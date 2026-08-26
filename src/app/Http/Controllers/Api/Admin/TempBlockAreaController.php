<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TempBlockAreaController extends Controller
{
    private const ENTITY_TABLE = 'temp_block_areas_live';

    // -------------------------
    // GET: list
    // -------------------------
    public function index(Request $r)
    {
        $q = DB::table('temp_block_areas_live as t')
            ->selectRaw("
                t.id, t.floor, t.restrict_type, t.valid_from, t.valid_to,
                t.title, t.reason, t.is_active,
                t.created_by, t.created_at, t.updated_at,
                ST_AsGeoJSON(ST_Transform(t.geom, 4326))::json as geom_geojson_4326
            ");

        if ($r->filled('floor')) $q->where('t.floor', (int)$r->floor);

        if ($r->boolean('only_active')) {
            $q->whereRaw("t.is_active = true AND t.valid_from <= now() AND (t.valid_to IS NULL OR t.valid_to >= now())");
        }

        if ($r->filled('from')) {
            $from = $r->input('from');
            $q->whereRaw("(t.valid_to IS NULL OR t.valid_to >= ?)", [$from]);
        }
        if ($r->filled('to')) {
            $to = $r->input('to');
            $q->whereRaw("t.valid_from <= ?", [$to]);
        }

        // bbox: minLng,minLat,maxLng,maxLat in 4326
        if ($r->filled('bbox')) {
            $parts = explode(',', $r->bbox);
            if (count($parts) === 4) {
                [$minLng, $minLat, $maxLng, $maxLat] = array_map('floatval', $parts);
                $q->whereRaw("
                    ST_Intersects(
                        t.geom,
                        ST_Transform(ST_MakeEnvelope(?, ?, ?, ?, 4326), 32640)
                    )
                ", [$minLng, $minLat, $maxLng, $maxLat]);
            }
        }

        $limit = (int)($r->input('limit', 500));
        $limit = max(1, min($limit, 5000));

        $items = $q->orderByDesc('t.created_at')->limit($limit)->get();

        return response()->json([
            'items' => $items,
            'meta' => ['limit' => $limit, 'count' => $items->count()],
        ]);
    }

    // -------------------------
    // GET: active (from view)
    // -------------------------
    public function active(Request $r)
    {
        $q = DB::table('v_temp_block_areas_active as v')
            ->selectRaw("
                v.id, v.floor, v.restrict_type, v.valid_from, v.valid_to,
                v.title, v.reason, v.is_active,
                v.created_by, v.created_at, v.updated_at,
                ST_AsGeoJSON(ST_Transform(v.geom, 4326))::json as geom_geojson_4326
            ");

        if ($r->filled('floor')) $q->where('v.floor', (int)$r->floor);

        $items = $q->orderByDesc('v.created_at')->get();

        return response()->json(['items' => $items, 'meta' => ['count' => $items->count()]]);
    }

    // -------------------------
    // GET: show (with restrictions)
    // -------------------------
    public function show($id)
    {
        $id = (int)$id;

        $row = DB::table('temp_block_areas_live as t')
            ->selectRaw("
                t.id, t.floor, t.restrict_type, t.valid_from, t.valid_to,
                t.title, t.reason, t.is_active,
                t.created_by, t.created_at, t.updated_at,
                ST_AsText(t.geom) as geom_wkt_32640,
                ST_AsGeoJSON(ST_Transform(t.geom, 4326))::json as geom_geojson_4326
            ")
            ->where('t.id', $id)
            ->first();

        if (!$row) return response()->json(['message' => 'Not found'], 404);

        $time = DB::table('access_time_restrictions')
            ->where('entity_table', self::ENTITY_TABLE)
            ->where('entity_id', $id)
            ->orderBy('id')
            ->get();

        $prayer = DB::table('access_prayer_restrictions')
            ->where('entity_table', self::ENTITY_TABLE)
            ->where('entity_id', $id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'area' => $row,
            'time_restrictions' => $time,
            'prayer_restrictions' => $prayer,
        ]);
    }

    // -------------------------
    // POST: store
    // -------------------------
    public function store(Request $r)
    {
        $data = $this->validatePayload($r, true);

        return DB::transaction(function () use ($data) {

            $geom = $this->geomExprAndBindPolygon($data);

            $row = DB::selectOne("
                INSERT INTO temp_block_areas_live
                    (floor, geom, restrict_type, valid_from, valid_to, created_by, title, reason, is_active, updated_at)
                VALUES
                    (?, {$geom['expr']}, ?, COALESCE(?, now()), ?, ?, ?, ?, COALESCE(?, true), now())
                RETURNING id
            ", array_merge(
                [(int)$data['floor']],
                $geom['bind'],
                [
                    $data['restrict_type'] ?? 'close',
                    $data['valid_from'] ?? null,
                    $data['valid_to'] ?? null,
                    $data['created_by'] ?? null, // بعدا از auth بگیر
                    $data['title'],
                    $data['reason'],
                    $data['is_active'] ?? true,
                ]
            ));

            $id = (int)$row->id;

            // ذخیره محدودیت‌ها
            $this->replaceRestrictions($id, $data);

            return $this->show($id);
        });
    }

    // -------------------------
    // PUT: update (replace restrictions)
    // -------------------------
    public function update(Request $r, $id)
    {
        $id = (int)$id;
        $data = $this->validatePayload($r, false);

        $exists = DB::table('temp_block_areas_live')->where('id', $id)->exists();
        if (!$exists) return response()->json(['message' => 'Not found'], 404);

        return DB::transaction(function () use ($id, $data) {

            $sets = [];
            $bind = [];

            foreach (['floor', 'restrict_type', 'valid_from', 'valid_to', 'title', 'reason', 'is_active'] as $k) {
                if (array_key_exists($k, $data)) {
                    $sets[] = "{$k} = ?";
                    $bind[] = $data[$k];
                }
            }

            // geom (Polygon)
            $hasGeom = array_key_exists('geom_wkt_32640', $data) || array_key_exists('geom_geojson_4326', $data);
            if ($hasGeom) {
                $geom = $this->geomExprAndBindPolygon($data);
                $sets[] = "geom = {$geom['expr']}";
                $bind = array_merge($bind, $geom['bind']);
            }

            // updated_at همیشه
            $sets[] = "updated_at = now()";

            if ($sets) {
                $bind[] = $id;
                DB::update("UPDATE temp_block_areas_live SET " . implode(', ', $sets) . " WHERE id = ?", $bind);
            }

            // replace restrictions کامل
            $this->replaceRestrictions($id, $data);

            return $this->show($id);
        });
    }

    // -------------------------
    // PATCH: stop -> valid_to = now()
    // -------------------------
    public function stop($id)
    {
        $id = (int)$id;
        $updated = DB::update("UPDATE temp_block_areas_live SET valid_to = now(), updated_at = now() WHERE id = ?", [$id]);
        if ($updated === 0) return response()->json(['message' => 'Not found'], 404);
        return $this->show($id);
    }

    // -------------------------
    // PATCH: extend -> update valid_to
    // -------------------------
    public function extend(Request $r, $id)
    {
        $id = (int)$id;
        $data = $r->validate(['valid_to' => ['nullable', 'date']]);

        $updated = DB::update("UPDATE temp_block_areas_live SET valid_to = ?, updated_at = now() WHERE id = ?", [
            $data['valid_to'] ?? null,
            $id,
        ]);

        if ($updated === 0) return response()->json(['message' => 'Not found'], 404);
        return $this->show($id);
    }

    // -------------------------
    // DELETE: destroy + delete restrictions
    // -------------------------
    public function destroy($id)
    {
        $id = (int)$id;

        return DB::transaction(function () use ($id) {

            DB::table('access_time_restrictions')
                ->where('entity_table', self::ENTITY_TABLE)
                ->where('entity_id', $id)
                ->delete();

            DB::table('access_prayer_restrictions')
                ->where('entity_table', self::ENTITY_TABLE)
                ->where('entity_id', $id)
                ->delete();

            $deleted = DB::delete("DELETE FROM temp_block_areas_live WHERE id = ?", [$id]);
            if ($deleted === 0) return response()->json(['message' => 'Not found'], 404);

            return response()->json(['ok' => true]);
        });
    }

    // =========================================================
    // Validation + Restrictions logic
    // =========================================================

    private function validatePayload(Request $r, bool $isCreate): array
    {
        $rules = [
            'floor' => [$isCreate ? 'required' : 'sometimes', 'integer'],
            'restrict_type' => ['sometimes', 'in:close,penalty'],

            'title' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:255'],
            'reason' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],

            'valid_from' => ['sometimes', 'date'],
            'valid_to' => ['nullable', 'date'],

            'geom_wkt_32640' => ['sometimes', 'string'],
            'geom_geojson_4326' => ['sometimes', 'array'],

            // برای مودال اوقات شرعی
            'prayer_rules' => ['sometimes', 'array'], // fajr/dhuhr/maghrib => {enabled,before,after}

            // حالت آرایه‌ای (اگر فرانت مثل درب‌ها بفرستد)
            'time_restrictions' => ['sometimes', 'array'],
            'prayer_restrictions' => ['sometimes', 'array'],

            'created_by' => ['sometimes', 'nullable', 'integer'],
        ];

        $data = $r->validate($rules);

        // create: geom لازم
        if ($isCreate) {
            $hasGeom = array_key_exists('geom_wkt_32640', $data) || array_key_exists('geom_geojson_4326', $data);
            if (!$hasGeom) abort(response()->json(['message' => 'geom_wkt_32640 or geom_geojson_4326 is required'], 422));
        }

        // valid_to >= valid_from
        if (isset($data['valid_from'], $data['valid_to']) && $data['valid_to'] !== null) {
            if (strtotime($data['valid_to']) < strtotime($data['valid_from'])) {
                abort(response()->json(['message' => 'valid_to must be >= valid_from'], 422));
            }
        }

        return $data;
    }

    /**
     * IMPORTANT: geom column is Polygon(32640)
     * No ST_Multi here.
     */
    private function geomExprAndBindPolygon(array $data): array
    {
        if (isset($data['geom_wkt_32640'])) {
            return [
                'expr' => "ST_GeomFromText(?, 32640)::geometry(Polygon,32640)",
                'bind' => [$data['geom_wkt_32640']],
            ];
        }

        $geojson = json_encode($data['geom_geojson_4326'], JSON_UNESCAPED_UNICODE);

        return [
            'expr' => "ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), 32640)::geometry(Polygon,32640)",
            'bind' => [$geojson],
        ];
    }

    /**
     * Replace ALL restrictions for this entity_id.
     * - time restrictions: from payload time_restrictions OR derived from valid_from/valid_to
     * - prayer restrictions: from payload prayer_rules or prayer_restrictions
     */
    private function replaceRestrictions(int $entityId, array $data): void
    {
        // 1) delete existing
        DB::table('access_time_restrictions')
            ->where('entity_table', self::ENTITY_TABLE)
            ->where('entity_id', $entityId)
            ->delete();

        DB::table('access_prayer_restrictions')
            ->where('entity_table', self::ENTITY_TABLE)
            ->where('entity_id', $entityId)
            ->delete();

        // 2) insert time restrictions
        $timeRows = [];

        if (!empty($data['time_restrictions']) && is_array($data['time_restrictions'])) {
            // حالت آرایه‌ای: هر آیتم را مستقیم به سطر تبدیل می‌کنیم
            foreach ($data['time_restrictions'] as $tr) {
                $timeRows = array_merge($timeRows, $this->normalizeTimeRestrictionItem($entityId, $tr, $data['title'] ?? null));
            }
        } else {
            // حالت مودال: از valid_from/valid_to استخراج می‌کنیم
            // اگر در update valid_from/valid_to ارسال نشود، چیزی ذخیره نمی‌کنیم (به قصد دست نزدن)
            if (array_key_exists('valid_from', $data) || array_key_exists('valid_to', $data)) {
                // برای derive نیاز به هر دو داریم؛ اگر یکی نبود، از DB بخوان
                $base = DB::table('temp_block_areas_live')
                    ->select('valid_from', 'valid_to')
                    ->where('id', $entityId)->first();

                $vf = $data['valid_from'] ?? $base->valid_from;
                $vt = array_key_exists('valid_to', $data) ? $data['valid_to'] : $base->valid_to;

                $timeRows = $this->buildTimeRowsFromDateTimeRange($entityId, $vf, $vt, $data['title'] ?? null);
            }
        }

        if (!empty($timeRows)) {
            DB::table('access_time_restrictions')->insert($timeRows);
        }

        // 3) insert prayer restrictions
        $prayerRows = [];

        if (!empty($data['prayer_restrictions']) && is_array($data['prayer_restrictions'])) {
            // حالت آرایه‌ای
            foreach ($data['prayer_restrictions'] as $pr) {
                $prayerRows = array_merge($prayerRows, $this->normalizePrayerRestrictionItem($entityId, $pr, $data['title'] ?? null));
            }
        } elseif (!empty($data['prayer_rules']) && is_array($data['prayer_rules'])) {
            // حالت مودال
            $prayerRows = $this->buildPrayerRowsFromPrayerRules($entityId, $data['prayer_rules'], $data['title'] ?? null);
        }

        if (!empty($prayerRows)) {
            DB::table('access_prayer_restrictions')->insert($prayerRows);
        }
    }

    /**
     * Convert one "time_restrictions item" (front format like doors) into DB rows.
     * Supports:
     *  - date_scope: array of dates or ["ALL_DAYS"]
     *  - all_hours: bool
     *  - time_ranges: [{start:"HH:MM", end:"HH:MM"}]  (if empty and all_hours true => whole day)
     *  - gender: array|null
     *  - title (optional inside attrs)
     */
    private function normalizeTimeRestrictionItem(int $entityId, array $tr, ?string $fallbackTitle): array
    {
        // ✅ FIX: تعریف امن dateScope
        $dateScopeRaw = $tr['date_scope'] ?? null;

        // قرارداد شما: اگر چیزی نیامد => ALL_DAYS
        $dateScope = $dateScopeRaw ?? ['ALL_DAYS'];

        $allHours  = (bool)($tr['all_hours'] ?? false);

        // ✅ FIX: تعریف امن gender (پیش‌فرض both)
        $genderRaw = $tr['gender'] ?? ($tr['gender_access'] ?? null);
        $gender = $this->defaultGender($genderRaw); // اگر این helper را قبلاً گذاشتی

        $attrs = $tr['attrs'] ?? [];
        if (!is_array($attrs)) $attrs = [];
        $attrs['source'] = 'temp_block_area';
        $attrs['title'] = $attrs['title'] ?? ($tr['title'] ?? $fallbackTitle);

        $rows = [];

        $timeRanges = $tr['time_ranges'] ?? [];
        if (!is_array($timeRanges)) $timeRanges = [];

        if ($allHours && count($timeRanges) === 0) {
            $rows[] = [
                'entity_table' => self::ENTITY_TABLE,
                'entity_id' => $entityId,
                'gender' => $this->pgArray($gender),
                'date_scope' => $this->pgArray($$dateScope ?? ['ALL_DAYS']),   // ✅ همیشه JSON
                'specific_date' => null,
                'start_time' => null,
                'end_time' => null,
                'all_hours' => true,
                'attrs' => $this->jsonOrNull($attrs),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            return $rows;
        }

        foreach ($timeRanges as $rng) {
            $rows[] = [
                'entity_table' => self::ENTITY_TABLE,
                'entity_id' => $entityId,
                'gender' => $this->pgArray($gender),
                'date_scope' => $this->pgArray($dateScope),
                'specific_date' => null,
                'start_time' => $rng['start'] ?? null,
                'end_time' => $rng['end'] ?? null,
                'all_hours' => $allHours,
                'attrs' => $this->jsonOrNull($attrs),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }



    /**
     * Build DB rows from a datetime range (valid_from -> valid_to)
     * - same day => 1 row (date_scope=[YYYY-MM-DD], start_time, end_time)
     * - multi-day => first day partial, middle days all_hours, last day partial
     * - valid_to null => store as ALL_DAYS all_hours=true (boundedness stays in temp_block table)
     */
    private function buildTimeRowsFromDateTimeRange(
        int $entityId,
        $validFrom,
        $validTo,
        ?string $title,
        $genderRaw = null
    ): array {
        $gender = $this->defaultGender($genderRaw);

        $attrs = [
            'source' => 'temp_block_area',
            'title'  => $title,
            'mode'   => 'derived_from_valid_from_to',
        ];

        // اگر valid_to نداشت => ALL_DAYS + all_hours
        if ($validTo === null) {
            return [[
                'entity_table'  => self::ENTITY_TABLE,
                'entity_id'     => $entityId,
                'gender'        => $this->pgArray($gender),
                'date_scope'    => $this->pgArray(['ALL_DAYS']), // ✅ تعریف صریح
                'specific_date' => null,
                'start_time'    => null,
                'end_time'      => null,
                'all_hours'     => true,
                'attrs'         => $this->jsonOrNull($attrs),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]];
        }

        $vf = new \DateTimeImmutable((string)$validFrom);
        $vt = new \DateTimeImmutable((string)$validTo);

        $rows = [];

        // --- روز اول ---
        $rows[] = [
            'entity_table'  => self::ENTITY_TABLE,
            'entity_id'     => $entityId,
            'gender'        => $this->pgArray($gender),
            'date_scope'    => $this->pgArray([$vf->format('Y-m-d')]), // ✅
            'specific_date' => null,
            'start_time'    => $vf->format('H:i'),
            'end_time'      => '23:59',
            'all_hours'     => false,
            'attrs'         => $this->jsonOrNull($attrs),
            'created_at'    => now(),
            'updated_at'    => now(),
        ];

        // --- روزهای میانی ---
        $d = $vf->modify('+1 day')->setTime(0, 0);
        $endMid = $vt->modify('-1 day')->setTime(0, 0);

        while ($d <= $endMid) {
            $rows[] = [
                'entity_table'  => self::ENTITY_TABLE,
                'entity_id'     => $entityId,
                'gender'        => $this->pgArray($gender),
                'date_scope'    => $this->pgArray([$d->format('Y-m-d')]), // ✅
                'specific_date' => null,
                'start_time'    => null,
                'end_time'      => null,
                'all_hours'     => true,
                'attrs'         => $this->jsonOrNull($attrs),
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
            $d = $d->modify('+1 day');
        }

        // --- روز آخر ---
        $rows[] = [
            'entity_table'  => self::ENTITY_TABLE,
            'entity_id'     => $entityId,
            'gender'        => $this->pgArray($gender),
            'date_scope'    => $this->pgArray([$vt->format('Y-m-d')]), // ✅
            'specific_date' => null,
            'start_time'    => '00:00',
            'end_time'      => $vt->format('H:i'),
            'all_hours'     => false,
            'attrs'         => $this->jsonOrNull($attrs),
            'created_at'    => now(),
            'updated_at'    => now(),
        ];

        return $rows;
    }


    private function jsonOrNull($value): ?string
    {
        if ($value === null) return null;
        // اگر از قبل string JSON بود دست نزن
        if (is_string($value)) return $value;
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }


    /**
     * From modal prayer_rules:
     * prayer_rules = { fajr:{enabled,before,after}, dhuhr:{...}, maghrib:{...} }
     */
    private function buildPrayerRowsFromPrayerRules(int $entityId, array $rules, ?string $title): array
{
    $rows = [];
    $attrsBase = ['source' => 'temp_block_area', 'title' => $title];

    // ✅ چون gender در prayer_rules نمی‌آید، پیش‌فرض: برای همه
    $gender = $this->defaultGender(null); // خروجی باید array باشد (پایین اصلاح می‌کنیم)

    foreach ($rules as $event => $cfg) {
        $event = $this->normalizePrayerEvent($event);
        if ($event === null) continue;

        $enabled = (bool)($cfg['enabled'] ?? false);
        if (!$enabled) continue;

        $before = (int)($cfg['before'] ?? 0);
        $after  = (int)($cfg['after'] ?? 0);

        $rows[] = [
            'entity_table'   => self::ENTITY_TABLE,
            'entity_id'      => $entityId,
            'prayer_event'   => $event,
            'before_minutes' => $before,
            'after_minutes'  => $after,
            'specific_date'  => null,
            'gender'         => $this->pgArray($gender), // ✅
            'attrs'          => $this->jsonOrNull($attrsBase),
            'created_at'     => now(),
            'updated_at'     => now(),
        ];
    }

    return $rows;
}


    /**
     * Normalize an array-style prayer restriction item:
     * { events:[...], before_minutes, after_minutes, gender?, attrs?, specific_date? }
     */
    private function normalizePrayerRestrictionItem(int $entityId, array $pr, ?string $fallbackTitle): array
    {
        $events = $pr['events'] ?? [];
        if (!is_array($events)) $events = [];

        $before = (int)($pr['before_minutes'] ?? 0);
        $after  = (int)($pr['after_minutes'] ?? 0);

        // ✅ FIX: تعریف امن gender
        $genderRaw = $pr['gender'] ?? ($pr['gender_access'] ?? null);
        $gender = $this->defaultGender($genderRaw);

        $specificDate = $pr['specific_date'] ?? null;

        $attrs = $pr['attrs'] ?? [];
        if (!is_array($attrs)) $attrs = [];
        $attrs['source'] = 'temp_block_area';
        $attrs['title'] = $attrs['title'] ?? ($pr['title'] ?? $fallbackTitle);

        $rows = [];
        foreach ($events as $ev) {
            $ev = $this->normalizePrayerEvent((string)$ev);
            if ($ev === null) continue;

            $rows[] = [
                'entity_table' => self::ENTITY_TABLE,
                'entity_id' => $entityId,
                'prayer_event' => $ev,
                'before_minutes' => $before,
                'after_minutes' => $after,
                'specific_date' => $specificDate,
                'gender' => $this->pgArray($gender),                               // ✅
                'attrs' => $this->jsonOrNull($attrs),               // ✅
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }


    /**
     * Accept canonical or Persian labels from UI and map to canonical
     */
    private function normalizePrayerEvent(string $event): ?string
    {
        $e = trim(mb_strtolower($event));

        // canonical
        if (in_array($e, ['fajr', 'dhuhr', 'maghrib'], true)) return $e;

        if (in_array($e, ['dhuhr_asr', 'maghrib_isha'], true)) {
            return $e === 'dhuhr_asr' ? 'dhuhr' : 'maghrib';
        }

        // Persian labels coming from UI
        $map = [
            'نماز صبح' => 'fajr',
            'صبح' => 'fajr',
            'fajr' => 'fajr',

            'نماز ظهر و عصر' => 'dhuhr',
            'ظهر و عصر' => 'dhuhr',
            'ظهر' => 'dhuhr',
            'عصر' => 'dhuhr',
            'dhuhr' => 'dhuhr',

            'نماز مغرب و عشاء' => 'maghrib',
            'مغرب و عشاء' => 'maghrib',
            'مغرب' => 'maghrib',
            'عشاء' => 'maghrib',
            'maghrib' => 'maghrib',
        ];

        // چون کلیدها فارسی‌اند، با ورژن اصلی هم چک می‌کنیم
        if (isset($map[$event])) return $map[$event];
        return $map[$e] ?? null;
    }

    private function defaultGender($value)
    {
        // چون ستون gender در DB آرایه است، خروجی را آرایه نگه می‌داریم
        if ($value === null) return ['both'];

        if (is_array($value)) {
            return count($value) ? $value : ['both'];
        }

        $v = trim((string)$value);
        return $v !== '' ? [$v] : ['both'];
    }



    private function pgTextArrayLiteral($value): ?string
    {
        if ($value === null) return null;

        // اگر از قبل string است (مثلاً "{ALL_DAYS}") همان را برگردان
        if (is_string($value)) {
            $v = trim($value);
            // اگر JSON آمده باشد، تبدیلش کن
            if (str_starts_with($v, '[') && str_ends_with($v, ']')) {
                $decoded = json_decode($v, true);
                if (is_array($decoded)) return $this->pgTextArrayLiteral($decoded);
            }
            // اگر خودش array literal است
            if (str_starts_with($v, '{') && str_ends_with($v, '}')) return $v;

            // تک رشته => آرایه تک‌عضوی
            return '{"' . str_replace('"', '\"', $v) . '"}';
        }

        // اگر آرایه PHP است
        if (is_array($value)) {
            if (count($value) === 0) return '{}';
            $items = array_map(function ($s) {
                $s = (string)$s;
                $s = str_replace('\\', '\\\\', $s);
                $s = str_replace('"', '\"', $s);
                return '"' . $s . '"';
            }, $value);

            return '{' . implode(',', $items) . '}';
        }

        // سایر انواع
        return '{"' . str_replace('"', '\"', (string)$value) . '"}';
    }

    private function pgArray($value): ?string
    {
        if ($value === null) return null;

        // اگر string JSON مثل ["2025-12-14"] آمد، decode کن
        if (is_string($value)) {
            $v = trim($value);
            if (str_starts_with($v, '[') && str_ends_with($v, ']')) {
                $decoded = json_decode($v, true);
                if (is_array($decoded)) return $this->pgArray($decoded);
            }
            // اگر خودش array literal است
            if (str_starts_with($v, '{') && str_ends_with($v, '}')) return $v;

            // تک رشته => آرایه تک عضوی
            $v = str_replace(['\\', '"'], ['\\\\', '\"'], $v);
            return '{"' . $v . '"}';
        }

        // PHP array => postgres array literal
        if (is_array($value)) {
            if (count($value) === 0) return '{}';
            $items = array_map(function ($s) {
                $s = (string)$s;
                $s = str_replace(['\\', '"'], ['\\\\', '\"'], $s);
                return '"' . $s . '"';
            }, $value);
            return '{' . implode(',', $items) . '}';
        }

        // fallback
        $v = str_replace(['\\', '"'], ['\\\\', '\"'], (string)$value);
        return '{"' . $v . '"}';
    }
}
