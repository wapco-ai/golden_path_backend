<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Cultural Items API (backed by contents + poi_points)
 *
 * Notes:
 * - "Cultural item" == all translations (fa/en/ar/ur) of contents for the same poi_id
 * - Media is stored in contents.media (jsonb) per language
 * - Visibility toggles + placeType are stored in poi_points.attrs->cultural
 * - addressInShrine + grouping are stored in poi_points.attrs
 * - POI location update (lat/lng) updates poi_points.geom (assumes geom SRID=32640; input lat/lng is EPSG:4326)
 * - Time restrictions are stored in access_time_restrictions / access_prayer_restrictions
 */
class CulturalItemsController extends Controller
{
    // ------------------------------------------------------------
    // List
    // ------------------------------------------------------------
    public function index(Request $request)
    {
        $page     = max((int) $request->input('page', 1), 1);
        $pageSize = (int) $request->input('pageSize', 10);
        $pageSize = max(min($pageSize, 100), 1);
        $search   = trim((string) $request->input('search', ''));

        // We page by POI that has ANY content (typically has_content=true)
        $poiQuery = DB::table('poi_points as p');
        // ->where('p.has_content', true);

        // Optional: search across contents title/body OR i18n_texts(name)
        if ($search !== '') {
            $like = '%' . $search . '%';

            $poiQuery->where(function ($w) use ($like) {
                // Search in contents (title/body)
                $w->whereExists(function ($q) use ($like) {
                    $q->select(DB::raw('1'))
                        ->from('contents as c')
                        ->whereColumn('c.poi_id', 'p.id')
                        ->where(function ($qq) use ($like) {
                            $qq->where('c.title', 'ILIKE', $like)
                                ->orWhere('c.body', 'ILIKE', $like);
                        });
                })
                    // OR search in i18n_texts (poi_points.name)
                    ->orWhereExists(function ($q) use ($like) {
                        $q->select(DB::raw('1'))
                            ->from('i18n_texts as t')
                            ->where('t.entity_table', 'poi_points')
                            ->whereColumn('t.entity_id', 'p.id')
                            ->where('t.field', 'name')
                            ->where('t.txt', 'ILIKE', $like);
                    });
            });
        }

        $total = (clone $poiQuery)->count();

        $pois = $poiQuery
            ->orderByDesc('p.id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->select([
                'p.id as poi_id',
                'p.floor',
                'p.attrs',
                DB::raw('ST_X(p.geom) as x'),
                DB::raw('ST_Y(p.geom) as y'),
                DB::raw('ST_Y(ST_Transform(p.geom, 4326)) as lat'),
                DB::raw('ST_X(ST_Transform(p.geom, 4326)) as lng'),
            ])
            ->get();

        $poiIds = $pois->pluck('poi_id')->values()->all();

        // Fetch POI names from i18n_texts (poi_points.name) for the same page (avoid N+1)
        $i18nRows = collect();
        if (!empty($poiIds)) {
            $i18nRows = DB::table('i18n_texts')
                ->where('entity_table', 'poi_points')
                ->whereIn('entity_id', $poiIds)
                ->where('field', 'name')
                ->whereIn('lang', ['fa', 'en', 'ar', 'ur'])
                ->select(['entity_id', 'lang', 'txt'])
                ->get();
        }
        $i18nByPoi = $i18nRows->groupBy('entity_id');

        $contents = collect();
        if (!empty($poiIds)) {
            $contents = DB::table('contents')
                ->whereIn('poi_id', $poiIds)
                ->whereIn('lang', ['fa', 'en', 'ar', 'ur'])
                ->select(['poi_id', 'lang', 'title', 'body', 'media'])
                ->get();
        }

        $byPoi = $contents->groupBy('poi_id');

