<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;

class MapGeojsonController extends Controller
{
    /**
     * GET /api/v1/maps/geojson?language=fa&floor=0
     */
    public function index(Request $request): JsonResponse
    {
        // 1) زبان
        $lang = $request->query('language', 'fa');
        $lang = strtolower($lang);
        $allowedLangs = ['fa', 'en', 'ar', 'ur'];
        if (! in_array($lang, $allowedLangs, true)) {
            $lang = 'fa';
        }

        // 2) طبقه
        // ورودی مثلاً "0" یا "-1"
        $floorParam = $request->query('floor', '0');

        // تبدیل به عدد صحیح
        $floor = (int) $floorParam;

        // فقط طبقات مجاز: 0 و -1 (اگر طبقه‌های دیگری داری اینجا اضافه کن)
        $allowedFloors = [0, -1];
        if (! in_array($floor, $allowedFloors, true)) {
            // اگر مقدار نامعتبر بود، می‌تونی یا خطا بدهی یا دیفالت کنی
            // من فعلاً دیفالت کردم به 0
            $floor = 0;
        }

        try {
            // 3) صدا زدن فانکشن جدید: fn_map_geojson_floor(p_lang, p_floor)
            $rows = DB::select(
                "SELECT fn_map_geojson(?::lang_enum, ?::smallint) AS geojson",
                [$lang, $floor]
            );

            if (empty($rows)) {
                return response()->json([
                    'type' => 'FeatureCollection',
                    'features' => [],
                ]);
            }

            $geojsonRaw = $rows[0]->geojson ?? null;

            // اگر به شکل string برگشت، decode می‌کنیم
            if (is_string($geojsonRaw)) {
                $geojson = json_decode($geojsonRaw, true);
            } else {
                $geojson = $geojsonRaw;
            }

            if (! is_array($geojson) || ($geojson['type'] ?? null) !== 'FeatureCollection') {
                $geojson = [
                    'type' => 'FeatureCollection',
                    'features' => [],
                ];
            }

            return response()->json($geojson);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'type' => 'FeatureCollection',
                'features' => [],
                'error' => 'map_geojson_failed',
                'message' => config('app.debug') ? $e->getMessage() : 'Map service error',
            ], 500);
        }
    }
}
