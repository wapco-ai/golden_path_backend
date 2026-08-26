<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AreaDoorsController extends Controller
{
    public function byPoint(Request $request)
    {
        // 1) اعتبارسنجی ورودی
        $data = $request->validate([
            'lat'   => 'required|numeric',
            'lon'   => 'required|numeric',
            'floor' => 'required|integer',
            'lang'  => 'nullable|string',
        ]);

        $lang = $data['lang'] ?? 'fa';

        // 2) صدا زدن فانکشن Postgres
        $row = DB::selectOne("
            SELECT fn_area_doors_clockwise_json(
                ST_Transform(
                    ST_SetSRID(ST_MakePoint(?, ?), 4326),
                    32640
                ),
                ?,
                ?::lang_enum
            ) AS payload
        ", [
            $data['lon'],   // ST_MakePoint(lon, lat)
            $data['lat'],
            $data['floor'],
            $lang,
        ]);

        // اگر هیچ محدوده‌ای پیدا نشده یا درب ندارد
        if (!$row || $row->payload === null) {
            return response()->json([
                'status'  => 'not_found',
                'message' => 'هیچ محدوده‌ای برای این مختصات یافت نشد یا دربی متصل ندارد.',
            ], 404);
        }

        // 3) نوع payload (ممکن است string یا آرایه/آبجکت باشد)
        $payload = $row->payload;

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
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

        if ($areaM2 !== null && $areaM2 < 100.0) {
            return response()->json([
                'status'  => 'area_too_small',
                'message' => 'مساحت محدودهٔ انتخابی کمتر از ۱۰۰ متر مربع است و برای این سرویس در نظر گرفته نمی‌شود.',
                'data'    => [
                    'area' => $area,  // برای اینکه فرانت بتواند اگر خواست، خود محدوده را نمایش دهد
                ],
            ], 400);
        }

        // 5) خروجی نهایی OK
        return response()->json([
            'status' => 'ok',
            'data'   => $payload,
        ]);
    }
}
