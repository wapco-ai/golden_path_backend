<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\GuidancePointImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class GuidancePointController extends Controller
{
    public function __construct(private readonly GuidancePointImageService $images) {}

    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->query('limit', 25), 100));
        $sort = $this->parseSort((string) $request->query('sort', 'sort_order:asc'));

        $q = DB::table('guidance_points as gp')
            ->whereNull('gp.deleted_at')
            ->selectRaw("gp.id, gp.floor, gp.area_id, gp.title, gp.description, gp.x, gp.y, gp.view_direction, gp.azimuth_deg, gp.coverage_radius_m, gp.sort_order, gp.primary_image_url, gp.is_active, gp.created_by, gp.updated_by, gp.created_at, gp.updated_at, ST_X(ST_Transform(gp.geom,4326)) AS longitude, ST_Y(ST_Transform(gp.geom,4326)) AS latitude");

        $this->applyFilters($q, $request);

        $page = $q->orderBy($sort['column'], $sort['direction'])->paginate($limit);
        $items = collect($page->items());
        $imagesByPoint = $this->loadImages($items->pluck('id')->all());

        return response()->json([
            'success' => true,
            'data' => $items->map(fn($row) => $this->formatPoint($row, $imagesByPoint[$row->id] ?? []))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $row = $this->findPoint($id);
        if (!$row) {
            return $this->error('Guidance point not found.', 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatPoint($row, $this->loadImages([$id])[$id] ?? []),
        ]);
    }


    public function store(Request $request): JsonResponse
    {
        if (!$this->canMutate($request)) {
            return $this->error('Access denied.', 403);
        }

        $validator = $this->validator($request, false);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $data = $validator->validated();
        $files = $this->imageFiles($request);
        $meta = $this->imageMeta($request);

        if (($err = $this->validateImageCount($files)) !== null) {
            return $err;
        }

        $stored = [];
        $id = null;

        DB::beginTransaction();

        try {
            $userId = optional($request->user())->id;

            $row = DB::selectOne(
                "INSERT INTO guidance_points
                    (floor, area_id, title, description, x, y, view_direction, azimuth_deg, coverage_radius_m, sort_order, primary_image_url, is_active, created_by, updated_by, geom, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, 10.00), COALESCE(?, 0), ?, COALESCE(?, true), ?, ?, ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), 4326), 32640), now(), now())
                 RETURNING id",
                [
                    (int) $data['floor'],
                    $data['area_id'] ?? null,
                    $data['title'] ?? null,
                    $data['description'] ?? null,
                    (float) $data['x'],
                    (float) $data['y'],
                    $data['view_direction'] ?? null,
                    $data['azimuth_deg'] ?? null,
                    $data['coverage_radius_m'] ?? 10.00,
                    $data['sort_order'] ?? 0,
                    $data['primary_image_url'] ?? null,
                    array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
                    $userId,
                    $userId,
                    (float) $data['x'],
                    (float) $data['y'],
                ]
            );

            $id = (int) $row->id;

            // Keep database and filesystem creation logically atomic: if storage or DB
            // persistence fails, the DB transaction is rolled back and stored files are removed.
            $stored = $this->images->storeMany($files, $id, $meta);

            if ($stored) {
                $this->insertImages($id, $stored);

                DB::table('guidance_points')
                    ->where('id', $id)
                    ->whereNull('deleted_at')
                    ->update([
                        'primary_image_url' => $stored[0]['image_url'] ?? null,
                        'updated_at' => now(),
                    ]);
            }

            $this->logAdmin($request, 'create', $id, [
                'floor' => (int) $data['floor'],
                'area_id' => $data['area_id'] ?? null,
                'image_count' => count($stored),
            ]);

            DB::commit();
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->images->deleteKeys(array_column($stored, 'image_key'));
            report($e);

            return $this->error('Internal server error.', 500);
        }

        $response = $this->show((int) $id);
        $payload = $response->getData(true);
        $payload['id'] = (int) $id;
        $response->setData($payload);

        return $response->setStatusCode(201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->canMutate($request)) {
            return $this->error('Access denied.', 403);
        }

        $current = $this->findPoint($id);
        if (!$current) {
            return $this->error('Guidance point not found.', 404);
        }

        $validator = $this->validator($request, true);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $data = $validator->validated();
        $files = $this->imageFiles($request);
        $meta = $this->imageMeta($request);
        $hasNewImages = count($files) > 0;

        if (($err = $this->validateImageCount($files)) !== null) {
            return $err;
        }

        $existingRows = DB::table('guidance_point_images')
            ->where('point_id', $id)
            ->orderBy('sort_order')
            ->get(['id', 'image_key']);

        $existingIds = $existingRows
            ->pluck('id')
            ->map(fn($value) => (int) $value)
            ->all();

        $hasRetainedImageList = $request->has('existing_image_ids');
        $retainedIds = array_values(array_unique(array_map(
            'intval',
            (array) $request->input('existing_image_ids', [])
        )));

        if ($hasRetainedImageList) {
            $foreignIds = array_values(array_diff($retainedIds, $existingIds));
            if ($foreignIds) {
                return $this->error(
                    'One or more retained images do not belong to this guidance point.',
                    422,
                    ['existing_image_ids' => ['Invalid guidance point image ownership.']]
                );
            }
        } elseif (!$hasNewImages) {
            // Metadata-only update: keep all current images when the client did not
            // explicitly submit a retained-image list.
            $retainedIds = $existingIds;
        }

        $retainedCount = count($retainedIds);
        if ($retainedCount + count($files) > GuidancePointImageService::MAX_IMAGES) {
            return $this->error(
                'A guidance point can have at most 4 images.',
                422,
                ['images' => ['A guidance point can have at most 4 images.']]
            );
        }

        $stored = [];
        $oldKeys = [];
        $imageSetChanged = $hasNewImages || $hasRetainedImageList;

        try {
            if ($hasNewImages) {
                $stored = $this->images->storeMany($files, $id, $meta, $retainedCount);
            }

            DB::transaction(function () use (
                $id,
                $data,
                $request,
                $stored,
                $hasNewImages,
                $imageSetChanged,
                $retainedIds,
                &$oldKeys
            ) {
                if ($imageSetChanged) {
                    $deleteQuery = DB::table('guidance_point_images')
                        ->where('point_id', $id);

                    if ($retainedIds) {
                        $deleteQuery->whereNotIn('id', $retainedIds);
                    }

                    $oldKeys = $deleteQuery
                        ->pluck('image_key')
                        ->filter()
                        ->map(fn($value) => (string) $value)
                        ->all();

                    $deleteQuery->delete();

                    if ($retainedIds) {
                        // Move retained rows out of the unique sort-order range first,
                        // then write the client order back as 1..N.
                        DB::table('guidance_point_images')
                            ->where('point_id', $id)
                            ->whereIn('id', $retainedIds)
                            ->update([
                                'sort_order' => DB::raw('sort_order + 100'),
                                'updated_at' => now(),
                            ]);

                        foreach ($retainedIds as $index => $imageId) {
                            DB::table('guidance_point_images')
                                ->where('point_id', $id)
                                ->where('id', $imageId)
                                ->update([
                                    'sort_order' => $index + 1,
                                    'updated_at' => now(),
                                ]);
                        }
                    }
                }

                $sets = ['updated_at = now()', 'updated_by = ?'];
                $bind = [optional($request->user())->id];

                foreach (['floor', 'area_id', 'title', 'description', 'view_direction', 'azimuth_deg', 'coverage_radius_m', 'sort_order', 'is_active'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $sets[] = $field . ' = ?';
                        $bind[] = in_array($field, ['floor', 'area_id', 'sort_order'], true) && $data[$field] !== null
                            ? (int) $data[$field]
                            : $data[$field];
                    }
                }

                $hasLocation = $request->filled('x') && $request->filled('y');
                if ($hasLocation) {
                    $lng = (float) $data['x'];
                    $lat = (float) $data['y'];

                    $sets[] = 'x = ?';
                    $bind[] = $lng;
                    $sets[] = 'y = ?';
                    $bind[] = $lat;
                    $sets[] = 'geom = ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), 4326), 32640)';
                    $bind[] = $lng;
                    $bind[] = $lat;
                }

                if (array_key_exists('primary_image_url', $data) && !$imageSetChanged) {
                    $sets[] = 'primary_image_url = ?';
                    $bind[] = $data['primary_image_url'];
                }

                $bind[] = $id;
                DB::update(
                    'UPDATE guidance_points SET ' . implode(', ', $sets) . ' WHERE id = ? AND deleted_at IS NULL',
                    $bind
                );

                if ($hasNewImages) {
                    $this->insertImages($id, $stored);
                }

                $this->updateExistingImageMeta($request, $id);

                if ($imageSetChanged) {
                    $nextImageUrl = DB::table('guidance_point_images')
                        ->where('point_id', $id)
                        ->orderBy('sort_order')
                        ->value('image_url');

                    DB::table('guidance_points')
                        ->where('id', $id)
                        ->whereNull('deleted_at')
                        ->update([
                            'primary_image_url' => $nextImageUrl,
                            'updated_at' => now(),
                        ]);
                }

                $this->logAdmin($request, 'update', $id, [
                    'image_set_changed' => $imageSetChanged,
                    'retained_image_count' => count($retainedIds),
                    'new_image_count' => count($stored),
                ]);
            });

            // Delete replaced files only after the database transaction succeeds.
            $this->images->deleteKeys($oldKeys);

            return $this->show($id);
        } catch (Throwable $e) {
            $this->images->deleteKeys(array_column($stored, 'image_key'));
            report($e);

            return $this->error('Internal server error.', 500);
        }
    }


    private function updateExistingImageMeta(Request $request, int $pointId): void
    {
        $ids = $request->input('existing_image_ids', []);
        $orientations = $request->input('existing_image_orientations', []);
        $azimuths = $request->input('existing_image_azimuths', []);
        $fovs = $request->input('existing_image_fovs', []);
        $captions = $request->input('existing_image_captions', []);

        foreach ($ids as $index => $imageId) {
            DB::table('guidance_point_images')
                ->where('id', (int) $imageId)
                ->where('point_id', $pointId)
                ->update([
                    'view_orientation' => $orientations[$index] ?? 'unknown',
                    'azimuth_deg' => isset($azimuths[$index]) && $azimuths[$index] !== ''
                        ? (float) $azimuths[$index]
                        : null,
                    'fov_deg' => isset($fovs[$index]) && $fovs[$index] !== ''
                        ? (float) $fovs[$index]
                        : 60,
                    'caption' => $captions[$index] ?? null,
                    'updated_at' => now(),
                ]);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (!$this->canMutate($request)) {
            return $this->error('Access denied.', 403);
        }

        if (!$this->findPoint($id)) {
            return $this->error('Guidance point not found.', 404);
        }

        $imageKeys = [];

        DB::transaction(function () use ($request, $id, &$imageKeys) {
            $imageKeys = DB::table('guidance_point_images')
                ->where('point_id', $id)
                ->pluck('image_key')
                ->filter()
                ->map(fn($value) => (string) $value)
                ->all();

            DB::table('guidance_point_images')
                ->where('point_id', $id)
                ->delete();

            DB::table('guidance_points')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                    'updated_by' => optional($request->user())->id,
                    'is_active' => false,
                    'primary_image_url' => null,
                ]);

            $this->logAdmin($request, 'delete', $id, [
                'deleted_image_count' => count($imageKeys),
            ]);
        });

        $this->images->deleteKeys($imageKeys);

        return response()->json(['success' => true, 'message' => 'Guidance point deleted.']);
    }

    private function validator(Request $request, bool $isUpdate)
    {
        $maxKb = (int) env('GUIDANCE_POINTS_IMAGE_MAX_KB', 5120);
        return Validator::make($request->all(), [
            'floor' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'in:-1,0'],
            'area_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'x' => [$isUpdate ? 'sometimes' : 'required', 'numeric', 'between:-180,180'],
            'y' => [$isUpdate ? 'sometimes' : 'required', 'numeric', 'between:-90,90'],
            'view_direction' => ['sometimes', 'nullable', 'string', 'max:40'],
            'azimuth_deg' => ['sometimes', 'nullable', 'numeric', 'gte:0', 'lt:360'],
            'coverage_radius_m' => ['sometimes', 'numeric', 'gt:0', 'lte:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'primary_image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'array', 'max:' . GuidancePointImageService::MAX_IMAGES],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:' . $maxKb],
            'image_orientations' => ['sometimes', 'array', 'max:' . GuidancePointImageService::MAX_IMAGES],

            'image_orientations.*' => ['nullable', 'string', 'in:north,north_east,east,south_east,south,south_west,west,north_west,unknown'],

            'image_azimuths' => ['sometimes', 'array', 'max:' . GuidancePointImageService::MAX_IMAGES],
            'image_azimuths.*' => ['nullable', 'numeric', 'gte:0', 'lt:360'],

            'image_fovs' => ['sometimes', 'array', 'max:' . GuidancePointImageService::MAX_IMAGES],
            'image_fovs.*' => ['nullable', 'numeric', 'gt:0', 'lte:180'],

            'image_captions' => ['sometimes', 'array', 'max:' . GuidancePointImageService::MAX_IMAGES],
            'image_captions.*' => ['nullable', 'string', 'max:160'],

            'existing_image_ids' => ['sometimes', 'array'],
            'existing_image_ids.*' => ['integer', 'exists:guidance_point_images,id'],

            'existing_image_orientations' => ['sometimes', 'array'],
            'existing_image_orientations.*' => ['nullable', 'string', 'in:north,north_east,east,south_east,south,south_west,west,north_west,unknown'],

            'existing_image_azimuths' => ['sometimes', 'array'],
            'existing_image_azimuths.*' => ['nullable', 'numeric', 'gte:0', 'lt:360'],

            'existing_image_fovs' => ['sometimes', 'array'],
            'existing_image_fovs.*' => ['nullable', 'numeric', 'gt:0', 'lte:180'],

            'existing_image_captions' => ['sometimes', 'array'],
            'existing_image_captions.*' => ['nullable', 'string', 'max:160'],
        ]);
    }


    private function imageMeta(Request $request): array
    {
        $orientations = $request->input('image_orientations', []);
        $azimuths = $request->input('image_azimuths', []);
        $fovs = $request->input('image_fovs', []);
        $captions = $request->input('image_captions', []);

        $meta = [];

        for ($i = 0; $i < GuidancePointImageService::MAX_IMAGES; $i++) {
            $meta[$i] = [
                'view_orientation' => $orientations[$i] ?? null,
                'azimuth_deg' => isset($azimuths[$i]) && $azimuths[$i] !== '' ? (float) $azimuths[$i] : null,
                'fov_deg' => isset($fovs[$i]) && $fovs[$i] !== '' ? (float) $fovs[$i] : 60,
                'caption' => $captions[$i] ?? null,
            ];
        }

        return $meta;
    }

    private function applyFilters($q, Request $request): void
    {
        if ($request->filled('floor')) {
            $q->where('gp.floor', (int) $request->query('floor'));
        }
        if ($request->filled('area_id')) {
            $q->where('gp.area_id', (int) $request->query('area_id'));
        }
        if ($request->filled('is_active')) {
            $q->where('gp.is_active', $request->boolean('is_active'));
        }
        if ($request->filled('search')) {
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->query('search')) . '%';
            $q->where(fn($qq) => $qq->where('gp.title', 'ILIKE', $needle)->orWhere('gp.description', 'ILIKE', $needle));
        }
        if ($request->filled(['near_x', 'near_y'])) {
            // API x/y are consistently WGS84 longitude/latitude. Convert to the
            // canonical routing/data SRID (EPSG:32640) before metric distance checks.
            $lng = (float) $request->query('near_x');
            $lat = (float) $request->query('near_y');
            $radius = max(0.1, min((float) $request->query('radius_m', 30), 200));
            $pointSql = 'ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), 4326), 32640)';

            $q->whereRaw("ST_DWithin(gp.geom, {$pointSql}, ?)", [$lng, $lat, $radius]);
            $q->selectRaw("ST_Distance(gp.geom, {$pointSql}) AS distance_m", [$lng, $lat]);
        }
    }

    private function imageFiles(Request $request): array
    {
        $files = $request->file('images', []);
        if ($files === null) return [];
        return is_array($files) ? array_values($files) : [$files];
    }

    private function validateImageCount(array $files): ?JsonResponse
    {
        if (count($files) > GuidancePointImageService::MAX_IMAGES) {
            return $this->error('A guidance point can have at most 4 images.', 422, ['images' => ['A guidance point can have at most 4 images.']]);
        }
        return null;
    }

    private function parseSort(string $sort): array
    {
        [$column, $direction] = array_pad(explode(':', $sort, 2), 2, 'asc');
        $allowed = ['id', 'floor', 'area_id', 'title', 'sort_order', 'is_active', 'created_at', 'updated_at'];
        $column = in_array($column, $allowed, true) ? 'gp.' . $column : 'gp.sort_order';
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        return compact('column', 'direction');
    }

    private function findPoint(int $id): ?object
    {
        return DB::table('guidance_points as gp')
            ->where('gp.id', $id)
            ->whereNull('gp.deleted_at')
            ->selectRaw("gp.*, ST_X(ST_Transform(gp.geom,4326)) AS longitude, ST_Y(ST_Transform(gp.geom,4326)) AS latitude")
            ->first();
    }

    private function loadImages(array $ids): array
    {
        if (!$ids) return [];

        $dbImages = DB::table('guidance_point_images')
            ->whereIn('point_id', $ids)
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
                'view_orientation' => $img->view_orientation,
                'azimuth_deg' => $img->azimuth_deg !== null ? (float) $img->azimuth_deg : null,
                'fov_deg' => $img->fov_deg !== null ? (float) $img->fov_deg : 60,
                'caption' => $img->caption,
                'attrs' => $img->attrs ? json_decode($img->attrs, true) : [],
                'source' => 'guidance_point_images',
            ])->values()->all())
            ->all();

        // Backward/compat fallback: generic /files uploads may exist under
        // uploads/poi_points/{id}/images even before they were inserted into guidance_point_images.
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        foreach ($ids as $id) {
            $id = (int) $id;
            $images = $dbImages[$id] ?? [];
            $knownPaths = collect($images)->pluck('image_key')->filter()->all();
            $dir = "uploads/guidance_points/{$id}/images";

            if ($disk->exists($dir)) {
                foreach ($disk->files($dir) as $path) {
                    if (in_array($path, $knownPaths, true)) {
                        continue;
                    }
                    $images[] = [
                        'id' => null,
                        'image_url' => $disk->url($path),
                        'image_key' => $path,
                        'url' => $disk->url($path),
                        'path' => $path,
                        'sort_order' => count($images) + 1,
                        'source' => 'files_storage',
                    ];
                }
            }

            if ($images) {
                $dbImages[$id] = array_values($images);
            }
        }

        return $dbImages;
    }

    private function formatPoint(object $row, array $images): array
    {
        return [
            'id' => (int) $row->id,
            'floor' => (int) $row->floor,
            'area_id' => $row->area_id ? (int) $row->area_id : null,
            'title' => $row->title,
            'description' => $row->description,
            'x' => (float) $row->x,
            'y' => (float) $row->y,
            'longitude' => isset($row->longitude) ? (float) $row->longitude : null,
            'latitude' => isset($row->latitude) ? (float) $row->latitude : null,
            'view_direction' => $row->view_direction,
            'azimuth_deg' => $row->azimuth_deg !== null ? (float) $row->azimuth_deg : null,
            'coverage_radius_m' => (float) $row->coverage_radius_m,
            'sort_order' => (int) $row->sort_order,
            'distance_m' => isset($row->distance_m) ? (float) $row->distance_m : null,
            'primary_image_url' => $row->primary_image_url,
            'images' => $images,
            'is_active' => (bool) $row->is_active,
            'created_by' => $row->created_by ? (int) $row->created_by : null,
            'updated_by' => $row->updated_by ? (int) $row->updated_by : null,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function insertImages(int $pointId, array $images): void
    {
        if (!$images) return;

        DB::table('guidance_point_images')->insert(array_map(function ($img) use ($pointId) {
            $attrs = $img['attrs'] ?? [];

            if (is_array($attrs)) {
                $attrs = json_encode($attrs, JSON_UNESCAPED_UNICODE);
            }

            if ($attrs === null || $attrs === '') {
                $attrs = json_encode([], JSON_UNESCAPED_UNICODE);
            }

            return [
                'point_id' => $pointId,
                'image_url' => $img['image_url'],
                'image_key' => $img['image_key'],
                'sort_order' => $img['sort_order'],
                'view_orientation' => $img['view_orientation'] ?? null,
                'azimuth_deg' => $img['azimuth_deg'] ?? null,
                'fov_deg' => $img['fov_deg'] ?? 60,
                'caption' => $img['caption'] ?? null,
                'attrs' => $attrs,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $images));
    }

    private function canMutate(Request $request): bool
    {
        $user = $request->user();

        if (!$user || empty($user->is_admin)) {
            return false;
        }

        $allowedRoles = [
            'super_dashboard_admin',
            'superadmin',
            'admin',
            'administrator',
            'guidance_points_manager',
            'guidance-point-manager',
            'guidance_points_admin',
        ];

        $allowedPermissions = [
            'guidance_points.manage',
            'guidance_points.write',
            'guidance-points.manage',
            'guidance-points.write',
            'manage_guidance_points',
            'manage_guidance_point_images',
            'map.manage',
            'manage_maps',
            'manage_routing_logs',
            'manage_cultural_items',
        ];

        if (method_exists($user, 'adminRoles')) {
            $roles = $user->adminRoles()
                ->pluck('code')
                ->map(fn($code) => strtolower(trim((string) $code)))
                ->all();

            if (array_intersect($roles, $allowedRoles)) {
                return true;
            }
        }

        if (method_exists($user, 'adminPermissions')) {
            $permissions = $user->adminPermissions()
                ->pluck('code')
                ->map(fn($code) => strtolower(trim((string) $code)))
                ->all();

            if (array_intersect($permissions, $allowedPermissions)) {
                return true;
            }
        }

        return false;
    }

    private function logAdmin(Request $request, string $action, ?int $entityId, array $meta): void
    {
        try {
            DB::table('admin_activity_logs')->insert([
                'user_id' => optional($request->user())->id,
                'action' => 'guidance_points.' . $action,
                'entity_table' => 'guidance_points',
                'entity_id' => $entityId,
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 2000),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function validationError(array $errors): JsonResponse
    {
        return $this->error('Validation failed.', 422, $errors);
    }

    private function error(string $message, int $status, array $errors = []): JsonResponse
    {
        $payload = ['success' => false, 'message' => $message];
        if ($errors) $payload['errors'] = $errors;
        return response()->json($payload, $status);
    }
}
