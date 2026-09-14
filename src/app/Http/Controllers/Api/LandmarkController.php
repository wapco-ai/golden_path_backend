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
     *  - source : auto (default) | guidance_points (requires floor; no POI fallback)
     */
    public function viewImage(Request $request)
    {
        $data = $request->validate([
            'language'        => 'nullable|string|in:fa,en,ar,ur',
            'geo.lat'         => 'required|numeric|between:-90,90',
            'geo.lng'         => 'required|numeric|between:-180,180',
            'heading'         => 'required|numeric',
            'floor'           => 'required_if:source,guidance_points|nullable|integer|min:-32768|max:32767',
            'source'          => 'sometimes|string|in:auto,guidance_points',
            'fov'             => 'nullable|numeric|min:1|max:180',
            'max_distance'    => 'nullable|numeric|min:1|max:1000',
        ]);

        $language     = $data['language'] ?? 'fa';
        $lat          = data_get($data, 'geo.lat');
        $lng          = data_get($data, 'geo.lng');
        $heading      = $data['heading'];

        $floor        = isset($data['floor']) ? (int) $data['floor'] : null;
        $imageSource  = $data['source'] ?? 'auto';
        $fov          = $data['fov'] ?? 90;
        $maxDistance  = $data['max_distance'] ?? 80;

        try {
            // Guidance points are visual navigation aids only. Route geometry and
            // route steps remain sourced from routing_edges_static / door_access_points.
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

                return response()->json($guidancePayload)->header('Cache-Control', 'no-store');
            }

            // RNG opts in explicitly. No POI fallback when no guidance point in the
            // requested floor/distance/FOV has a usable directional image.
            if ($imageSource === 'guidance_points') {
                return response()->json([
                    'status' => 'NO_MATCH',
                    'source' => 'guidance_points',
                    'guidance_point_id' => null,
                    'poi_id' => null,
                    'image' => null,
                    'floor' => $floor,
                    'heading' => $this->normalizeAzimuth((float) $heading),
                    'reason' => 'NO_GUIDANCE_IMAGE_MATCH',
                    'language' => $language,
                    'generatedAt' => now()->toIso8601String(),
                ])->header('Cache-Control', 'no-store');
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
            $payload['generatedAt'] = $payload['generatedAt'] ?? now()->toIso8601String();

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
        $normalizedHeading = $this->normalizeAzimuth($heading);
        $localNorthOffset = (float) config('guidance.local_north_offset_deg', 30.0);
        $pointSql = 'ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), 4326), 32640)';

        // Stage 1: select guidance POINTS only. Request max_distance, per-point
        // coverage radius, floor and request FOV decide which points are eligible.
        // Image orientation and local-north correction deliberately do not participate.
        $query = DB::table('guidance_points as gp')
            ->where('gp.is_active', true)
            ->whereNull('gp.deleted_at')
            ->whereNotNull('gp.geom')
            ->whereRaw(
                "ST_DWithin(gp.geom, {$pointSql}, LEAST(?::double precision, COALESCE(gp.coverage_radius_m, 100)::double precision))",
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

        $points = $query
            ->orderBy('distance_m')
            ->orderBy('gp.sort_order')
            ->limit(100)
            ->get();

        $visiblePoints = [];
        $halfRequestFov = $requestFov / 2.0;

        foreach ($points as $point) {
            $pointLat = (float) $point->latitude;
            $pointLng = (float) $point->longitude;
            $bearingToPoint = $this->bearingDegrees($lat, $lng, $pointLat, $pointLng);

            // If user and point are effectively coincident, keep the point eligible;
            // an azimuth is undefined at zero distance but it is certainly not "behind".
            $pointAngleDiff = $bearingToPoint === null
                ? 0.0
                : $this->circularAngleDiff($bearingToPoint, $normalizedHeading);

            if ($pointAngleDiff > $halfRequestFov) {
                continue;
            }

            $point->bearing_to_guidance_deg = $bearingToPoint;
            $point->point_angle_diff_deg = $pointAngleDiff;
            $visiblePoints[] = $point;
        }

        if (!$visiblePoints) {
            return null;
        }

        usort($visiblePoints, static function ($a, $b): int {
            $distanceCompare = ((float) $a->distance_m) <=> ((float) $b->distance_m);
            if ($distanceCompare !== 0) {
                return $distanceCompare;
            }

            $angleCompare = ((float) $a->point_angle_diff_deg) <=> ((float) $b->point_angle_diff_deg);
            if ($angleCompare !== 0) {
                return $angleCompare;
            }

            return ((int) $a->point_sort_order) <=> ((int) $b->point_sort_order);
        });

        // Load images for all visible candidates once. Iterating points in the ranked
        // order below naturally switches to the next point when the nearer one has no
        // image (or no image with usable direction metadata).
        $pointIds = array_map(static fn ($point) => (int) $point->guidance_point_id, $visiblePoints);
        $imageRows = DB::table('guidance_point_images as gpi')
            ->whereIn('gpi.point_id', $pointIds)
            ->orderBy('gpi.point_id')
            ->orderBy('gpi.sort_order')
            ->get([
                'gpi.id as image_id',
                'gpi.point_id',
                'gpi.image_url',
                'gpi.image_key',
                'gpi.sort_order as image_sort_order',
                'gpi.view_orientation',
                'gpi.azimuth_deg as image_azimuth_deg',
                'gpi.fov_deg',
                'gpi.caption',
                'gpi.attrs',
            ]);

        $imagesByPoint = collect($imageRows)->groupBy(static fn ($row) => (int) $row->point_id);
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

        // Stage 2: after a point has passed floor/distance/FOV selection, choose the
        // image from the side where the user actually is relative to that point.
        // Only this stage uses the shrine-wide local-north offset.
        foreach ($visiblePoints as $point) {
            $images = $imagesByPoint->get((int) $point->guidance_point_id, collect());
            if ($images->isEmpty()) {
                continue;
            }

            $pointLat = (float) $point->latitude;
            $pointLng = (float) $point->longitude;
            $bearingPointToUser = $this->bearingDegrees($pointLat, $pointLng, $lat, $lng);

            // At the exact point, use the reverse movement direction as a stable
            // approximation of the approach side.
            if ($bearingPointToUser === null) {
                $bearingPointToUser = $this->normalizeAzimuth($normalizedHeading + 180.0);
            }

            $localViewBearing = $this->normalizeAzimuth($bearingPointToUser - $localNorthOffset);
            $bestImage = null;
            $bestImageDiff = null;

            foreach ($images as $image) {
                $orientation = $image->view_orientation ?: 'unknown';
                $imageAzimuth = $image->image_azimuth_deg !== null
                    ? (float) $image->image_azimuth_deg
                    : ($orientationAzimuths[$orientation] ?? null);

                if ($imageAzimuth === null && $point->point_azimuth_deg !== null) {
                    $imageAzimuth = (float) $point->point_azimuth_deg;
                }

                if ($imageAzimuth === null) {
                    continue;
                }

                $imageAzimuth = $this->normalizeAzimuth($imageAzimuth);
                $imageDiff = $this->circularAngleDiff($imageAzimuth, $localViewBearing);

                if (
                    $bestImage === null
                    || $imageDiff < $bestImageDiff
                    || ($imageDiff === $bestImageDiff && (int) $image->image_sort_order < (int) $bestImage->image_sort_order)
                ) {
                    $bestImage = $image;
                    $bestImageDiff = $imageDiff;
                    $bestImage->resolved_azimuth_deg = $imageAzimuth;
                }
            }

            if ($bestImage === null) {
                continue;
            }

            $imageFov = $bestImage->fov_deg !== null ? (float) $bestImage->fov_deg : 60.0;
            $imageMatched = $bestImageDiff <= ($imageFov / 2.0);
            $imageUrl = $bestImage->image_key
                ? Storage::disk('public')->url($bestImage->image_key)
                : $bestImage->image_url;

            return [
                'status' => 'OK',
                'source' => 'guidance_points',
                'guidance_point_id' => (int) $point->guidance_point_id,
                'poi_id' => null,
                'floor' => (int) $point->floor,
                'area_id' => $point->area_id !== null ? (int) $point->area_id : null,
                'distance_m' => (float) $point->distance_m,
                'heading' => $normalizedHeading,
                'request_fov_deg' => $requestFov,
                'bearing_to_guidance_deg' => $point->bearing_to_guidance_deg !== null
                    ? (float) $point->bearing_to_guidance_deg
                    : null,
                'point_angle_diff_deg' => (float) $point->point_angle_diff_deg,
                'bearing_guidance_to_user_deg' => $bearingPointToUser,
                'local_north_offset_deg' => $localNorthOffset,
                'local_view_bearing_deg' => $localViewBearing,
                'selected_orientation' => $bestImage->view_orientation ?: 'unknown',
                // Keep the legacy key for clients/logs, but it now explicitly means
                // the image-vs-local-view difference, not point FOV eligibility.
                'angle_diff_deg' => (float) $bestImageDiff,
                'image_angle_diff_deg' => (float) $bestImageDiff,
                'imageMatched' => $imageMatched,
                'location' => [
                    'lat' => $pointLat,
                    'lng' => $pointLng,
                ],
                'content' => [
                    'title' => $point->title,
                    'description' => $point->description,
                ],
                'image' => [
                    'id' => (int) $bestImage->image_id,
                    'url' => $imageUrl,
                    'path' => $bestImage->image_key,
                    'orientation' => $bestImage->view_orientation ?: 'unknown',
                    'azimuth_deg' => (float) $bestImage->resolved_azimuth_deg,
                    'fov_deg' => $imageFov,
                    'caption' => $bestImage->caption,
                    'attrs' => $bestImage->attrs ? json_decode($bestImage->attrs, true) : [],
                ],
            ];
        }

        return null;
    }

    private function normalizeAzimuth(float $degrees): float
    {
        return fmod(fmod($degrees, 360.0) + 360.0, 360.0);
    }

    private function circularAngleDiff(float $a, float $b): float
    {
        $diff = abs($this->normalizeAzimuth($a) - $this->normalizeAzimuth($b));
        return min($diff, 360.0 - $diff);
    }

    private function bearingDegrees(float $fromLat, float $fromLng, float $toLat, float $toLng): ?float
    {
        if (abs($fromLat - $toLat) < 1e-12 && abs($fromLng - $toLng) < 1e-12) {
            return null;
        }

        $lat1 = deg2rad($fromLat);
        $lat2 = deg2rad($toLat);
        $dLng = deg2rad($toLng - $fromLng);
        $y = sin($dLng) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLng);

        return $this->normalizeAzimuth(rad2deg(atan2($y, $x)));
    }
}
