<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DoorRouteEndpointService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RoutingController extends Controller
{
    public function route(Request $request, DoorRouteEndpointService $doorEndpoints)
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
                'user_id'    => optional($request->user())->id,
            ],
        ];

        try {
            // 1) اعتبارسنجی ورودی
            $data = $request->validate([
                'mode'   => 'required|string|in:walk,wheelchair,van',
                'gender' => 'required|string|in:male,female,both',
                'floor'  => 'nullable|integer',

                'lang'            => 'nullable|in:fa,en,ar,ur',
                'maxAlternatives' => 'nullable|integer|min:0|max:3',
                'allowNearbyDestination' => 'sometimes|boolean',

                'origin.type'  => 'required|string|in:coordinate,poi,door,area,qrcode',
                'origin.id'    => 'nullable|integer',
                'origin.code'  => 'nullable|string',
                'origin.name'  => 'nullable|string|max:255',
                'origin.lat'   => 'nullable|numeric',
                'origin.lon'   => 'nullable|numeric',
                'origin.floor' => 'nullable|integer',
                'origin.accessId' => 'required_if:origin.type,door|integer|min:1',
                'origin.areaId' => 'required_if:origin.type,door|integer|min:1',

                'destination.type'  => 'required|string|in:coordinate,poi,door,area,qrcode',
                'destination.id'    => 'nullable|integer',
                'destination.code'  => 'nullable|string',
                'destination.name'  => 'nullable|string|max:255',
                'destination.lat'   => 'nullable|numeric',
                'destination.lon'   => 'nullable|numeric',
                'destination.floor' => 'nullable|integer',
                'destination.accessId' => 'required_if:destination.type,door|integer|min:1',
                'destination.areaId' => 'required_if:destination.type,door|integer|min:1',
            ]);

            $mode   = $data['mode'];
            $gender = $data['gender'];
            $floor  = isset($data['floor']) ? (int)$data['floor'] : 0;

            $lang    = $data['lang'] ?? 'fa';
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
                    'type'  => $o['type'] ?? null,
                    'id'    => $o['id'] ?? null,
                    'accessId' => $o['accessId'] ?? null,
                    'areaId' => $o['areaId'] ?? null,
                    'code'  => $o['code'] ?? null,
                    'name'  => $o['name'] ?? null,
                    'lat'   => $o['lat'] ?? null,
                    'lon'   => $o['lon'] ?? null,
                    'floor' => isset($o['floor']) ? (int)$o['floor'] : $floor,
                ],
                'destination' => [
                    'type'  => $d['type'] ?? null,
                    'id'    => $d['id'] ?? null,
                    'accessId' => $d['accessId'] ?? null,
                    'areaId' => $d['areaId'] ?? null,
                    'code'  => $d['code'] ?? null,
                    'name'  => $d['name'] ?? null,
                    'lat'   => $d['lat'] ?? null,
                    'lon'   => $d['lon'] ?? null,
                    'floor' => isset($d['floor']) ? (int)$d['floor'] : $floor,
                ],
            ];

            $points = ['origin' => $o, 'destination' => $d];
            foreach ($points as $side => $point) {
                if (!in_array($point['type'], ['coordinate', 'door'], true)) {
                    $log['meta']['status'] = 'UNSUPPORTED';
                    $log['meta']['http_code'] = 422;
                    $this->insertRouteLogSafe($log, $t0);
                    return response()->json([
                        'ok' => false,
                        'message' => 'مبدأ و مقصد باید مختصات یا درب انتخاب‌شده باشند.',
                    ], 422);
                }
                if ($point['type'] === 'coordinate' && (empty($point['lat']) || empty($point['lon']))) {
                    $log['meta']['status'] = 'VALIDATION';
                    $log['meta']['http_code'] = 422;
                    $log['meta']['error'] = 'MISSING_LAT_LON';
                    $this->insertRouteLogSafe($log, $t0);
                    return response()->json([
                        'ok' => false,
                        'message' => 'برای type=coordinate باید lat/lon مبدا و مقصد ارسال شود.',
                    ], 422);
                }
                if ($point['type'] === 'door' && !isset($point['floor'])) {
                    throw ValidationException::withMessages([$side => 'طبقهٔ درب باید مشخص باشد.']);
                }
            }

            $originFloor = (int) ($o['floor'] ?? $floor);
            $destinationFloor = (int) ($d['floor'] ?? $floor);
            $knownFloors = DB::table('routing_floors')->whereIn('floor', [$originFloor, $destinationFloor])->pluck('floor')->map(fn ($f) => (int) $f)->all();
            if (!in_array($originFloor, $knownFloors, true) || !in_array($destinationFloor, $knownFloors, true)) {
                throw ValidationException::withMessages(['floor' => 'طبقهٔ مبدأ یا مقصد معتبر نیست.']);
            }
            $log['meta']['origin_floor'] = $originFloor;
            $log['meta']['destination_floor'] = $destinationFloor;
            $resolved = [];
            foreach ($points as $side => $point) {
                if ($point['type'] === 'coordinate') {
                    $resolved[$side] = ['lat' => (float) $point['lat'], 'lon' => (float) $point['lon']];
                    continue;
                }
                $endpoint = $doorEndpoints->resolve($point, $side === 'origin' ? $originFloor : $destinationFloor, $mode, $gender, $lang);
                if (isset($endpoint['reason'])) {
                    $log['meta']['status'] = 'DOOR_UNAVAILABLE';
                    $log['meta']['http_code'] = 409;
                    $this->insertRouteLogSafe($log, $t0);
                    return response()->json(['ok' => false, 'status' => 'DOOR_UNAVAILABLE', 'endpoint' => $side, ...$endpoint], 409);
                }
                $resolved[$side] = $endpoint;
            }

            ['lat' => $originLat, 'lon' => $originLon] = $resolved['origin'];
            ['lat' => $destLat, 'lon' => $destLon] = $resolved['destination'];
            $result = app(\App\Services\MultiFloorRoutingService::class)->route(
                $gender, $mode, $originFloor, $destinationFloor,
                $resolved['origin'], $resolved['destination'],
                $lang, (int) $maxAlts, $request->boolean('allowNearbyDestination'), $d['name'] ?? null
            );

            // استخراج distance/duration اگر در خروجی موجود بود
            $distance = data_get($result, 'distanceMeters');
            $duration = data_get($result, 'estimatedMinutes');

            $log['distance_m'] = is_numeric($distance) ? (float)$distance : null;
            $log['duration_s'] = is_numeric($duration) ? (float)$duration * 60 : null;

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
            $result['ok']     = true;
            $result['mode']   = $mode;
            $result['gender'] = $gender;
            $result['floor']  = $floor;

            $log['ok'] = true;
            $log['meta']['status']    = 'OK';
            $log['meta']['http_code'] = 200;

            // تاریخچه فقط برای کاربر احراز هویت‌شده ذخیره می‌شود و همان خروجی موتور
            // مسیریابی را نگه می‌دارد؛ هیچ هندسه‌ای در این بخش بازسازی نمی‌شود.
            if ($request->user()) {
                $log['meta']['history'] = [
                    'origin' => [
                        'name' => $o['name'] ?? null,
                        'coordinates' => [$originLat, $originLon],
                        'floor' => $originFloor,
                        ...($o['type'] === 'door' ? ['door' => ['accessId' => (int) $o['accessId'], 'selectedAreaId' => (int) $o['areaId']]] : []),
                    ],
                    'destination' => [
                        'name' => $d['name'] ?? null,
                        'coordinates' => [$destLat, $destLon],
                        'floor' => $destinationFloor,
                        ...($d['type'] === 'door' ? ['door' => ['accessId' => (int) $d['accessId'], 'selectedAreaId' => (int) $d['areaId']]] : []),
                    ],
                    'capture_version' => 1,
                ];
                $log['meta']['route_snapshot'] = $result;
            }

            $this->insertRouteLogSafe($log, $t0);

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

            // fingerprint پایدار از پارامترهایی که روی خروجی مسیر اثر می‌گذارند
            $fingerprintPayload = [
                'mode'   => $log['mode'] ?? null,
                'gender' => $log['gender'] ?? null,
                'floor'  => $log['floor'] ?? null,
                'lang'   => data_get($meta, 'request.lang'),
                'maxAlternatives' => data_get($meta, 'request.maxAlternatives'),
                'origin_floor' => data_get($meta, 'request.origin.floor'),
                'destination_floor' => data_get($meta, 'request.destination.floor'),
                'o' => [
                    'type' => data_get($meta, 'request.origin.type'),
                    'id'   => data_get($meta, 'request.origin.id'),
                    'code' => data_get($meta, 'request.origin.code'),
                    'accessId' => data_get($meta, 'request.origin.accessId'),
                    'areaId' => data_get($meta, 'request.origin.areaId'),
                    'lat'  => data_get($meta, 'request.origin.lat'),
                    'lon'  => data_get($meta, 'request.origin.lon'),
                ],
                'd' => [
                    'type' => data_get($meta, 'request.destination.type'),
                    'id'   => data_get($meta, 'request.destination.id'),
                    'code' => data_get($meta, 'request.destination.code'),
                    'accessId' => data_get($meta, 'request.destination.accessId'),
                    'areaId' => data_get($meta, 'request.destination.areaId'),
                    'lat'  => data_get($meta, 'request.destination.lat'),
                    'lon'  => data_get($meta, 'request.destination.lon'),
                ],
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
