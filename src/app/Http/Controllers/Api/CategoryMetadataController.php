<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryMetadataController extends Controller
{
    /**
     * GET /api/groups/metadata
     *
     * Query params:
     *  - language: fa|en|ar|ur (اختیاری، پیش‌فرض fa)
     *  - only: string (اختیاری، فیلتر روی value = code)
     *  - withPng: bool (اختیاری، پیش‌فرض true)
     */
    public function groupsMetadata(Request $request)
    {
        $supportedLanguages = ['fa', 'en', 'ar', 'ur'];

        $language = $request->query('language', 'fa');
        if (!in_array($language, $supportedLanguages, true)) {
            $language = 'fa';
        }

        $only    = $request->query('only');
        $withPng = $request->boolean('withPng', true);

        $iconBaseUrl = config('services.group_icons.base_url');

        $query = DB::table('categories')
            ->select('id', 'code', 'label_key', 'property_target', 'icon')
            ->where('level', 1)
            ->where('is_active', true)
            ->whereIn('property_target', ['group', 'nodeFunction'])
            ->orderBy('sort_order');

        if (!empty($only)) {
            $query->where('code', $only);
        }

        $rows = $query->get();

        // --- ترجمه‌ها از i18n_texts برای categories ---
        $categoryIds = $rows->pluck('id')->filter()->unique()->values()->all();
        $fieldPriority = ['name', 'label', 'title']; // اگر در دیتای شما field چیز دیگری است، همین‌جا اصلاح کنید

        $categoryLabelMap = []; // [category_id][lang] = ['field'=>..., 'txt'=>...]
        if (!empty($categoryIds)) {
            $raw = DB::table('i18n_texts')
                ->select('entity_id', 'lang', 'field', 'txt')
                ->where('entity_table', 'categories')
                ->whereIn('entity_id', $categoryIds)
                ->whereIn('lang', $supportedLanguages)
                ->whereIn('field', $fieldPriority)
                ->get();

            foreach ($raw as $t) {
                $cid   = (int) $t->entity_id;
                $lang  = (string) $t->lang;
                $field = (string) $t->field;
                $txt   = (string) $t->txt;

                if ($txt === '') continue;

                if (!isset($categoryLabelMap[$cid])) $categoryLabelMap[$cid] = [];

                if (!isset($categoryLabelMap[$cid][$lang])) {
                    $categoryLabelMap[$cid][$lang] = ['field' => $field, 'txt' => $txt];
                } else {
                    $prevField = $categoryLabelMap[$cid][$lang]['field'];
                    $prevRank  = array_search($prevField, $fieldPriority, true);
                    $newRank   = array_search($field, $fieldPriority, true);

                    if ($newRank !== false && ($prevRank === false || $newRank < $prevRank)) {
                        $categoryLabelMap[$cid][$lang] = ['field' => $field, 'txt' => $txt];
                    }
                }
            }
        }

        $groups = $rows->map(function ($row) use ($supportedLanguages, $withPng, $iconBaseUrl, $categoryLabelMap) {
            $cid = (int) $row->id;

            // اگر ترجمه نبود، fallback روی label_key
            $fallbackKey = $row->label_key;

            $label = [];
            foreach ($supportedLanguages as $lang) {
                $label[$lang] = $fallbackKey;
                if (isset($categoryLabelMap[$cid][$lang]['txt'])) {
                    $label[$lang] = $categoryLabelMap[$cid][$lang]['txt'];
                }
            }

            $item = [
                'value'         => $row->code,
                'label'         => $label,
                'label_key'      => $row->label_key,  // سازگاری با خروجی قبلی
                'propertyTarget' => $row->property_target,  // ✅ فیلد جدا طبق نیاز شما
                'icon'          => $row->icon,
            ];

            if ($withPng && !empty($iconBaseUrl) && !empty($row->icon)) {
                $item['png'] = rtrim($iconBaseUrl, '/') . '/' . $row->icon . '.png';
            }

            return $item;
        })->values();

        // اگر خروجی شما الان مثل نمونه‌تان "0": {...} است و wrapper نمی‌خواهید:
        // return response()->json($groups);

        return response()->json([
            'groups'      => $groups,
            'language'    => $language,
            'generatedAt' => now()->toIso8601String(),
        ]);
    }


    /**
     * GET /api/groups/subgroups
     *
     * Query params:
     *  - language: fa|en|ar|ur (اختیاری، پیش‌فرض fa)
     *  - group: string|array (برای فیلتر روی چند گروه خاص: group=sahn&group=eyvan)
     *  - search: string (جستجو روی label/address زبان انتخاب‌شده)
     *  - limit: int (پیش‌فرض 50، حداکثر 200)
     *  - offset: int (پیش‌فرض 0)
     *  - withImages: bool (اختیاری، پیش‌فرض true)
     *
     * خروجی مطابق subcategory_place_service.md:
     * {
     *   "subGroups": {
     *     "sahn": [
     *       { value, label{...}, description{...}, img[], address{...} },
     *       ...
     *     ],
     *     "eyvan": [ ... ]
     *   },
     *   "language": "fa",
     *   "generatedAt": "..."
     * }
     */
    public function subGroups(Request $request)
    {
        // 1) پارامترها
        $supportedLanguages = ['fa', 'en', 'ar', 'ur'];

        $language = $request->query('language', 'fa');
        if (!in_array($language, $supportedLanguages, true)) {
            $language = 'fa';
        }

        // group می‌تونه نیاد، یا یک مقدار، یا چندتا مقدار باشه
        $groupParam = $request->query('group'); // string | array | null

        if (is_null($groupParam)) {
            $groupCodes = [];
        } elseif (is_array($groupParam)) {
            // ?group=sahn&group=eyvan
            $groupCodes = array_values(array_filter($groupParam));
        } else {
            // ?group=sahn  یا ?group=sahn,eyvan
            $tmp        = array_map('trim', explode(',', $groupParam));
            $groupCodes = array_values(array_filter($tmp));
        }

        $search     = trim((string) $request->query('search', ''));
        $limit      = (int) $request->query('limit', 50);
        $offset     = (int) $request->query('offset', 0);
        $withImages = $request->boolean('withImages', true);

        if ($limit <= 0) {
            $limit = 50;
        }
        if ($limit > 200) {
            $limit = 200;
        }
        if ($offset < 0) {
            $offset = 0;
        }

        // 2) کوئری اصلی: دسته‌بندی از روی poi_points.category_leaf_id
        // p.category_leaf_id → c_leaf.id
        // c_leaf.parent_id → c_group.id (اگر leaf خودش group باشد، parent_id = null)
        $baseQuery = DB::table('poi_points as p')
            ->join('categories as c_leaf', 'c_leaf.id', '=', 'p.category_leaf_id')
            ->leftJoin('categories as c_group', 'c_group.id', '=', 'c_leaf.parent_id')
            ->whereNotNull('p.category_leaf_id')
            ->where('c_leaf.is_active', true)
            ->where(function ($q) {
                $q->whereNull('c_group.id')
                    ->orWhere('c_group.is_active', true);
            });

        // گروه مؤثر = اگر parent دارد، parent.code؛ در غیر این صورت خود leaf.code (مثل sahn, elevator, other, khadamat, .)
        $poiRows = $baseQuery
            ->select(
                'p.id as poi_id',
                // مقدار زیرگروه (value) از روی poi_type (enum)
                'p.poi_type as value',
                'p.floor as floor',
                // مختصات نقطه؛ فرض بر این است که geom در SRID=32640 است
                DB::raw('ST_Y(ST_Transform(p.geom, 4326)) as lat'),
                DB::raw('ST_X(ST_Transform(p.geom, 4326)) as lon'),
                DB::raw('COALESCE(c_group.code, c_leaf.code) as group_code'),
                DB::raw('COALESCE(c_group.sort_order, c_leaf.sort_order) as group_sort_order')
            )
            ->orderBy('group_sort_order')
            ->orderBy('value')
            ->get();

        // اگر group در کوئری آمده، در PHP فیلتر کن
        if (!empty($groupCodes)) {
            $poiRows = $poiRows->filter(function ($row) use ($groupCodes) {
                return in_array($row->group_code, $groupCodes, true);
            })->values();
        }

        if ($poiRows->isEmpty()) {
            return response()->json([
                'subGroups'   => (object) [],
                'language'    => $language,
                'generatedAt' => now()->toIso8601String(),
            ]);
        }

        $poiIds = $poiRows->pluck('poi_id')->unique()->values()->all();

        // 3) ترجمه‌ها از i18n_texts (name / description / address)
        $translations = DB::table('i18n_texts')
            ->where('entity_table', 'poi_points')
            ->whereIn('entity_id', $poiIds)
            ->whereIn('lang', $supportedLanguages)
            ->whereIn('field', ['name', 'description', 'address'])
            ->get();

        $byPoi = [];
        foreach ($poiRows as $row) {
            $byPoi[$row->poi_id] = [
                'poi_id'      => $row->poi_id,
                'value'       => $row->value,       // همون poi_type
                'group'       => $row->group_code,  // sahn, khadamat, elevator, other, ...
                'lat'         => $row->lat,
                'lon'         => $row->lon,
                'floor'       => $row->floor,
                'label'       => [],
                'description' => [],
                'address'     => [],
                'img'         => [],
            ];
        }

        foreach ($translations as $tr) {
            $pid = $tr->entity_id;
            if (!isset($byPoi[$pid])) {
                continue;
            }
            $lang = $tr->lang;
            $txt  = $tr->txt;

            switch ($tr->field) {
                case 'name':
                    $byPoi[$pid]['label'][$lang] = $txt;
                    break;
                case 'description':
                    $byPoi[$pid]['description'][$lang] = $txt;
                    break;
                case 'address':
                    $byPoi[$pid]['address'][$lang] = $txt;
                    break;
            }
        }

        // 4) عکس‌ها از contents
        if ($withImages) {
            $imageRows = DB::table('contents')
                ->whereIn('poi_id', $poiIds)
                ->where('lang', $language)
                ->get();

            foreach ($imageRows as $img) {
                $pid = $img->poi_id;
                if (!isset($byPoi[$pid])) {
                    continue;
                }

                $media = $img->media;

                if (is_string($media)) {
                    $mediaArr = json_decode($media, true) ?: [];
                } elseif (is_array($media)) {
                    $mediaArr = $media;
                } else {
                    $mediaArr = json_decode(json_encode($media), true) ?: [];
                }

                foreach ($mediaArr as $m) {
                    if (!is_array($m)) {
                        continue;
                    }
                    $data = $m['data'] ?? null;
                    $mime = $m['mime'] ?? null;

                    if ($data && $mime) {
                        $url = 'data:' . $mime . ';base64,' . $data;
                        $byPoi[$pid]['img'][] = $url;
                    }
                }
            }
        }

        // 5) ساخت آیتم‌ها با fallback زبانی + geo
        $items = [];
        foreach ($byPoi as $poi) {
            $label       = self::buildMultilangField($poi['label'],       $supportedLanguages, $language, true);
            $description = self::buildMultilangField($poi['description'], $supportedLanguages, $language, false);
            $address     = self::buildMultilangField($poi['address'],     $supportedLanguages, $language, false);

            $item = [
                'value' => $poi['value'],
                'label' => $label,
            ];

            // geo: lat / lng / floor
            if ($poi['lat'] !== null && $poi['lon'] !== null) {
                $geo = [
                    'lat'  => (float) $poi['lat'],
                    'lng'  => (float) $poi['lon'],
                ];
                if ($poi['floor'] !== null) {
                    $geo['floor'] = (int) $poi['floor'];
                }
                $item['geo'] = $geo;
            }

            if (!empty($description)) {
                $item['description'] = $description;
            }
            if ($withImages && !empty($poi['img'])) {
                $item['img'] = $poi['img'];
            }
            if (!empty($address)) {
                $item['address'] = $address;
            }

            $items[] = [
                'group_code' => $poi['group'],
                'item'       => $item,
            ];
        }

        // 6) سرچ روی label/address زبان انتخابی
        if ($search !== '') {
            $needle = mb_strtolower($search, 'UTF-8');
            $items  = array_values(array_filter($items, function ($row) use ($needle, $language) {
                $item    = $row['item'];
                $label   = $item['label'][$language]   ?? '';
                $addr    = $item['address'][$language] ?? '';
                $text    = $label . ' ' . $addr;
                $haystack = mb_strtolower($text, 'UTF-8');

                return mb_strpos($haystack, $needle) !== false;
            }));
        }

        // 7) pagination global
        $total = count($items);
        $paged = array_slice($items, $offset, $limit);

        // 8) گروهبندی روی group_code → subGroups[group] = [.]
        $subGroups = [];
        foreach ($paged as $row) {
            $g = $row['group_code'];
            if (!isset($subGroups[$g])) {
                $subGroups[$g] = [];
            }
            $subGroups[$g][] = $row['item'];
        }

        return response()->json([
            'subGroups'   => (object) $subGroups,
            'language'    => $language,
            'generatedAt' => now()->toIso8601String(),
            // در صورت نیاز می‌تونی total/limit/offset رو هم آزاد کنی:
            // 'total'  => $total,
            // 'limit'  => $limit,
            // 'offset' => $offset,
        ]);
    }



    /**
     * Helper: ساخت آبجکت چندزبانه با fallback
     *
     * @param array  $src           مثل ['fa' => 'صحن انقلاب', 'en' => 'Enghelab Courtyard']
     * @param array  $langs         ['fa','en','ar','ur']
     * @param string $primaryLang   زبان مورد نظر کاربر
     * @param bool   $forceNonEmpty برای label=true، برای description/address=false
     *
     * @return array
     */
    protected static function buildMultilangField(array $src, array $langs, string $primaryLang, bool $forceNonEmpty = true): array
    {
        if (!empty($src)) {
            // اگر زبان اصلی مقدار دارد، از آن به‌عنوان منبع fallback استفاده کن
            $primaryValue = $src[$primaryLang] ?? reset($src);

            $out = [];
            foreach ($langs as $l) {
                if (isset($src[$l]) && $src[$l] !== '') {
                    $out[$l] = $src[$l];
                } elseif (!empty($primaryValue)) {
                    $out[$l] = $primaryValue;
                }
            }

            return $out;
        }

        // اگر هیچ مقداری نداریم
        if ($forceNonEmpty) {
            return [];
        }

        return [];
    }
}
