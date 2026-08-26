<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RoutingController extends Controller
{
    public function route(Request $request)
    {
        $t0 = microtime(true);

        // برای اینکه حتی قبل از validate هم origin/destination.type را داشته باشیم
        $originType = data_get($request->all(), 'origin.type');
        $destType   = data_get($request->all(), 'destination.type');

        // پیش‌فرض‌های لاگ (در طول مسیر تکمیل می‌شوند)
        $log = [
            'mode'             => null,
            'gender'           => null,
            'floor'            => null,
            'origin_type'      => $originType,
            'destination_type' => $destType,
            'distance_m'       => null,
            'duration_s'       => null,
            'ok'               => false,
            'meta'             => [
                'status'     => 'INIT',
                'http_code'  => null,
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
                'user_id'    => optional($request->user())->id, // اگر احراز هویت داشتید
            ],
        ];

        try {
            // 1) اعتبارسنجی ورودی
            $data = $request->validate([
                'mode'   => 'required|string|in:walk,wheelchair,van',
                'gender' => 'required|string|in:male,female,both',
                'floor'  => 'nullable|integer',

                'lang'            => 'nullable|string',
                'maxAlternatives' => 'nullable|integer|min:0|max:3',

                'origin.type' => 'required|string|in:coordinate,poi,door,area,qrcode',
                'origin.id'   => 'nullable|integer',
                'origin.code' => 'nullable|string',
                'origin.lat'  => 'nullable|numeric',
                'origin.lon'  => 'nullable|numeric',

                'destination.type' => 'required|string|in:coordinate,poi,door,area,qrcode',
                'destination.id'   => 'nullable|integer',
                'destination.code' => 'nullable|string',
                'destination.lat'  => 'nullable|numeric',
                'destination.lon'  => 'nullable|numeric',
            ]);

            $mode   = $data['mode'];
            $gender = $data['gender'];
            $floor  = isset($data['floor']) ? (int)$data['floor'] : 0;

            $lang    = $data['lang'] ?? 'fa_enum';
            $maxAlts = $data['maxAlternatives'] ?? 2;

            $o = $data['origin'];
            $d = $data['destination'];

            // تکمیل لاگ با داده‌های معتبر
            $log['mode']   = $mode;
            $log['gender'] = $gender;
            $log['floor']  = $floor;
            $log['origin_type']      = $o['type'] ?? $log['origin_type'];
            $log['destination_type'] = $d['type'] ?? $log['destination_type'];

            $log['meta']['request'] = [
                'lang' => $lang,
                'maxAlternatives' => $maxAlts,
                'origin' => [
                    'type' => $o['type'] ?? null,
                    'id'   => $o['id'] ?? null,
                    'code' => $o['code'] ?? null,
                    'lat'  => $o['lat'] ?? null,
                    'lon'  => $o['lon'] ?? null,
                ],
                'destination' => [
                    'type' => $d['type'] ?? null,
                    'id'   => $d['id'] ?? null,
                    'code' => $d['code'] ?? null,
                    'lat'  => $d['lat'] ?? null,
                    'lon'  => $d['lon'] ?? null,
                ],
            ];

            // 2) محدودیت فعلی شما: فقط coordinate
            if (($o['type'] ?? null) !== 'coordinate' || ($d['type'] ?? null) !== 'coordinate') {
                $log['ok'] = false;
                $log['meta']['status']    = 'UNSUPPORTED';
                $log['meta']['http_code'] = 422;

                $this->insertRouteLogSafe($log, $t0);

                return response()->json([
                    'ok'      => false,
                    'message' => 'در نسخهٔ فعلی، مسیریابی جدید فقط برای origin/destination از نوع coordinate فعال است.',
                ], 422);
            }

            if (empty($o['lat']) || empty($o['lon']) || empty($d['lat']) || empty($d['lon'])) {
                $log['ok'] = false;
                $log['meta']['status']    = 'VALIDATION';
                $log['meta']['http_code'] = 422;
                $log['meta']['error']     = 'MISSING_LAT_LON';

                $this->insertRouteLogSafe($log, $t0);

                return response()->json([
                    'ok'      => false,
                    'message' => 'برای type=coordinate باید lat/lon مبدا و مقصد ارسال شود.',
                ], 422);
            }

            $originLat = (float)$o['lat'];
            $originLon = (float)$o['lon'];
            $destLat   = (float)$d['lat'];
            $destLon   = (float)$d['lon'];

            // 3) صدا زدن تابع fn_route_analyze_walk در Postgres
            try {
                $row = DB::selectOne("
                    SELECT fn_route_analyze_walk(
                        now()::timestamptz,
                        ?::gender_enum,
                        ?::text,
                        ?::smallint,
                        ST_Transform(
                            ST_SetSRID(ST_MakePoint(?::double precision, ?::double precision), 4326),
                            32640
                        ),
                        ST_Transform(
                            ST_SetSRID(ST_MakePoint(?::double precision, ?::double precision), 4326),
                            32640
                        ),
                        ?::lang_enum,
                        ?::int
                    ) AS route_json
                ", [
                    $gender,
                    $mode,
                    $floor,
                    $originLon,
                    $originLat,
                    $destLon,
                    $destLat,
                    $lang,
                    $maxAlts,
                ]);
            } catch (Throwable $e) {
                $log['ok'] = false;
                $log['meta']['status']    = 'DB_ERROR';
                $log['meta']['http_code'] = 500;
                $log['meta']['error']     = $e->getMessage();

                $this->insertRouteLogSafe($log, $t0);

                return response()->json([
                    'ok' => false,
                    'message' => 'خطا در اجرای عملیات پایگاه داده: ' . $e->getMessage(),
                ], 500);
            }

            if (!$row || $row->route_json === null) {
                $log['ok'] = false;
                $log['meta']['status']    = 'DB_INVALID_RESPONSE';
                $log['meta']['http_code'] = 500;

                $this->insertRouteLogSafe($log, $t0);

                return response()->json([
                    'ok'      => false,
                    'message' => 'fn_route_analyze_walk مقدار نامعتبری برگرداند.',
                ], 500);
            }

            $result = json_decode($row->route_json, true);

            // استخراج distance/duration اگر در خروجی موجود بود
            $distance = data_get($result, 'distanceMeters');   // بعضی خروجی‌ها distance می‌دهند

            $duration = data_get($result, 'estimatedMinutes');   // بعضی خروجی‌ها duration می‌دهند

            $log['distance_m'] = is_numeric($distance) ? (float)$distance : null;
            $log['duration_s'] = is_numeric($duration) ? (float)$duration : null;


            // 4) NO_PATH
            if (isset($result['status']) && $result['status'] === 'NO_PATH') {
                $log['ok'] = false;
                $log['meta']['status']    = 'NO_PATH';
                $log['meta']['http_code'] = 404;

                $this->insertRouteLogSafe($log, $t0);

                $result['ok'] = false;
                return response()->json($result, 404);
            }

            // 5) OK
            $log['ok'] = true;
            $log['meta']['status']    = 'OK';
            $log['meta']['http_code'] = 200;

            $this->insertRouteLogSafe($log, $t0);

            $result['ok']     = true;
            $result['mode']   = $mode;
            $result['gender'] = $gender;
            $result['floor']  = $floor;

            return response()->json($result);
        } catch (ValidationException $e) {
            // خطاهای validate()
            $log['ok'] = false;
            $log['meta']['status']    = 'VALIDATION';
            $log['meta']['http_code'] = 422;
            $log['meta']['errors']    = $e->errors();

            $this->insertRouteLogSafe($log, $t0);

            // بگذار خود Laravel پاسخ استانداردش را بدهد
            throw $e;
        } catch (Throwable $e) {
            // هر خطای غیرمنتظره
            $log['ok'] = false;
            $log['meta']['status']    = 'ERROR';
            $log['meta']['http_code'] = 500;
            $log['meta']['error']     = $e->getMessage();

            $this->insertRouteLogSafe($log, $t0);

            return response()->json([
                'ok' => false,
                'message' => 'خطای غیرمنتظره: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function insertRouteLogSafe(array $log, float $t0): void
    {
        try {
            // اگر مرورگر request رو abort کرده، لاگ نریز
            if (function_exists('connection_aborted') && connection_aborted()) {
                return;
            }

            $ms = (int) round((microtime(true) - $t0) * 1000);

            $meta = $log['meta'] ?? [];
            $meta['timing_ms'] = $ms;

            // fingerprint پایدار از درخواست
            $fingerprintPayload = [
                'mode'   => $log['mode'] ?? null,
                'gender' => $log['gender'] ?? null,
                'floor'  => $log['floor'] ?? null,
                'o'      => data_get($meta, 'request.origin'),
                'd'      => data_get($meta, 'request.destination'),
                'user_id' => data_get($meta, 'user_id'),
            ];
            $fingerprint = sha1(json_encode($fingerprintPayload, JSON_UNESCAPED_UNICODE));
            $meta['fingerprint'] = $fingerprint;

            // اگر در 2 ثانیه اخیر همین fingerprint ثبت شده، دوباره ثبت نکن
            $dup = DB::table('route_logs')
                ->whereRaw("meta->>'fingerprint' = ?", [$fingerprint])
                ->whereRaw("ts >= (now() - interval '2 seconds')")
                ->exists();

            if ($dup) return;

            DB::table('route_logs')->insert([
                'mode'             => $log['mode'] ?? 'unknown',
                'gender'           => $log['gender'] ?? 'both',
                'floor'            => $log['floor'],
                'origin_type'      => $log['origin_type'],
                'destination_type' => $log['destination_type'],
                'distance_m'       => $log['distance_m'],
                'duration_s'       => $log['duration_s'],
                'ok'               => (bool)($log['ok'] ?? false),
                'meta'             => json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            // لاگ‌گیری نباید مسیر‌یابی را خراب کند
        }
    }
}
