<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AreaEndpointService;
use App\Services\RouteEndpointLabelService;
use App\Services\DoorRouteEndpointService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AreaDoorsController extends Controller
{
    public function endpointLabel(Request $request, RouteEndpointLabelService $labels)
    {
        $point = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'floor' => 'required|integer|min:-32768|max:32767',
            'lang' => 'nullable|string|in:fa,en,ar,ur',
        ]);

        return response()->json([
            'label' => $labels->find($point['lat'], $point['lon'], $point['floor'], $point['lang'] ?? 'fa'),
        ])->header('Cache-Control', 'no-store');
    }

    public function byPoint(Request $request, AreaEndpointService $areaEndpoints, DoorRouteEndpointService $doorEndpoints)
    {
        // 1) اعتبارسنجی ورودی
        $data = $request->validate([
            'target' => 'nullable|in:origin,destination',
            'origin' => 'nullable|array:lat,lon,floor,accessId,areaId',
            'origin.floor' => 'required_with:origin|integer|between:-32768,32767',
            'origin.lat' => 'required_with:origin|numeric|between:-90,90',
            'origin.lon' => 'required_with:origin|numeric|between:-180,180',
            'origin.accessId' => 'required_with:origin.areaId|integer|min:1',
            'origin.areaId' => 'required_with:origin.accessId|integer|min:1',
            'area_id' => 'nullable|integer|min:1|prohibits:area_group_id',
            'area_group_id' => 'nullable|string|max:100',
            'lat'     => 'required_without_all:area_id,area_group_id|nullable|numeric',
            'lon'     => 'required_without_all:area_id,area_group_id|nullable|numeric',
            'floor'   => 'required_without_all:area_id,area_group_id|nullable|integer|min:-32768|max:32767',
            'mode'    => 'nullable|in:walk,wheelchair,van',
            'gender'  => 'nullable|in:male,female,both',
            'lang'    => 'nullable|string|in:fa,en,ar,ur',
        ]);

        $lang = $data['lang'] ?? 'fa';

        $destination = ($data['target'] ?? null) === 'destination';
        $mode = $data['mode'] ?? 'walk';
        $gender = $data['gender'] ?? 'both';
        $areaId = $data['area_id'] ?? null;
        $areaGroupId = $data['area_group_id'] ?? null;
        $rawPayload = $areaId !== null || $areaGroupId !== null
            ? $areaEndpoints->doors($areaId, $lang, $data['floor'] ?? null, $areaGroupId, $destination)
            : DB::selectOne("
            SELECT fn_area_doors_clockwise_json(
                ST_Transform(
                    ST_SetSRID(ST_MakePoint(?, ?), 4326),
                    32640
                ),
                ?::smallint,
                ?::lang_enum
            ) AS payload
        ", [
            $data['lon'],   // ST_MakePoint(lon, lat)
            $data['lat'],
            $data['floor'],
            $lang,
        ])?->payload;

        // اگر هیچ محدوده‌ای پیدا نشده یا درب ندارد
        $payload = is_string($rawPayload) ? json_decode($rawPayload, true) : $rawPayload;
        if ($rawPayload === null || (is_array($payload) && array_key_exists('area', $payload) && $payload['area'] === null)) {
            return response()->json([
                'status'  => 'not_found',
                'message' => 'هیچ محدوده‌ای برای این مختصات یافت نشد یا دربی متصل ندارد.',
            ], 404);
        }

        // اگر ساختار مورد انتظار نبود، یک خطای عمومی می‌دهیم
        if (!is_array($payload) || !isset($payload['area'])) {
            return response()->json([
                'status'  => 'error',
                'message' => 'ساختار داده دریافتی از پایگاه داده نامعتبر است.',
            ], 500);
        }

        // 4) چک کردن مساحت محدوده
        $area = $payload['area'] ?? null;
        $areaM2 = null;

        if (is_array($area) && isset($area['areaM2'])) {
            $areaM2 = floatval($area['areaM2']);
        }

        $ids = array_map('intval', $area['memberAreaIds'] ?? [$area['id']]);
        $floor = (int) $area['floor'];
        $denied = $destination ? $doorEndpoints->deniedAreas($ids, $floor, $mode, $gender) : [];
        if (!$denied && $areaM2 !== null && $areaM2 < AreaEndpointService::MIN_PICKER_AREA_M2) {
            return response()->json([
                'status'  => 'area_too_small',
                'message' => 'مساحت محدودهٔ انتخابی کمتر از ۱۰۰ متر مربع است و برای این سرویس در نظر گرفته نمی‌شود.',
                'data'    => [
                    'area' => $area,  // برای اینکه فرانت بتواند اگر خواست، خود محدوده را نمایش دهد
                ],
            ], 400);
        }

        if ($destination) $payload['restrictedArea'] = (bool) $denied;

        if (!empty($payload['doors']) && ($destination || isset($data['mode']) || isset($data['gender']))) {
            $reachable = [];
            if ($denied && !empty($data['origin'])) {
                $origin = $data['origin'];
                $start = isset($origin['accessId'])
                    ? $doorEndpoints->resolve($origin, (int) $origin['floor'], $mode, $gender, $lang)
                    : $origin;
                if (!isset($start['reason'])) {
                    $reachable = app(\App\Services\MultiFloorRoutingService::class)->reachableEntrances(
                        $gender, $mode, (int) $origin['floor'], $floor, $start, $ids);
                }
            }
            $payload['requiresOrigin'] = $denied && empty($data['origin']);
            $permitted = array_values(array_diff($ids, $denied));
            $statuses = collect($permitted ? $doorEndpoints->forAreas($permitted, $floor, $mode, $gender, $lang) : [])
                ->keyBy(fn ($row) => $row->access_id.':'.$row->area_id);
            foreach ($payload['doors'] as &$door) {
                $fromArea = (int) ($door['fromAreaId'] ?? 0);
                $toArea = (int) ($door['toAreaId'] ?? 0);
                $selectedArea = in_array($fromArea, $ids, true) ? $fromArea : $toArea;
                $status = $statuses->get($door['accessId'].':'.$selectedArea);
                $door['selectedAreaId'] = $selectedArea;
                $door['routingStatus'] = $status?->status ?? 'unavailable';
                $door['routingName'] = $status?->name;
                if (in_array($selectedArea, $denied, true)) {
                    $door['approachOnly'] = true;
                    $door['routingStatus'] = in_array((int) $door['accessId'], $reachable, true) ? 'available' : 'unavailable';
                }
            }
            unset($door);
        }

        // 5) خروجی نهایی OK
        return response()->json([
            'status' => 'ok',
            'data'   => $payload,
        ]);
    }
}
