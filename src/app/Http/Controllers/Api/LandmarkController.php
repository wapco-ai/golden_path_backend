<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LandmarkController extends Controller
{
    /**
     * GET /api/v1/landmark-places
     *
     * Query params:
     *  - language: fa | en | ar | ur (optional, default fa)
     *  - limit: int (optional, default 2000)
     *  - geo[lat], geo[lng]: float (optional)
     *  - poi_id: int (optional)    // برای Landmarkهای جدول poi_points
     *  - area_id: int (optional)   // برای Landmarkهای جدول areas
     */
    public function landmarkPlaces(Request $request)
    {
        $data = $request->validate([
            'language'   => 'nullable|string|in:fa,en,ar,ur',
            'limit'      => 'nullable|integer|min:1|max:200', // (پیشنهاد: تناقض 2000/200 رفع شد)
            'geo.lat'    => 'nullable|numeric',
            'geo.lng'    => 'nullable|numeric',
            'poi_id'     => 'nullable|integer',
            'search'     => 'nullable|string|max:100',
            'featured' => 'nullable|boolean',
        ]);

        $language = $data['language'] ?? 'fa';
        $limit    = $data['limit'] ?? 100;

        $lat    = data_get($data, 'geo.lat');
        $lng    = data_get($data, 'geo.lng');
        $poiId  = $data['poi_id'] ?? null;
        $search = $data['search'] ?? null;
        $featured = (bool)($data['featured'] ?? false);


        try {
            $row = DB::selectOne(
                // 👇 امضای جدید فانکشن: ۶ پارامتر
                'SELECT public.fn_landmark_places_json(?::lang_enum, ?, ?, ?, ?, ?, ?) AS data',
                [$language, $limit, $lat, $lng, $poiId, $search, $featured]
            );

            $payload = $row->data ?? null;

            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }

            if (!is_array($payload)) {
                $payload = [
                    'places' => [
                        'landmarkPlaces' => [],
                    ],
                    'language'    => $language,
                    'generatedAt' => now()->toIso8601String(),
                ];
            }

            // تضمین language / generatedAt
            $payload['language']    = $payload['language']    ?? $language;
            $payload['generatedAt'] = $payload['generatedAt'] ?? now()->toIso8601String();

            return response()->json($payload);
        } catch (\Throwable $e) {
            \Log::error('LandmarkController@landmarkPlaces error', [
                'error' => $e->getMessage(),
                'input' => $request->all(),
            ]);

            return response()->json([
                'error'   => 'SERVER_ERROR',
                'message' => 'مشکلی در تولید داده‌های Landmark رخ داد.',
            ], 500);
        }
    }

    /**
     * GET /api/v1/landmark-view-image
     *
     * Query params:
     *  - language: fa|en|ar|ur (default fa)
     *  - geo[lat], geo[lng] : float (required)
     *  - heading : float (required) 0..360 (north=0)
     *  - floor : smallint (optional)
     *  - fov : float (optional, default 90)
     *  - max_distance : float (optional, default 80 meters)
     */
    public function viewImage(Request $request)
    {
        $data = $request->validate([
            'language'        => 'nullable|string|in:fa,en,ar,ur',
            'geo.lat'         => 'required|numeric',
            'geo.lng'         => 'required|numeric',
            'heading'         => 'required|numeric',
            'floor'           => 'nullable|integer',
            'fov'             => 'nullable|numeric|min:1|max:180',
            'max_distance'    => 'nullable|numeric|min:1|max:1000',
        ]);

        $language     = $data['language'] ?? 'fa';
        $lat          = data_get($data, 'geo.lat');
        $lng          = data_get($data, 'geo.lng');
        $heading      = $data['heading'];

        $floor        = array_key_exists('floor', $data) ? (int)$data['floor'] : null;
        $fov          = $data['fov'] ?? 90;
        $maxDistance  = $data['max_distance'] ?? 80;

        try {
            $row = DB::selectOne(
                'SELECT public.fn_landmark_view_image(?::lang_enum, ?, ?, ?, ?::smallint, ?, ?) AS data',
                [$language, $lat, $lng, $heading, $floor, $fov, $maxDistance]
            );

            $payload = $row->data ?? null;
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }

            if (!is_array($payload)) {
                $payload = [
                    'status'  => 'SERVER_ERROR',
                    'message' => 'خروجی نامعتبر از fn_landmark_view_image',
                ];
            }

            $payload['language']    = $payload['language']    ?? $language;
            $payload['generatedAt'] = now()->toIso8601String();

            // ✅ Fix image.url based on current environment (APP_URL/filesystems config)
            if (is_array($payload) && isset($payload['image']) && is_array($payload['image'])) {
                $path = $payload['image']['path'] ?? null;

                // اگر path داریم، URL را همیشه از روی دیسک public دوباره بساز
                if ($path) {
                    $payload['image']['url'] = Storage::disk('public')->url($path);
                } else {
                    // اگر path نداریم ولی url هست و localhost است، حداقل replace نکنیم (چون ممکن است CDN باشد)
                    // (اختیاری) می‌توانی اینجا replace هم انجام بدهی
                }
            }

            return response()->json($payload);
        } catch (\Throwable $e) {
            \Log::error('LandmarkController@viewImage error', [
                'error' => $e->getMessage(),
                'input' => $request->all(),
            ]);

            return response()->json([
                'status'  => 'SERVER_ERROR',
                'message' => 'مشکلی در انتخاب تصویر لندمارک رخ داد.',
            ], 500);
        }
    }
}
