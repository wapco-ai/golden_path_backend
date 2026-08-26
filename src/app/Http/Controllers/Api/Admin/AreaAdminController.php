<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Area;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use App\Services\I18nTextService;


class AreaAdminController extends Controller
{
    /**
     * GET /api/v1/areas
     *
     * مثل قبل، فقط attrs شامل time_restrictions/prayer_restrictions هست
     * و از جداول جدید خوانده نمی‌شود (فعلاً فقط برای ثبت/بروزرسانی استفاده می‌کنیم).
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'floor'          => 'nullable|integer',
            'area_type'      => 'nullable|string|max:50',
            'allowed_gender' => 'nullable|string|max:20',
            'is_closed'      => 'nullable|boolean',
            'is_covered'     => 'nullable|boolean',
            'status'         => 'nullable|string|max:20',
            'bbox'           => 'nullable|string',
            'search'         => 'nullable|string|max:200',
            'language'       => 'nullable|string|in:fa,en,ar,ur',
            'per_page'       => 'nullable|integer|min:1|max:500',
        ]);

        $language = $data['language'] ?? 'fa';
        $perPage  = $data['per_page'] ?? 50;

        $query = Area::query()
            ->selectRaw("
                areas.*,
                ST_AsGeoJSON(geom) AS geom_geojson,
                it_title.txt AS title
            ")
            ->leftJoin('i18n_texts AS it_title', function ($join) use ($language) {
                $join->on('it_title.entity_table', '=', DB::raw("'areas'"))
                    ->on('it_title.entity_id', '=', 'areas.id')
                    ->where('it_title.field', 'name')    // ⬅️ اصلاح شده
                    ->where('it_title.lang', $language);
            });
        if (isset($data['floor'])) {
            $query->where('areas.floor', $data['floor']);
        }

        if (!empty($data['area_type'])) {
            $query->where('areas.area_type', $data['area_type']);
        }

        if (!empty($data['allowed_gender'])) {
            $query->where('areas.allowed_gender', $data['allowed_gender']);
        }

        if (array_key_exists('is_closed', $data)) {
            $query->where('areas.is_closed', $data['is_closed']);
        }

        if (array_key_exists('is_covered', $data)) {
            $query->whereRaw(
                "(attrs->'operational'->>'is_covered')::boolean = ?",
                [$data['is_covered']]
            );
        }

        if (!empty($data['status'])) {
            $query->whereRaw(
                "attrs->'operational'->>'status' = ?",
                [$data['status']]
            );
        }

        if (!empty($data['search'])) {
            $s = '%' . str_replace(' ', '%', $data['search']) . '%';
            $query->where(function ($q) use ($s) {
                $q->where('it_title.txt', 'ILIKE', $s);
            });
        }

        if (!empty($data['bbox'])) {
            $parts = explode(',', $data['bbox']);
            if (count($parts) === 4) {
                [$minX, $minY, $maxX, $maxY] = array_map('floatval', $parts);

                $polygonWkt = sprintf(
                    'POLYGON((%f %f,%f %f,%f %f,%f %f,%f %f))',
                    $minX,
                    $minY,
                    $maxX,
                    $minY,
                    $maxX,
                    $maxY,
                    $minX,
                    $maxY,
                    $minX,
                    $minY
                );

                $query->whereRaw(
                    'ST_Intersects(geom, ST_GeomFromText(?, 32640))',
                    [$polygonWkt]
                );
            }
        }

        $items = $query
            ->orderBy('areas.floor')
            ->orderBy('areas.id')
            ->paginate($perPage);

        return response()->json($items);
    }

    /**
     * GET /api/v1/admin/areas/{id}
     *
     * attrs شامل time_restrictions/prayer_restrictions هست.
     * اگر فرانت خواست، می‌تونه مستقیماً از attrs بخونه؛
     * مقادیر جداول access_* با attrs sync می‌شوند.
     */
    public function show($id, Request $request)
    {
        $language = $request->query('language', 'fa');

        $area = DB::table('areas AS a')
            ->selectRaw("
            a.*,
            ST_AsGeoJSON(a.geom) AS geom_geojson,
            it_title.txt AS title,
            it_desc.txt  AS description
        ")
            ->leftJoin('i18n_texts AS it_title', function ($join) use ($language) {
                $join->on('it_title.entity_table', '=', DB::raw("'areas'"))
                    ->on('it_title.entity_id', '=', 'a.id')
                    ->where('it_title.field', 'name')
                    ->where('it_title.lang', $language);
            })
            ->leftJoin('i18n_texts AS it_desc', function ($join) use ($language) {
                $join->on('it_desc.entity_table', '=', DB::raw("'areas'"))
                    ->on('it_desc.entity_id', '=', 'a.id')
                    ->where('it_desc.field', 'desc')
                    ->where('it_desc.lang', $language);
            })
            ->where('a.id', $id)
            ->first();

        if (!$area) {
            return response()->json([
                'message' => 'Area not found',
            ], Response::HTTP_NOT_FOUND);
        }

        // attrs را به آرایه PHP تبدیل کن
        $attrs = $area->attrs ? json_decode($area->attrs, true) : [];

    // ✅ همه ترجمه‌ها از i18n_texts
        /** @var I18nTextService $i18n */
        $i18n = app(I18nTextService::class);

        $titleAll = array_merge($i18n->empty4(), $i18n->getLangMap('areas', (int)$id, 'name'));
        $descAll  = array_merge($i18n->empty4(), $i18n->getLangMap('areas', (int)$id, 'desc'));

        // basic_info را بساز/مرج کن ولی title را همیشه all-lang برگردان
        $basicInfo = $attrs['basic_info'] ?? [];
        $basicInfo['title'] = $titleAll;

        // برای اینکه قرارداد قبلی (string بودن description) نشکند:
        // - description قبلی را نگه می‌داریم
        // - یک فیلد جدید description_i18n هم اضافه می‌کنیم
        if (!array_key_exists('description', $basicInfo)) {
            $basicInfo['description'] = $area->description; // همان زبانِ درخواستی
        }
        $basicInfo['description_i18n'] = $descAll;

        $area->basic_info          = $basicInfo;
        $area->grouping            = $attrs['grouping']            ?? null;
        $area->operational         = $attrs['operational']         ?? null;
        $area->time_restrictions   = $attrs['time_restrictions']   ?? [];
        $area->prayer_restrictions = $attrs['prayer_restrictions'] ?? [];

        // اگر title/description ستون‌های join شده null بودند، از all-lang پر کن
        if (empty($area->title)) {
            $area->title = $titleAll[$language] ?? $titleAll['fa'] ?? null;
        }
        if (empty($area->description)) {
            $area->description = $descAll[$language] ?? $descAll['fa'] ?? null;
        }

        // هندسه
        $area->geometry = $area->geom_geojson
            ? json_decode($area->geom_geojson, true)
            : null;

        // متادیتا
        $area->meta = [
            'area_type'         => $area->area_type,
            'floor'             => $area->floor,
            'allowed_gender'    => $area->allowed_gender,
            'is_closed'         => (bool) $area->is_closed,
            'weight_open_space' => $area->weight_open_space,
        ];

        $area->attrs_raw = $attrs;

        return response()->json($area);
    }



    /**
     * POST /api/v1/admin/areas
     *
     * محدودیت‌ها:
     *  - در attrs ذخیره می‌شن (time_restrictions / prayer_restrictions)
     *  - هم‌زمان در جداول access_time_restrictions و access_prayer_restrictions
     *    برای entity_table = 'areas' و entity_id = id رکورد درج می‌شن.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'basic_info.title.fa'   => 'required|string|max:255',
            'basic_info.title.en'   => 'nullable|string|max:255',
            'basic_info.title.ar'   => 'nullable|string|max:255',
            'basic_info.title.ur'   => 'nullable|string|max:255',
            'basic_info.description' => 'nullable|string',

            'grouping.group_id'      => 'nullable|string|max:100',
            'grouping.sub_group_id'  => 'nullable|string|max:100',
            'grouping.sub_group_label' => 'nullable|string|max:255',

            'operational.status'          => 'required|string|max:20',
            'operational.transport_modes' => 'nullable|array',
            'operational.transport_modes.*' => 'string|max:50',
            'operational.gender_access'   => 'nullable|array',
            'operational.gender_access.*' => 'string|max:50',
            'operational.is_covered'      => 'required|boolean',
            'operational.description'     => 'nullable|string',

            'time_restrictions'           => 'nullable|array',
            'prayer_restrictions'         => 'nullable|array',

            'geometry'                    => 'required|array',
            'meta.area_type'              => 'required|string|max:50',
            'meta.floor'                  => 'required|integer',
            'meta.allowed_gender'         => 'nullable|string|max:20',
            'meta.is_closed'              => 'nullable|boolean',
            'meta.weight_open_space'      => 'nullable|numeric',
        ]);

        $attrs = [
            'basic_info'          => $data['basic_info'] ?? null,
            'grouping'            => $data['grouping'] ?? null,
            'operational'         => $data['operational'] ?? null,
            'time_restrictions'   => $data['time_restrictions'] ?? [],
            'prayer_restrictions' => $data['prayer_restrictions'] ?? [],
        ];

        // Derive meta.allowed_gender from operational.gender_access when meta.allowed_gender is not provided
        $derivedAllowedGender = null;

        if (empty($data['meta']['allowed_gender']) && !empty($data['operational']['gender_access']) && is_array($data['operational']['gender_access'])) {
            $ga = array_values(array_unique($data['operational']['gender_access']));

            // normalize
            $ga = array_map(
                static function ($x) {

                    $x = strtolower(
                        trim((string) $x)
                    );

                    return $x === 'family'
                        ? 'both'
                        : $x;
                },
                $ga
            );

            // expected enum values: male, female, both
            if (count($ga) === 1 && in_array($ga[0], ['male', 'female', 'both'], true)) {
                $derivedAllowedGender = $ga[0];
            } elseif (in_array('male', $ga, true) && in_array('female', $ga, true)) {
                $derivedAllowedGender = 'both';
            } elseif (in_array('both', $ga, true)) {
                $derivedAllowedGender = 'both';
            }
        }


        // ترنزاکشن: درج area + درج محدودیت‌ها + i18n
        return DB::transaction(function () use ($data, $attrs) {

            $row = DB::selectOne(
                <<<SQL
                INSERT INTO areas (
                    geom,
                    area_type,
                    floor,
                    allowed_gender,
                    is_closed,
                    weight_open_space,
                    attrs
                )
                VALUES (
                    ST_SetSRID(ST_GeomFromGeoJSON(?), 32640),
                    ?::area_type_enum,
                    ?,
                    COALESCE(?::gender_enum, 'both'::gender_enum),
                    COALESCE(?, false),
                    COALESCE(?, 1.0),
                    ?::jsonb
                )
                RETURNING
                    id,
                    area_type::text AS area_type,
                    floor,
                    allowed_gender::text AS allowed_gender,
                    is_closed,
                    weight_open_space,
                    attrs,
                    updated_at,
                    ST_AsGeoJSON(geom) AS geom_geojson
                SQL,
                [
                    json_encode($data['geometry']),
                    $data['meta']['area_type'],
                    $data['meta']['floor'],
                    $data['meta']['allowed_gender'] ?? $derivedAllowedGender,
                    $data['meta']['is_closed'] ?? null,
                    $data['meta']['weight_open_space'] ?? null,
                    json_encode($attrs),
                ]
            );

            $areaId = $row->id;

            // i18n
            $this->upsertI18nTitleDesc('areas', $areaId, $data['basic_info'] ?? []);

            // سینک محدودیت‌های زمان و نماز در جداول جدید
            $this->syncTimeRestrictionsForArea($areaId, $attrs['time_restrictions']);
            $this->syncPrayerRestrictionsForArea($areaId, $attrs['prayer_restrictions']);

            return response()->json($row, Response::HTTP_CREATED);
        });
    }

    /**
     * PUT /api/v1/admin/areas/{id}
     *
     * اگر time_restrictions / prayer_restrictions در body باشند:
     *  - در attrs جایگزین می‌شن
     *  - جداول access_* مربوط به این area پاک و دوباره از روی JSON جدید پر می‌شن.
     * اگر نباشند، محدودیت‌های قبلی دست‌نخورده می‌مونن.
     */
    public function update(Request $request, $id)
    {
        $data = $request->validate(
            [
                'basic_info.title.fa'   => 'sometimes|string|max:255',
                'basic_info.title.en'   => 'sometimes|nullable|string|max:255',
                'basic_info.title.ar'   => 'sometimes|nullable|string|max:255',
                'basic_info.title.ur'   => 'sometimes|nullable|string|max:255',
                'basic_info.description' => 'sometimes|nullable|string',

                'grouping.group_id'      => 'sometimes|nullable|string|max:100',
                'grouping.sub_group_id'  => 'sometimes|nullable|string|max:100',
                'grouping.sub_group_label' => 'sometimes|nullable|string|max:255',

                'operational.status'          => 'sometimes|string|max:20',
                'operational.transport_modes' => 'sometimes|array',
                'operational.transport_modes.*' => 'string|max:50',
                'operational.gender_access'   => 'sometimes|array',
                'operational.gender_access.*' => 'string|max:50',
                'operational.is_covered'      => 'sometimes|boolean',
                'operational.description'     => 'sometimes|nullable|string',

                'time_restrictions'           => 'sometimes|array',
                'prayer_restrictions'         => 'sometimes|array',

                'geometry'                    => 'sometimes|array',
                'meta.area_type'              => 'sometimes|string|max:50',
                'meta.floor'                  => 'sometimes|integer',
                'meta.allowed_gender'         => 'sometimes|string|max:20',
                'meta.is_closed'              => 'sometimes|boolean',
                'meta.weight_open_space'      => 'sometimes|numeric',
            ],
            [
                'operational.is_covered.required' => 'انتخاب «مسقف/غیرمسقف» الزامی است.',
                'operational.is_covered.boolean'  => 'مقدار «مسقف/غیرمسقف» نامعتبر است. لطفاً مسقف یا غیرمسقف را انتخاب کنید.',
            ]
        );

        // PATCH: derive allowed_gender from gender_access
        // ---------------------------------------------
        $derivedAllowedGender = null;

        if (
            empty($data['meta']['allowed_gender']) &&
            !empty($data['operational']['gender_access']) &&
            is_array($data['operational']['gender_access'])
        ) {
            $ga = array_values(array_unique($data['operational']['gender_access']));

            // normalize values
            $ga = array_map(
                static function ($x) {

                    $x = strtolower(
                        trim((string) $x)
                    );

                    return $x === 'family'
                        ? 'both'
                        : $x;
                },
                $ga
            );

            // expected enum values: male, female, both
            if (count($ga) === 1 && in_array($ga[0], ['male', 'female', 'both'], true)) {
                $derivedAllowedGender = $ga[0];
            } elseif (in_array('male', $ga, true) && in_array('female', $ga, true)) {
                $derivedAllowedGender = 'both';
            } elseif (in_array('both', $ga, true)) {
                $derivedAllowedGender = 'both';
            }
        }


        // PATCH: derive is_closed from operational.status
        // ---------------------------------------------
        $derivedIsClosed = null;

        if (
            !array_key_exists('is_closed', $data['meta'] ?? []) &&
            isset($data['operational']['status'])
        ) {
            $derivedIsClosed = strtolower((string) $data['operational']['status']) === 'inactive';
        }



        $exists = DB::table('areas')->where('id', $id)->exists();
        if (!$exists) {
            return response()->json([
                'message' => 'Area not found',
            ], Response::HTTP_NOT_FOUND);
        }

        return DB::transaction(function () use ($data, $id, $derivedAllowedGender, $derivedIsClosed) {

            $currentAttrs = DB::table('areas')
                ->where('id', $id)
                ->value('attrs');

            $attrs = $currentAttrs ? json_decode($currentAttrs, true) : [];

            foreach (['basic_info', 'grouping', 'operational', 'time_restrictions', 'prayer_restrictions'] as $key) {
                if (array_key_exists($key, $data)) {
                    $attrs[$key] = $data[$key];
                }
            }

            $sets     = [];
            $bindings = [];

            if (array_key_exists('geometry', $data)) {
                $sets[]     = 'geom = ST_SetSRID(ST_GeomFromGeoJSON(?), 32640)';
                $bindings[] = json_encode($data['geometry']);
            }
            if (isset($data['meta']['area_type'])) {
                $sets[]     = 'area_type = ?::area_type_enum';
                $bindings[] = $data['meta']['area_type'];
            }
            if (isset($data['meta']['floor'])) {
                $sets[]     = 'floor = ?';
                $bindings[] = $data['meta']['floor'];
            }
            $allowedGenderToSet =
                $data['meta']['allowed_gender']
                ?? $derivedAllowedGender
                ?? null;

            if ($allowedGenderToSet !== null) {
                $sets[]     = 'allowed_gender = ?::gender_enum';
                $bindings[] = $allowedGenderToSet;
            }
            if (array_key_exists('is_closed', $data['meta'] ?? []) || $derivedIsClosed !== null) {
                $sets[]     = 'is_closed = ?';
                $bindings[] = array_key_exists('is_closed', $data['meta'] ?? [])
                    ? $data['meta']['is_closed']
                    : $derivedIsClosed;
            }
            if (array_key_exists('weight_open_space', $data['meta'] ?? [])) {
                $sets[]     = 'weight_open_space = ?';
                $bindings[] = $data['meta']['weight_open_space'];
            }

            $sets[]     = 'attrs = ?::jsonb';
            $bindings[] = json_encode($attrs);

            $sets[] = 'updated_at = now()';

            $sql = sprintf(
                'UPDATE areas SET %s WHERE id = ? RETURNING
                    id,
                    area_type::text AS area_type,
                    floor,
                    allowed_gender::text AS allowed_gender,
                    is_closed,
                    weight_open_space,
                    attrs,
                    updated_at,
                    ST_AsGeoJSON(geom) AS geom_geojson',
                implode(', ', $sets)
            );

            $bindings[] = $id;

            $row = DB::selectOne($sql, $bindings);

            // i18n
            if (array_key_exists('basic_info', $data)) {
                $this->upsertI18nTitleDesc('areas', $id, $data['basic_info']);
            }

            // اگر time/prayer در ورودی بود، جدول‌های نرمال‌شده را هم sync کن
            if (array_key_exists('time_restrictions', $data)) {
                $this->syncTimeRestrictionsForArea($id, $attrs['time_restrictions'] ?? []);
            }
            if (array_key_exists('prayer_restrictions', $data)) {
                $this->syncPrayerRestrictionsForArea($id, $attrs['prayer_restrictions'] ?? []);
            }

            return response()->json($row);
        });
    }

    /**
     * DELETE /api/v1/admin/areas/{id}
     *
     * علاوه بر حذف رکورد و i18n، محدودیت‌های access_* آن هم حذف می‌شن.
     */
    public function destroy($id)
    {
        DB::transaction(function () use ($id) {

            DB::table('i18n_texts')
                ->where('entity_table', 'areas')
                ->where('entity_id', $id)
                ->delete();

            DB::table('access_time_restrictions')
                ->where('entity_table', 'areas')
                ->where('entity_id', $id)
                ->delete();

            DB::table('access_prayer_restrictions')
                ->where('entity_table', 'areas')
                ->where('entity_id', $id)
                ->delete();

            $deleted = DB::table('areas')
                ->where('id', $id)
                ->delete();

            if (!$deleted) {
                throw new \RuntimeException('Area not found');
            }
        });

        return response()->json([
            'message' => 'Area deleted successfully',
        ]);
    }

    /* --------------------------------------------------------------------
     * Helper: i18n
     * ------------------------------------------------------------------ */
    protected function upsertI18nTitleDesc(string $entityTable, int $entityId, array $basicInfo): void
    {
        $titles = $basicInfo['title'] ?? [];
        $desc   = $basicInfo['description'] ?? null;

        foreach (['fa', 'en', 'ar', 'ur'] as $lang) {
            if (!empty($titles[$lang])) {
                DB::table('i18n_texts')->updateOrInsert(
                    [
                        'entity_table' => $entityTable,
                        'entity_id'    => $entityId,
                        'field'        => 'name',
                        'lang'         => $lang,
                    ],
                    [
                        'txt' => $titles[$lang],
                    ]
                );
            }
        }

        if ($desc !== null) {
            DB::table('i18n_texts')->updateOrInsert(
                [
                    'entity_table' => $entityTable,
                    'entity_id'    => $entityId,
                    'field'        => 'desc',
                    'lang'         => 'fa',
                ],
                [
                    'txt' => $desc,
                ]
            );
        }
    }

    /* --------------------------------------------------------------------
     * Helpers: sync time / prayer restrictions for areas
     * ------------------------------------------------------------------ */

    /**
     * تمام ردیف‌های access_time_restrictions برای این area را پاک کرده
     * و از روی آرایه‌ی time_restrictions دوباره درج می‌کند.
     *
     * ساختار time_restrictions در attrs همان چیزی است که فرانت می‌فرستد:
     * [
     *   {
     *     "date_scope": ["این ماه"],
     *     "gender": ["male","family"],
     *     "time_ranges": [
     *        {"start":"00:00","end":"23:59"}
     *     ],
     *     "all_hours": true
     *   },
     *   ...
     * ]
     */
    protected function syncTimeRestrictionsForArea(int $areaId, array $timeRestrictions): void
    {
        DB::table('access_time_restrictions')
            ->where('entity_table', 'areas')
            ->where('entity_id', $areaId)
            ->delete();

        foreach ($timeRestrictions as $tr) {
            $genderArray = $tr['gender'] ?? null;
            $dateScope   = $tr['date_scope'] ?? null;
            $timeRanges  = $tr['time_ranges'] ?? [];
            $allHours    = $tr['all_hours'] ?? false;

            if (is_array($genderArray)) {
                $genderArray = array_map(
                    static function ($value) {
                        $value = strtolower(
                            trim((string) $value)
                        );

                        return $value === 'family'
                            ? 'both'
                            : $value;
                    },
                    $genderArray
                );
                $genderArray = array_values(
                    array_unique(
                        array_filter(
                            $genderArray,
                            static fn($value) =>
                            in_array(
                                $value,
                                ['male', 'female', 'both'],
                                true
                            )
                        )
                    )
                );
            }

            // آماده‌سازی آرایه‌ها برای Postgres
            $genderLiteral = null;
            if (is_array($genderArray) && count($genderArray) > 0) {
                // {male,female} → gender_enum[]
                $escaped = array_map(
                    fn($g) => '"' . str_replace('"', '\"', $g) . '"',
                    $genderArray
                );
                $genderLiteral = '{' . implode(',', $escaped) . '}';
            }



            $dateScopeLiteral = null;
            if (is_array($dateScope) && count($dateScope) > 0) {
                $escaped = array_map(
                    fn($d) => '"' . str_replace('"', '\"', $d) . '"',
                    $dateScope
                );
                $dateScopeLiteral = '{' . implode(',', $escaped) . '}';
            }

            // اگر time_ranges خالی بود، حداقل یک ردیف بدون start/end هم می‌توانیم بزنیم
            if (empty($timeRanges)) {
                DB::statement(
                    '
                    INSERT INTO access_time_restrictions (
                        entity_table, entity_id,
                        gender, date_scope, specific_date,
                        start_time, end_time, all_hours, attrs
                    )
                    VALUES (
                        ?, ?,
                        ' . ($genderLiteral ? 'CAST(? AS gender_enum[])' : 'NULL') . ',
                        ' . ($dateScopeLiteral ? 'CAST(? AS text[])' : 'NULL') . ',
                        NULL,
                        NULL, NULL,
                        ?,
                        ?::jsonb
                    )',
                    array_values(
                        array_filter([
                            'areas',
                            $areaId,
                            $genderLiteral,
                            $dateScopeLiteral,
                            (bool)$allHours,
                            json_encode($tr),
                        ], fn($v) => true)
                    )
                );
                continue;
            }

            // برای هر بازه‌ی زمانی یک ردیف بساز
            foreach ($timeRanges as $rng) {
                $start = $rng['start'] ?? null;
                $end   = $rng['end']   ?? null;

                DB::statement(
                    '
                    INSERT INTO access_time_restrictions (
                        entity_table, entity_id,
                        gender, date_scope, specific_date,
                        start_time, end_time, all_hours, attrs
                    )
                    VALUES (
                        ?, ?,
                        ' . ($genderLiteral ? 'CAST(? AS gender_enum[])' : 'NULL') . ',
                        ' . ($dateScopeLiteral ? 'CAST(? AS text[])' : 'NULL') . ',
                        NULL,
                        ' . ($start ? '?::time' : 'NULL') . ',
                        ' . ($end   ? '?::time' : 'NULL') . ',
                        ?,
                        ?::jsonb
                    )',
                    array_values(
                        array_filter([
                            'areas',
                            $areaId,
                            $genderLiteral,
                            $dateScopeLiteral,
                            $start,
                            $end,
                            (bool)$allHours,
                            json_encode($tr),
                        ], fn($v) => true)
                    )
                );
            }
        }
    }

    /**
     * تمام ردیف‌های access_prayer_restrictions برای این area را پاک کرده
     * و از روی آرایه‌ی prayer_restrictions دوباره درج می‌کند.
     *
     * ساختار prayer_restrictions:
     * [
     *   {
     *     "events": ["نماز صبح","نماز مغرب و عشاء"],
     *     "before_minutes": 25,
     *     "after_minutes": 26,
     *     "date": "روز 2 فروردین 1403"
     *   },
     *   ...
     * ]
     *
     * فعلاً specific_date را NULL می‌گذاریم و کل رکورد JSON را در attrs نگه می‌داریم؛
     * اگر بعداً تاریخ شمسی/میلادی استاندارد شد، می‌توانی اینجا parse کنی.
     */
    protected function syncPrayerRestrictionsForArea(int $areaId, array $prayerRestrictions): void
    {
        DB::table('access_prayer_restrictions')
            ->where('entity_table', 'areas')
            ->where('entity_id', $areaId)
            ->delete();

        foreach ($prayerRestrictions as $pr) {
            $events         = $pr['events'] ?? [];
            $beforeMinutes  = $pr['before_minutes'] ?? 0;
            $afterMinutes   = $pr['after_minutes'] ?? 0;
            $genderArray    = $pr['gender'] ?? null;

            if (is_array($genderArray)) {
                $genderArray = array_map(
                    static function ($value) {
                        $value = strtolower(
                            trim((string) $value)
                        );

                        return $value === 'family'
                            ? 'both'
                            : $value;
                    },
                    $genderArray
                );

                $genderArray = array_values(
                    array_unique(
                        array_filter(
                            $genderArray,
                            static fn($value) =>
                            in_array(
                                $value,
                                ['male', 'female', 'both'],
                                true
                            )
                        )
                    )
                );
            }

            $genderLiteral = null;
            if (is_array($genderArray) && count($genderArray) > 0) {
                $escaped = array_map(
                    fn($g) => '"' . str_replace('"', '\"', $g) . '"',
                    $genderArray
                );
                $genderLiteral = '{' . implode(',', $escaped) . '}';
            }

            if (empty($events)) {
                continue;
            }

            foreach ($events as $eventName) {
                DB::statement(
                    '
                    INSERT INTO access_prayer_restrictions (
                        entity_table, entity_id,
                        prayer_event,
                        before_minutes, after_minutes,
                        specific_date,
                        gender,
                        attrs
                    )
                    VALUES (
                        ?, ?,
                        ?,
                        ?, ?,
                        NULL,
                        ' . ($genderLiteral ? 'CAST(? AS gender_enum[])' : 'NULL') . ',
                        ?::jsonb
                    )',
                    array_values(
                        array_filter([
                            'areas',
                            $areaId,
                            $eventName,
                            (int)$beforeMinutes,
                            (int)$afterMinutes,
                            $genderLiteral,
                            json_encode($pr),
                        ], fn($v) => !is_null($v))
                    )
                );
            }
        }
    }
}
