<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GuidancePointsPublicController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->query('limit', 50), 100));

        $q = DB::table('guidance_points as gp')
            ->where('gp.is_active', true)
            ->whereNull('gp.deleted_at')
            ->selectRaw("gp.id, gp.floor, gp.area_id, gp.title, gp.description, gp.x, gp.y, gp.view_direction, gp.azimuth_deg, gp.coverage_radius_m, gp.sort_order, gp.primary_image_url, ST_X(ST_Transform(gp.geom,4326)) AS longitude, ST_Y(ST_Transform(gp.geom,4326)) AS latitude");

        if ($request->filled('floor')) {
            $q->where('gp.floor', (int) $request->query('floor'));
        }
        if ($request->filled('area_id')) {
            $q->where('gp.area_id', (int) $request->query('area_id'));
        }
        if ($request->filled(['near_x', 'near_y'])) {
            // Public API uses WGS84 longitude/latitude; convert to EPSG:32640 for
            // metric PostGIS distance operations.
            $lng = (float) $request->query('near_x');
            $lat = (float) $request->query('near_y');
            $radius = max(0.1, min((float) $request->query('radius_m', 30), 200));
            $pointSql = 'ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), 4326), 32640)';

            $q->whereRaw("ST_DWithin(gp.geom, {$pointSql}, ?)", [$lng, $lat, $radius])
                ->selectRaw("ST_Distance(gp.geom, {$pointSql}) AS distance_m", [$lng, $lat])
                ->orderBy('distance_m');
        } else {
            $q->orderBy('gp.floor')->orderBy('gp.area_id')->orderBy('gp.sort_order');
        }

        $rows = $q->limit($limit)->get();
        $imagesByPoint = $rows->isEmpty() ? [] : DB::table('guidance_point_images')
            ->whereIn('point_id', $rows->pluck('id')->all())
            ->orderBy('sort_order')
            ->get([
                'id',
                'point_id',
                'image_url',
                'image_key',
                'sort_order',
                'view_orientation',
                'azimuth_deg',
                'fov_deg',
                'caption',
                'attrs',
            ])
            ->groupBy('point_id')
            ->map(fn($items) => $items->map(fn($img) => [
                'id' => (int) $img->id,
                'image_url' => $img->image_url,
                'image_key' => $img->image_key,
                'url' => $img->image_url,
                'path' => $img->image_key,
                'sort_order' => (int) $img->sort_order,

                // جهت قابل نمایش برای کاربر/ادمین
                'view_orientation' => $img->view_orientation ?: 'unknown',

                // زاویه دقیق تصویر نسبت به شمال؛ مبنای مچ با heading کاربر
                'azimuth_deg' => $img->azimuth_deg !== null ? (float) $img->azimuth_deg : null,

                // میدان دید قابل قبول برای این تصویر
                'fov_deg' => $img->fov_deg !== null ? (float) $img->fov_deg : 60.0,

                'caption' => $img->caption,
                'attrs' => $img->attrs ? json_decode($img->attrs, true) : [],
            ])->values()->all())
            ->all();

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn($row) => [
                'id' => (int) $row->id,
                'floor' => (int) $row->floor,
                'area_id' => $row->area_id ? (int) $row->area_id : null,
                'title' => $row->title,
                'description' => $row->description,
                'x' => (float) $row->x,
                'y' => (float) $row->y,
                'longitude' => (float) $row->longitude,
                'latitude' => (float) $row->latitude,
                'view_direction' => $row->view_direction,
                'azimuth_deg' => $row->azimuth_deg !== null ? (float) $row->azimuth_deg : null,
                'coverage_radius_m' => (float) $row->coverage_radius_m,
                'sort_order' => (int) $row->sort_order,
                'distance_m' => isset($row->distance_m) ? (float) $row->distance_m : null,
                'primary_image_url' => $row->primary_image_url,
                'images' => $imagesByPoint[$row->id] ?? [],
            ])->values(),
            'meta' => ['count' => $rows->count()],
        ]);
    }
}