        $items = $pois->map(function ($p) use ($byPoi, $i18nByPoi) {
            $rows = $byPoi->get($p->poi_id, collect());

            $titles = ['fa' => null, 'en' => null, 'ar' => null, 'ur' => null];
            $descs  = ['fa' => null, 'en' => null, 'ar' => null, 'ur' => null];

            $primaryImage = null;
            $mediaPreview = [];

            foreach (['fa', 'en', 'ar', 'ur'] as $lang) {
                $r = $rows->firstWhere('lang', $lang);
                if (!$r) continue;

                $titles[$lang] = $r->title;
                $descs[$lang]  = $r->body;

                if ($primaryImage === null) {
                    $m = $this->jsonToArray($r->media);
                    if (is_array($m) && count($m) > 0) {
                        $primaryImage = $m[0]['url'] ?? $m[0]['src'] ?? null;
                        $mediaPreview = $m;
                    }
                }
            }

            // Fill/override titles from i18n_texts (poi_points.name)
            $i18n = $i18nByPoi->get($p->poi_id, collect());
            foreach (['fa', 'en', 'ar', 'ur'] as $lang) {
                $txt = $i18n->firstWhere('lang', $lang)->txt ?? null;
                $txt = is_string($txt) ? trim($txt) : null;
                if ($txt !== null && $txt !== '') {
                    $titles[$lang] = $txt;
                }
            }

            $attrs = $this->jsonToArray($p->attrs) ?: [];
            $cultural = $attrs['cultural'] ?? [];
            $grouping = $attrs['grouping'] ?? null;

            return [
                'poiId' => (int) $p->poi_id,

                'title'       => $titles['fa'],
                'description' => $descs['fa'],

                'titles'       => $titles,
                'descriptions' => $descs,

                'primaryImage' => $primaryImage,
                'media'        => $mediaPreview,

                'addressInShrine' => $attrs['address_in_shrine'] ?? null,
                'grouping'        => $grouping,

                'showUserFeedbacks' => isset($cultural['showUserFeedbacks']) ? (bool) $cultural['showUserFeedbacks'] : true,
                'showMediaGallery'  => isset($cultural['showMediaGallery'])  ? (bool) $cultural['showMediaGallery']  : true,
                'placeType'         => $cultural['placeType'] ?? null,

                'location' => [
                    'x'     => $p->x !== null ? (float) $p->x : null,
                    'y'     => $p->y !== null ? (float) $p->y : null,
                    'lat'   => $p->lat !== null ? (float) $p->lat : null,
                    'lng'   => $p->lng !== null ? (float) $p->lng : null,
                    'floor' => $p->floor !== null ? (int) $p->floor : null,
                ],
            ];
        });

