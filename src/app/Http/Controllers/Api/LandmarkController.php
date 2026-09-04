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
            // Guidance points are the first-class visual navigation source. They are
            // intentionally independent from route geometry: routing/steps still come
            // from routing_edges_static / door_access_points, while this endpoint only
            // selects the best nearby image for the current route heading.
            $guidancePayload = $this->findGuidanceViewImage(
                (float) $lat,
                (float) $lng,
                (float) $heading,
                $floor,
                (float) $fov,
                (float) $maxDistance
            );

            if ($guidancePayload !== null) {
                $guidancePayload['language'] = $language;
                $guidancePayload['generatedAt'] = now()->toIso8601String();

                return response()->json($guidancePayload);
            }

            // Fallback keeps existing cultural/POI landmark behavior unchanged when no
            // guidance-point image is applicable.
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
    private function findGuidanceViewImage(
        float $lat,
        float $lng,
        float $heading,
        ?int $floor,
        float $requestFov,
        float $maxDistance
    ): ?array {
        $normalizedHeading = fmod($heading, 360.0);
        if ($normalizedHeading < 0) {
            $normalizedHeading += 360.0;
        }

        $pointSql = 'ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), 4326), 32640)';

        $query = DB::table('guidance_points as gp')
            ->join('guidance_point_images as gpi', 'gpi.point_id', '=', 'gp.id')
            ->where('gp.is_active', true)
            ->whereNull('gp.deleted_at')
            ->whereNotNull('gp.geom')
            ->whereRaw(
                "ST_DWithin(gp.geom, {$pointSql}, LEAST(?::double precision, COALESCE(gp.coverage_radius_m, 10)::double precision))",
                [$lng, $lat, $maxDistance]
            )
            ->select([
                'gp.id as guidance_point_id',
                'gp.floor',
                'gp.area_id',
                'gp.title',
                'gp.description',
                'gp.azimuth_deg as point_azimuth_deg',
                'gp.coverage_radius_m',
                'gp.sort_order as point_sort_order',
                'gpi.id as image_id',
                'gpi.image_url',
                'gpi.image_key',
                'gpi.sort_order as image_sort_order',
                'gpi.view_orientation',
                'gpi.azimuth_deg as image_azimuth_deg',
                'gpi.fov_deg',
                'gpi.caption',
                'gpi.attrs',
            ])
            ->selectRaw(
                "ST_Distance(gp.geom, {$pointSql}) AS distance_m, " .
                'ST_X(ST_Transform(gp.geom, 4326)) AS longitude, ' .
                'ST_Y(ST_Transform(gp.geom, 4326)) AS latitude',
                [$lng, $lat]
            );

        if ($floor !== null) {
            $query->where('gp.floor', $floor);
        }

        $candidates = $query
            ->orderBy('distance_m')
            ->orderBy('gp.sort_order')
            ->orderBy('gpi.sort_order')
            ->limit(100)
            ->get();

        $orientationAzimuths = [
            'north' => 0.0,
            'north_east' => 45.0,
            'east' => 90.0,
            'south_east' => 135.0,
            'south' => 180.0,
            'south_west' => 225.0,
            'west' => 270.0,
            'north_west' => 315.0,
        ];

        $best = null;
        $bestScore = null;

        foreach ($candidates as $candidate) {
            $orientation = $candidate->view_orientation ?: 'unknown';
            $imageAzimuth = $candidate->image_azimuth_deg !== null
                ? (float) $candidate->image_azimuth_deg
                : ($orientationAzimuths[$orientation] ?? null);

            if ($imageAzimuth === null && $candidate->point_azimuth_deg !== null) {
                $imageAzimuth = (float) $candidate->point_azimuth_deg;
            }

            if ($imageAzimuth === null) {
                continue;
            }

            $imageAzimuth = fmod($imageAzimuth, 360.0);
            if ($imageAzimuth < 0) {
                $imageAzimuth += 360.0;
            }

            $rawDiff = abs($imageAzimuth - $normalizedHeading);
            $angleDiff = min($rawDiff, 360.0 - $rawDiff);

            $imageFov = $candidate->fov_deg !== null ? (float) $candidate->fov_deg : 60.0;
            $allowedHalfAngle = max(0.5, min($imageFov, $requestFov) / 2.0);

            if ($angleDiff > $allowedHalfAngle) {
                continue;
            }

            $score = [
                (float) $candidate->distance_m,
                $angleDiff,
                (int) $candidate->point_sort_order,
                (int) $candidate->image_sort_order,
            ];

            $isBetter = $bestScore === null
                || $score[0] < $bestScore[0]
                || ($score[0] === $bestScore[0] && $score[1] < $bestScore[1])
                || ($score[0] === $bestScore[0] && $score[1] === $bestScore[1] && $score[2] < $bestScore[2])
                || ($score[0] === $bestScore[0] && $score[1] === $bestScore[1] && $score[2] === $bestScore[2] && $score[3] < $bestScore[3]);

            if ($isBetter) {
                $best = $candidate;
                $bestScore = $score;
                $best->resolved_azimuth_deg = $imageAzimuth;
                $best->angle_diff_deg = $angleDiff;
            }
        }

        if ($best === null) {
            return null;
        }

        $imageUrl = $best->image_key
            ? Storage::disk('public')->url($best->image_key)
            : $best->image_url;

        return [
            'status' => 'OK',
            'source' => 'guidance_points',
            'guidance_point_id' => (int) $best->guidance_point_id,
            'poi_id' => null,
            'floor' => (int) $best->floor,
            'area_id' => $best->area_id !== null ? (int) $best->area_id : null,
            'distance_m' => (float) $best->distance_m,
            'heading' => $normalizedHeading,
            'selected_orientation' => $best->view_orientation ?: 'unknown',
            'angle_diff_deg' => (float) $best->angle_diff_deg,
            'location' => [
                'lat' => (float) $best->latitude,
                'lng' => (float) $best->longitude,
            ],
            'content' => [
                'title' => $best->title,
                'description' => $best->description,
            ],
            'image' => [
                'id' => (int) $best->image_id,
                'url' => $imageUrl,
                'path' => $best->image_key,
                'orientation' => $best->view_orientation ?: 'unknown',
                'azimuth_deg' => (float) $best->resolved_azimuth_deg,
                'fov_deg' => $best->fov_deg !== null ? (float) $best->fov_deg : 60.0,
                'caption' => $best->caption,
                'attrs' => $best->attrs ? json_decode($best->attrs, true) : [],
            ],
        ];
    }

}