        return response()->json([
            'items'      => $items,
            'totalItems' => $total,
            'page'       => $page,
            'pageSize'   => $pageSize,
        ]);
    }

    // ------------------------------------------------------------
    // Details
    // ------------------------------------------------------------
    public function show(Request $request, $poiId)
    {
        $poiId = (int) $poiId;
        if ($poiId <= 0) {
            return response()->json(['message' => 'Invalid poiId'], 422);
        }

        $poi = DB::table('poi_points as p')
            ->where('p.id', $poiId)
            ->select([
                'p.id as poi_id',
                'p.floor',
                'p.attrs',
                DB::raw('ST_X(p.geom) as x'),
                DB::raw('ST_Y(p.geom) as y'),
                DB::raw('ST_Y(ST_Transform(p.geom, 4326)) as lat'),
                DB::raw('ST_X(ST_Transform(p.geom, 4326)) as lng'),
            ])
            ->first();

        if (!$poi) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $rows = DB::table('contents')
            ->where('poi_id', $poiId)
            ->whereIn('lang', ['fa', 'en', 'ar', 'ur'])
            ->select(['lang', 'title', 'body', 'media'])
            ->get();

        $titles = ['fa' => null, 'en' => null, 'ar' => null, 'ur' => null];
        $descs  = ['fa' => null, 'en' => null, 'ar' => null, 'ur' => null];
        $mediaByLang = ['fa' => [], 'en' => [], 'ar' => [], 'ur' => []];

        foreach (['fa', 'en', 'ar', 'ur'] as $lang) {
            $r = $rows->firstWhere('lang', $lang);
            if (!$r) continue;

            $titles[$lang] = $r->title;
            $descs[$lang]  = $r->body;
            $mediaByLang[$lang] = $this->jsonToArray($r->media) ?: [];
        }


        // Fill/override titles from i18n_texts (entity_table=poi_points, field=name)
        $i18nRows = DB::table('i18n_texts')
            ->where('entity_table', 'poi_points')
            ->where('entity_id', $poiId)
            ->where('field', 'name')
            ->whereIn('lang', ['fa', 'en', 'ar', 'ur'])
            ->select(['lang', 'txt'])
            ->get();

        foreach (['fa', 'en', 'ar', 'ur'] as $lang) {
            $txt = $i18nRows->firstWhere('lang', $lang)->txt ?? null;
            $txt = is_string($txt) ? trim($txt) : null;

            if ($txt !== null && $txt !== '') {
                $titles[$lang] = $txt;
            }
        }

        $attrs = $this->jsonToArray($poi->attrs) ?: [];
        $cultural = $attrs['cultural'] ?? [];

        $timeRestrictions = DB::table('access_time_restrictions')
            ->where('entity_table', 'poi_points')
            ->where('entity_id', $poiId)
            ->orderBy('id')
            ->pluck('attrs')
            ->map(fn($j) => $this->jsonToArray($j))
            ->filter()
            ->values()
            ->all();

        $prayerRestrictions = DB::table('access_prayer_restrictions')
            ->where('entity_table', 'poi_points')
            ->where('entity_id', $poiId)
            ->orderBy('id')
            ->pluck('attrs')
            ->map(fn($j) => $this->jsonToArray($j))
            ->filter()
            ->values()
            ->all();

        return response()->json([
            'poiId' => (int) $poi->poi_id,
            'titles' => $titles,
            'descriptions' => $descs,
            'media' => $mediaByLang,

            'addressInShrine' => $attrs['address_in_shrine'] ?? null,
            'grouping'        => $attrs['grouping'] ?? null,

            'showUserFeedbacks' => isset($cultural['showUserFeedbacks']) ? (bool) $cultural['showUserFeedbacks'] : true,
            'showMediaGallery'  => isset($cultural['showMediaGallery'])  ? (bool) $cultural['showMediaGallery']  : true,
            'placeType'         => $cultural['placeType'] ?? null,

            'location' => [
                'x'     => $poi->x !== null ? (float) $poi->x : null,
                'y'     => $poi->y !== null ? (float) $poi->y : null,
                'lat'   => $poi->lat !== null ? (float) $poi->lat : null,
                'lng'   => $poi->lng !== null ? (float) $poi->lng : null,
                'floor' => $poi->floor !== null ? (int) $poi->floor : null,
            ],

            'time_restrictions'   => $timeRestrictions,
            'prayer_restrictions' => $prayerRestrictions,
        ]);
    }

    // ------------------------------------------------------------
    // Create / Update
    // ------------------------------------------------------------
    public function store(Request $request)
    {
        $validated = $this->validateUpsert($request);

        DB::transaction(function () use ($validated, &$poiId) {

            // سناریوی B: اگر poi_id نیامده، POI جدید بساز
            if (!isset($validated['poi_id'])) {
                $poiId = $this->createPoiPointFromPayload($validated['poi'] ?? []);
            } else {
                $poiId = (int) $validated['poi_id'];
            }

            // بقیه مثل قبل
            $this->updatePoiBasicMeta($poiId, $validated); // اگر خواستی همچنان addressInShrine/grouping/location بیرون poi هم بیاد
            $this->upsertTranslations($poiId, $validated['translations']);
            $this->upsertPoiI18nName($poiId, $validated['translations']);

            $settings = $validated['settings'] ?? [];
            if (!isset($settings['placeType']) && isset($validated['placeType'])) {
                $settings['placeType'] = $validated['placeType'];
            }
            $this->updatePoiCulturalMeta($poiId, $settings);

            $this->syncAccessTimeRestrictions('poi_points', $poiId, $validated['time_restrictions'] ?? []);
            $this->syncAccessPrayerRestrictions('poi_points', $poiId, $validated['prayer_restrictions'] ?? []);

            $hasContent = $this->computeHasCulturalContent($validated['translations'] ?? []);
            DB::table('poi_points')->where('id', $poiId)->update(['has_content' => $hasContent]);
        });

        return $this->show($request, $poiId);
    }


    public function update(Request $request, $poiId)
    {
        $poiId = (int) $poiId;
        if ($poiId <= 0) return response()->json(['message' => 'Invalid poiId'], 422);

        $validated = $this->validateUpsert($request, true);

        DB::transaction(function () use ($validated, $poiId) {
            $this->updatePoiBasicMeta($poiId, $validated);

            if (isset($validated['translations'])) {
                $this->upsertTranslations($poiId, $validated['translations'], true);
                $this->upsertPoiI18nName($poiId, $validated['translations']);
            }

            if (isset($validated['settings']) || isset($validated['placeType'])) {
                $settings = $validated['settings'] ?? [];
                if (!isset($settings['placeType']) && isset($validated['placeType'])) {
                    $settings['placeType'] = $validated['placeType'];
                }
                $this->updatePoiCulturalMeta($poiId, $settings);
            }

            if (array_key_exists('time_restrictions', $validated)) {
                $this->syncAccessTimeRestrictions('poi_points', $poiId, $validated['time_restrictions'] ?? []);
            }
            if (array_key_exists('prayer_restrictions', $validated)) {
                $this->syncAccessPrayerRestrictions('poi_points', $poiId, $validated['prayer_restrictions'] ?? []);
            }

            $hasContent = $this->computeHasCulturalContent($validated['translations'] ?? []);
            DB::table('poi_points')->where('id', $poiId)->update(['has_content' => $hasContent]);
        });

        return $this->show($request, $poiId);
    }

    public function destroy(Request $request, $poiId)
    {
        $poiId = (int) $poiId;
        if ($poiId <= 0) {
            return response()->json(['message' => 'Invalid poiId'], 422);
        }

        DB::transaction(function () use ($poiId) {
            DB::table('contents')->where('poi_id', $poiId)->delete();

            DB::table('i18n_texts')
                ->where('entity_table', 'poi_points')
                ->where('entity_id', $poiId)
                ->delete();

            DB::table('access_time_restrictions')
                ->where('entity_table', 'poi_points')
                ->where('entity_id', $poiId)
                ->delete();

            DB::table('access_prayer_restrictions')
                ->where('entity_table', 'poi_points')
                ->where('entity_id', $poiId)
                ->delete();

            DB::table('poi_points')->where('id', $poiId)->delete();
        });

        return response()->json(['success' => true]);
    }
    // ------------------------------------------------------------
    // Validation + helpers
    // ------------------------------------------------------------
    protected function validateUpsert(Request $request, bool $isUpdate = false): array
    {
        $placeTypes = ['ziyarati', 'farhangi', 'khadamati', 'tarikhi', 'memari'];
        $poiTypes = ['elevator', 'other', 'sahn', 'eyvan', 'ravaq', 'masjed', 'madrese', 'khadamat', 'elmi', 'cemetery'];

        $rules = [
            'poi_id' => [$isUpdate ? 'sometimes' : 'nullable', 'integer', 'exists:poi_points,id'],

            'addressInShrine' => ['sometimes', 'nullable', 'string'],
            'grouping' => ['sometimes', 'nullable', 'array'],
            'grouping.group_id' => ['nullable'],
            'grouping.sub_group_id' => ['nullable'],
            'grouping.sub_group_label' => ['nullable'],

            'location' => ['sometimes', 'nullable', 'array'],
            'location.lat' => ['required_with:location', 'numeric'],
            'location.lng' => ['required_with:location', 'numeric'],
            'placeType' => ['sometimes', 'string', Rule::in($placeTypes)],

            'floor' => ['sometimes', 'nullable', 'integer'],

            'translations' => [$isUpdate ? 'sometimes' : 'required', 'array'],
            'translations.fa.title' => ['nullable', 'string'],
            'translations.fa.body'  => ['nullable', 'string'],
            'translations.fa.media' => ['sometimes', 'array'],
            'translations.en.title' => ['nullable', 'string'],
            'translations.en.body'  => ['nullable', 'string'],
            'translations.en.media' => ['sometimes', 'array'],
            'translations.ar.title' => ['nullable', 'string'],
            'translations.ar.body'  => ['nullable', 'string'],
            'translations.ar.media' => ['sometimes', 'array'],
            'translations.ur.title' => ['nullable', 'string'],
            'translations.ur.body'  => ['nullable', 'string'],
            'translations.ur.media' => ['sometimes', 'array'],

            'settings' => ['sometimes', 'array'],
            'settings.showUserFeedbacks' => ['sometimes', 'boolean'],
            'settings.showMediaGallery'  => ['sometimes', 'boolean'],
            'settings.placeType'         => ['sometimes', 'string', Rule::in($placeTypes)],

            'time_restrictions' => ['sometimes', 'array'],
            'prayer_restrictions' => ['sometimes', 'array'],


            'poi' => [$isUpdate ? 'sometimes' : 'required_without:poi_id', 'array'],

            'poi.category_leaf_id' => ['sometimes', 'nullable', 'integer'], // اگر ستونش را داری

            'poi.location' => ['required_without:poi_id', 'array'],
            'poi.location.lat' => ['required_without:poi_id', 'numeric'],
            'poi.location.lng' => ['required_without:poi_id', 'numeric'],

            'poi.addressInShrine' => ['sometimes', 'nullable', 'string'],
            'poi.grouping' => ['sometimes', 'nullable', 'array'],

            // داخل $rules
            'poi.grouping.group_id' => [$isUpdate ? 'sometimes' : 'required_without:poi_id', 'integer', 'exists:categories,id'],
            'poi.grouping.sub_group_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'poi.grouping.sub_group_label' => ['nullable'],
            'poi.category_leaf_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],

            // اگر grouping ریشه‌ای هم می‌خواهی معتبر باشد:
            'grouping.group_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'grouping.sub_group_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],


            'poi.placeType' => ['sometimes', 'string', Rule::in($placeTypes)],
            'poi.grouping' => [$isUpdate ? 'sometimes' : 'sometimes', 'array'],

        ];

        $validated = $request->validate($rules);
        $g = data_get($validated, 'poi.grouping.group_id');
        $sg = data_get($validated, 'poi.grouping.sub_group_id');

        if ($g && $sg) {
            $ok = DB::table('categories')->where('id', $sg)->where('parent_id', $g)->exists();
            if (!$ok) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'poi.grouping.sub_group_id' => ['sub_group_id must be a child of group_id in categories.'],
                ]);
            }
        }

        return $validated;
    }

    protected function upsertTranslations(int $poiId, array $translations, bool $isUpdate = false): void
    {
        foreach (['fa', 'en', 'ar', 'ur'] as $lang) {
            if (!isset($translations[$lang])) continue;

            $payload = $translations[$lang];

            $update = [
                'title' => $payload['title'] ?? null,
                'body'  => $payload['body'] ?? null,
            ];

            if (array_key_exists('media', $payload)) {
                $update['media'] = json_encode($payload['media'] ?? [], JSON_UNESCAPED_UNICODE);
            }

            $insert = $update;
            if (!array_key_exists('media', $insert)) {
                $insert['media'] = json_encode([], JSON_UNESCAPED_UNICODE);
            }

            DB::table('contents')->updateOrInsert(
                ['poi_id' => $poiId, 'lang' => $lang],
                $isUpdate ? $update : $insert
            );
        }
    }


    /**
     * Upsert POI name translations into i18n_texts (entity_table=poi_points, field=name).
     * This keeps "name" available even when there is no cultural content (no rows in contents).
     */
    protected function upsertPoiI18nName(int $poiId, array $translations): void
    {
        foreach (['fa', 'en', 'ar', 'ur'] as $lang) {
            if (!isset($translations[$lang]) || !is_array($translations[$lang])) {
                continue;
            }

            $txt = $translations[$lang]['title'] ?? null;
            $txt = is_string($txt) ? trim($txt) : '';

            // Do not delete existing i18n values when an empty string is sent.
            if ($txt === '') {
                continue;
            }

            DB::table('i18n_texts')->updateOrInsert(
                [
                    'entity_table' => 'poi_points',
                    'entity_id'    => $poiId,
                    'field'        => 'name',
                    'lang'         => $lang,
                ],
                [
                    'txt' => $txt,
                ]
            );
        }
    }

    /**
     * Determine whether payload contains actual cultural content (body or media in any language).
     * Used to keep poi_points.has_content consistent.
     */
    protected function computeHasCulturalContent(array $translations): bool
    {
        foreach (['fa', 'en', 'ar', 'ur'] as $lang) {
            if (!isset($translations[$lang]) || !is_array($translations[$lang])) {
                continue;
            }

            $body = $translations[$lang]['body'] ?? '';
            if (is_string($body) && trim($body) !== '') {
                return true;
            }

            if (array_key_exists('media', $translations[$lang])) {
                $media = $translations[$lang]['media'];
                if (is_array($media) && count($media) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function updatePoiBasicMeta(int $poiId, array $payload): void
    {
        // ---- 1) attrs + category_leaf_id (addressInShrine + grouping) ----
        if (array_key_exists('addressInShrine', $payload) || array_key_exists('grouping', $payload)) {
            $poi = DB::table('poi_points')->select('attrs')->where('id', $poiId)->first();
            if ($poi) {
                $attrs = $this->jsonToArray($poi->attrs) ?: [];

                if (array_key_exists('addressInShrine', $payload)) {
                    $attrs['address_in_shrine'] = $payload['addressInShrine'];
                }

                $updates = [
                    'attrs' => json_encode($attrs, JSON_UNESCAPED_UNICODE),
                ];

                if (array_key_exists('grouping', $payload)) {
                    $attrs['grouping'] = $payload['grouping'];
                    $updates['attrs'] = json_encode($attrs, JSON_UNESCAPED_UNICODE);

                    // اگر sub_group_id ارسال شد، category_leaf_id هم بروز شود
                    $subGroupId = data_get($payload, 'grouping.sub_group_id');
                    if ($subGroupId !== null) {
                        $updates['category_leaf_id'] = (int) $subGroupId;
                    }
                }

                // ✅ مهم: دیگر return نداریم تا آپدیت مختصات هم انجام شود
                DB::table('poi_points')->where('id', $poiId)->update($updates);
            }
        }

        // ---- 2) floor update ----
        if (array_key_exists('floor', $payload)) {
            DB::table('poi_points')->where('id', $poiId)->update([
                'floor' => (int) $payload['floor'],
            ]);
        }

        // ---- 3) geom update (location) ----
        if (isset($payload['location']) && is_array($payload['location'])) {
            $lat = $payload['location']['lat'] ?? null;
            $lng = $payload['location']['lng'] ?? null;

            if (is_numeric($lat) && is_numeric($lng)) {
                DB::statement(
                    "UPDATE poi_points
                 SET geom = ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), 4326), 32640)
                 WHERE id = ?",
                    [(float)$lng, (float)$lat, $poiId]
                );
            }
        }
    }

    protected function updatePoiCulturalMeta(int $poiId, array $settings): void
    {
        $poi = DB::table('poi_points')->select('attrs')->where('id', $poiId)->first();
        if (!$poi) return;

        $attrs = $this->jsonToArray($poi->attrs) ?: [];
        $attrs['cultural'] = $attrs['cultural'] ?? [];

        if (array_key_exists('showUserFeedbacks', $settings)) $attrs['cultural']['showUserFeedbacks'] = (bool)$settings['showUserFeedbacks'];
        if (array_key_exists('showMediaGallery', $settings))  $attrs['cultural']['showMediaGallery']  = (bool)$settings['showMediaGallery'];
        if (array_key_exists('placeType', $settings))         $attrs['cultural']['placeType']         = $settings['placeType'];

        DB::table('poi_points')->where('id', $poiId)->update([
            'attrs' => json_encode($attrs, JSON_UNESCAPED_UNICODE),
        ]);
    }

    // -------------------- Postgres array helpers --------------------
    protected function mapGender($g): ?string
    {
        if ($g === null) return null;
        $g = trim((string)$g);

        return match ($g) {
            'male', 'مرد', 'مردانه' => 'male',
            'female', 'زن', 'زنانه' => 'female',
            'both', 'همه', 'بدون محدودیت' => 'both',
            default => null,
        };
    }

    protected function toPgTextArrayLiteral(?array $arr): ?string
    {
        if (!$arr || !is_array($arr) || count($arr) === 0) return null;

        $escaped = array_map(function ($v) {
            $v = (string)$v;
            $v = str_replace(['\\', '"'], ['\\\\', '\"'], $v);
            return '"' . $v . '"';
        }, $arr);

        return '{' . implode(',', $escaped) . '}';
    }

    protected function toPgGenderEnumArrayLiteral($gender): ?string
    {
        if ($gender === null) return null;

        if (is_string($gender)) $gender = [$gender];
        if (!is_array($gender) || count($gender) === 0) return null;

        $mapped = [];
        foreach ($gender as $g) {
            $m = $this->mapGender($g);
            if ($m) $mapped[] = $m;
        }

        $mapped = array_values(array_unique($mapped));
        if (count($mapped) === 0) return null;

        return '{' . implode(',', $mapped) . '}';
    }

    protected function mapPrayerEvent(string $label): string
    {
        $label = trim($label);

        return match ($label) {
            'fajr', 'Fajr', 'نماز صبح' => 'fajr',
            'dhuhr_asr', 'DhuhrAsr', 'نماز ظهر و عصر' => 'dhuhr_asr',
            'maghrib_isha', 'MaghribIsha', 'نماز مغرب و عشاء' => 'maghrib_isha',
            default => $label,
        };
    }

    protected function syncAccessTimeRestrictions(string $entityTable, int $entityId, array $items): void
    {
        DB::table('access_time_restrictions')
            ->where('entity_table', $entityTable)
            ->where('entity_id', $entityId)
            ->delete();

        $now = now();

        foreach ($items as $item) {
            if (!is_array($item)) continue;

            $allHours = (bool)($item['all_hours'] ?? false);

            $genderLiteral = $this->toPgGenderEnumArrayLiteral($item['gender'] ?? null);

            $dateScope = $item['date_scope'] ?? null;
            if (is_string($dateScope)) $dateScope = [$dateScope];
            $dateScopeLiteral = $this->toPgTextArrayLiteral(is_array($dateScope) ? $dateScope : null);

            $specificDate = $item['date'] ?? null;

            $timeRanges = $item['time_ranges'] ?? [];
            if ($allHours || empty($timeRanges) || !is_array($timeRanges)) {
                $timeRanges = [['start' => null, 'end' => null]];
            }

            foreach ($timeRanges as $tr) {
                $start = $allHours ? null : ($tr['start'] ?? null);
                $end   = $allHours ? null : ($tr['end'] ?? null);

                DB::insert(
                    "INSERT INTO access_time_restrictions
                     (entity_table, entity_id, gender, date_scope, specific_date, start_time, end_time, all_hours, attrs, created_at, updated_at)
                     VALUES
                     (?, ?, ?::gender_enum[], ?::text[], ?, ?, ?, ?, ?::jsonb, ?, ?)",
                    [
                        $entityTable,
                        $entityId,
                        $genderLiteral,
                        $dateScopeLiteral,
                        $specificDate,
                        $start,
                        $end,
                        $allHours ? 1 : 0,
                        json_encode($item, JSON_UNESCAPED_UNICODE),
                        $now,
                        $now,
                    ]
                );
            }
        }
    }

    protected function syncAccessPrayerRestrictions(string $entityTable, int $entityId, array $items): void
    {
        DB::table('access_prayer_restrictions')
            ->where('entity_table', $entityTable)
            ->where('entity_id', $entityId)
            ->delete();

        $now = now();

        foreach ($items as $item) {
            if (!is_array($item)) continue;

            $events = $item['events'] ?? [];
            if (is_string($events)) $events = [$events];
            if (!is_array($events)) $events = [];

            $before = (int)($item['before_minutes'] ?? 0);
            $after  = (int)($item['after_minutes'] ?? 0);

            $specificDate = $item['date'] ?? null;

            $genderLiteral = $this->toPgGenderEnumArrayLiteral($item['gender'] ?? null);

            foreach ($events as $event) {
                if (!is_string($event) || $event === '') continue;

                $eventCode = $this->mapPrayerEvent($event);

                DB::insert(
                    "INSERT INTO access_prayer_restrictions
                     (entity_table, entity_id, prayer_event, before_minutes, after_minutes, specific_date, gender, attrs, created_at, updated_at)
                     VALUES
                     (?, ?, ?, ?, ?, ?, ?::gender_enum[], ?::jsonb, ?, ?)",
                    [
                        $entityTable,
                        $entityId,
                        $eventCode,
                        $before,
                        $after,
                        $specificDate,
                        $genderLiteral,
                        json_encode($item, JSON_UNESCAPED_UNICODE),
                        $now,
                        $now,
                    ]
                );
            }
        }
    }

    protected function jsonToArray($value): ?array
    {
        if ($value === null) return null;
        if (is_array($value)) return $value;
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : null;
        }
        if (is_object($value)) {
            $decoded = json_decode(json_encode($value), true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    protected function createPoiPointFromPayload(array $poi): int
    {
        $floor = $poi['floor'] ?? 0;

        $lat = $poi['location']['lat'] ?? null;
        $lng = $poi['location']['lng'] ?? null;

        if (!is_numeric($lat) || !is_numeric($lng)) {
            throw new \InvalidArgumentException("poi.location.lat/lng is required for create");
        }

        // attrs اولیه
        $attrs = [];

        if (array_key_exists('addressInShrine', $poi)) {
            $attrs['address_in_shrine'] = $poi['addressInShrine'];
        }
        if (array_key_exists('grouping', $poi)) {
            $attrs['grouping'] = $poi['grouping'];
        }
        if (isset($poi['placeType'])) {
            $attrs['cultural'] = $attrs['cultural'] ?? [];
            $attrs['cultural']['placeType'] = $poi['placeType'];
        }

        $placeType = $poi['placeType'] ?? 'other';


        $insert = [
            'floor' => (int)$floor,
            'poi_type' => 'other',  // ✅ از group_id یا other
            'has_content' => false,
            'attrs' => json_encode($attrs, JSON_UNESCAPED_UNICODE),
            'geom' => DB::raw(
                'ST_Transform(ST_SetSRID(ST_MakePoint(' . (float)$lng . ', ' . (float)$lat . '), 4326), 32640)'
            ),
        ];


        // اگر ستون category_leaf_id داری
        $subGroupId = data_get($poi, 'grouping.sub_group_id');
        $leafId = $subGroupId ?? ($poi['category_leaf_id'] ?? null);

        if ($leafId !== null) {
            $insert['category_leaf_id'] = (int) $leafId;
        }


        return (int) DB::table('poi_points')->insertGetId($insert);
    }
}
