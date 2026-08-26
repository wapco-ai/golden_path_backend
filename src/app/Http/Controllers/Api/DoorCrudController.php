<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RebuildDoorGraphJob;
use App\Jobs\DeleteDoorGraphJob;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;



class DoorCrudController extends Controller
{
    /**
     * POST /api/v1/doors
     *
     * ایجاد درب جدید از روی کلیک روی نقشه
     */
    private const PRAYER_EVENT_CODE_MAP = [
        'fajr' => [
            'fa' => 'نماز صبح',
            'en' => 'Fajr',
        ],
        'dhuhr_asr' => [
            'fa' => 'نماز ظهر و عصر',
            'en' => 'Dhuhr & Asr',
        ],
        'maghrib_isha' => [
            'fa' => 'نماز مغرب و عشاء',
            'en' => 'Maghrib & Isha',
        ],
    ];

    /**
     * ورودی: لیبل ارسالی از فرانت (فارسی/انگلیسی/کد)
     * خروجی: [event_code, event_label_for_fa]
     */
    private function normalizePrayerEvent(string $value): array
    {
        $v = trim($value);

        // اگر خودش دقیقاً یکی از کدهاست
        if (isset(self::PRAYER_EVENT_CODE_MAP[$v])) {
            return [$v, self::PRAYER_EVENT_CODE_MAP[$v]['fa']];
        }

        // اگر یکی از لیبل‌های فارسی باشد
        foreach (self::PRAYER_EVENT_CODE_MAP as $code => $labels) {
            if (($labels['fa'] ?? null) === $v) {
                return [$code, $labels['fa']];
            }
        }

        // اگر لیبل انگلیسی استاندارد بود
        foreach (self::PRAYER_EVENT_CODE_MAP as $code => $labels) {
            if (($labels['en'] ?? null) === $v) {
                return [$code, $labels['fa']]; // برای نمایش فعلاً fa
            }
        }

        // fallback: اسلاگ کنیم (مثلاً "نماز عشاء" -> "namaz_asia" و ...)
        $code = Str::slug($v, '_');
        return [$code, $v];
    }

    /**
     * از روی کد، لیبل مناسب (فعلاً فارسی) بساز
     */
    private function localizePrayerEvent(string $code, string $lang = 'fa'): string
    {
        if (isset(self::PRAYER_EVENT_CODE_MAP[$code][$lang])) {
            return self::PRAYER_EVENT_CODE_MAP[$code][$lang];
        }
        // اگر مپ نداشتیم، خود کد رو برگردونیم
        return $code;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'x'              => 'required|numeric',
            'y'              => 'required|numeric',
            'floor'          => 'required|integer',
            'allowed_gender' => 'nullable|string',
            'is_open'        => 'nullable|boolean',
            'modes'          => 'nullable|array',
            'modes.*'        => 'string',
            'bidirectional'  => 'nullable|boolean',
        ]);

        $x  = (float) $data['x'];
        $y  = (float) $data['y'];
        $fl = (int)   $data['floor'];

        $allowedGender = $data['allowed_gender'] ?? 'both';
        $isOpen        = $data['is_open'] ?? true;
        $modes         = $data['modes'] ?? ['walk', 'wheelchair'];
        $bidirectional = $data['bidirectional'] ?? true;

        // تبدیل آرایه PHP به literal مناسب PG: {"walk","wheelchair"}
        $modesPg = '{' . implode(',', array_map(function ($m) {
            $m = str_replace('"', '\"', $m);
            return '"' . $m . '"';
        }, $modes)) . '}';

        // ...

        $row = DB::transaction(function () use (
            $x,
            $y,
            $fl,
            $allowedGender,
            $isOpen,
            $modesPg,
            $bidirectional
        ) {
            $row = DB::selectOne(
                "SELECT *
         FROM fn_create_door_from_click(
             :x, :y, :floor,
             :allowed_gender, :is_open,
             :modes::text[], :bidirectional
         )",
                [
                    'x'              => $x,
                    'y'              => $y,
                    'floor'          => $fl,
                    'allowed_gender' => $allowedGender,
                    'is_open'        => $isOpen,
                    'modes'          => $modesPg,
                    'bidirectional'  => $bidirectional,
                ]
            );

            if (!$row) {
                throw new \RuntimeException('Door creation failed: no record returned from fn_create_door_from_click');
            }

            // ✅ ساخت incremental گراف فقط برای همین درب
            // DB::select("SELECT fn_rebuild_graph_for_door(:door_id)", [
            //     'door_id' => $row->door_id,
            // ]);

            return $row;
        });

        $this->queueDoorGraphRebuild((int) $row->door_id);

        if (!$row) {
            return response()->json([
                'message' => 'Door creation failed: no record returned from fn_create_door_from_click',
            ], 500);
        }

        // ...


        // return response()->json([
        //     'door' => [
        //         'id'        => $row->door_id,
        //         'from_area' => $row->door_from_area,
        //         'to_area'   => $row->door_to_area,
        //     ],
        //     'door_access_point' => [
        //         'id'    => $row->dap_id,
        //         'floor' => $fl,
        //     ],
        // ]);

        return response()->json([
            'message' => 'Door created. Graph rebuild queued.',
            'graph_status' => 'queued',
            'door' => [
                'id'        => $row->door_id,
                'from_area' => $row->door_from_area,
                'to_area'   => $row->door_to_area,
            ],
            'door_access_point' => [
                'id'    => $row->dap_id,
                'floor' => $fl,
            ],
        ], 202);
    }

    /**
     * GET /api/v1/doors/{id}
     *
     * نمایش اطلاعات خام درب + access_points
     * (برای مصرف سیستمی/ادمین. برای مودال از /doors/{id}/info استفاده کنید)
     */
    public function show(int $doorId)
    {
        $door  = Door::findOrFail($doorId);
        $attrs = $door->attrs ?? [];

        $timeRestrictions   = $attrs['time_restrictions']   ?? [];
        $prayerRestrictions = $attrs['prayer_restrictions'] ?? [];

        // اگر داده‌های قدیمی داری که در آنها هنوز 'date' وجود دارد،
        // اینجا می‌توانی یک تبدیل سبک برای backward-compat انجام بدهی.
        $timeRestrictions   = $this->denormalizeTimeRestrictions($timeRestrictions);
        $prayerRestrictions = $this->denormalizePrayerRestrictions($prayerRestrictions);

        return response()->json([
            'id'    => $door->id,
            'floor' => $door->floor,

            'basic_info' => $attrs['basic_info'] ?? [
                'title' => ['fa' => null, 'en' => null, 'ar' => null, 'ur' => null],
                'description' => null,
            ],

            'grouping' => $attrs['grouping'] ?? [
                'group_id'        => null,
                'sub_group_id'    => null,
                'sub_group_label' => null,
            ],

            'operational' => [
                'status'          => data_get($attrs, 'operational.status', 'inactive'),
                'transport_modes' => $door->modes ?? [],
                'gender_access'   => data_get($attrs, 'operational.gender_access', []),
                'place_function'  => data_get($attrs, 'operational.place_function', 'door'),
                'is_covered'      => data_get($attrs, 'operational.is_covered', null),
            ],

            'time_restrictions'   => $timeRestrictions,
            'prayer_restrictions' => $prayerRestrictions,

            'notes' => $attrs['notes'] ?? '',
        ]);
    }


    /**
     * PUT /api/v1/doors/{id}
     *
     * آپدیت ساده‌ی فیلدهای مستقیم درب (بدون اطلاعات توصیفی مودال)
     * اگر نمی‌خواهید از این استفاده کنید می‌توانید فقط از /info استفاده کنید.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'basic_info'                       => 'required|array',
            'basic_info.title'                 => 'required|array',
            'basic_info.title.fa'              => 'required|string',
            'basic_info.title.en'              => 'nullable|string',
            'basic_info.title.ar'              => 'nullable|string',
            'basic_info.title.ur'              => 'nullable|string',
            'basic_info.description'           => 'nullable|string',

            'grouping'                         => 'nullable|array',
            'grouping.group_id'                => 'nullable|string',
            'grouping.sub_group_id'            => 'nullable|string',
            'grouping.sub_group_label'         => 'nullable|string',

            'operational'                          => 'required|array',
            'operational.status'                   => 'required|in:active,inactive',
            'operational.transport_modes'          => 'required|array',
            'operational.transport_modes.*'        => 'required|string',
            'operational.gender_access'            => 'required|array',
            'operational.gender_access.*'          => 'required|string',
            'operational.place_function'           => 'required|string',
            'operational.is_covered'               => 'nullable|boolean',

            'time_restrictions'                    => 'nullable|array',
            'time_restrictions.*.date_scope'       => 'required|array|min:1',
            'time_restrictions.*.date_scope.*'     => 'required|string', // "YYYY-MM-DD" یا "ALL_DAYS"
            'time_restrictions.*.gender'           => 'nullable|array',
            'time_restrictions.*.gender.*'         => 'required|string',
            'time_restrictions.*.time_ranges'      => 'required|array|min:1',
            'time_restrictions.*.time_ranges.*.start' => 'required|string|date_format:H:i',
            'time_restrictions.*.time_ranges.*.end'   => 'required|string|date_format:H:i',
            'time_restrictions.*.all_hours'        => 'required|boolean',
            // اگر خواستی برای time هم title نگه داری:
            'time_restrictions.*.title'            => 'nullable|string',

            'prayer_restrictions'                  => 'nullable|array',
            'prayer_restrictions.*.date_scope'     => 'required|array|min:1',
            'prayer_restrictions.*.date_scope.*'   => 'required|string', // "YYYY-MM-DD" یا "ALL_DAYS"
            'prayer_restrictions.*.events'         => 'required|array|min:1',
            'prayer_restrictions.*.events.*'       => 'required|string',
            'prayer_restrictions.*.before_minutes' => 'required|integer',
            'prayer_restrictions.*.after_minutes'  => 'required|integer',
            'prayer_restrictions.*.title'          => 'required|string', // طبق قرارداد جدید حتماً میاد

            'notes'                                => 'nullable|string',

            'prayer_restrictions.*.gender' => 'nullable|array',

            'prayer_restrictions.*.gender.*' => 'required|string|in:male,female,both,family',
        ]);


        $validated = $this->validateDoorInfo($request); // همون کُدی که بالا نوشتم

        $door = Door::findOrFail($doorId);

        $timeRestrictions    = $this->normalizeTimeRestrictions($validated['time_restrictions'] ?? []);
        $prayerRestrictions  = $this->normalizePrayerRestrictions($validated['prayer_restrictions'] ?? []);

        $attrs = $door->attrs ?? [];

        $attrs['basic_info']          = $validated['basic_info'];
        $attrs['grouping']            = $validated['grouping'] ?? null;
        $attrs['operational']         = $validated['operational'];
        $attrs['time_restrictions']   = $timeRestrictions;
        $attrs['prayer_restrictions'] = $prayerRestrictions;
        $attrs['notes']               = $validated['notes'] ?? null;

        // بروزرسانی ستون‌های اصلی (modes / allowed_gender / is_open اگر داری)
        $door->modes          = $validated['operational']['transport_modes']; // حتماً cast=array در مدل
        $door->allowed_gender = $this->normalizeAllowedGender(
            $validated['operational']['gender_access'] ?? []
        );
        $door->is_open        = $validated['operational']['status'] === 'active';

        $door->attrs = $attrs;
        $door->save();

        return response()->json([
            'message' => 'Door info updated successfully.',
        ]);
    }



    private function normalizeDateScope(?array $dateScope): ?array
    {
        if (empty($dateScope)) {
            return null;
        }

        // اگر ALL_DAYS باشد، همان را دست‌نخورده نگه می‌داریم
        if (count($dateScope) === 1 && strtoupper($dateScope[0]) === 'ALL_DAYS') {
            return ['ALL_DAYS'];
        }

        // بقیه حالت‌ها: فقط trim می‌کنیم (و اگر خواستی می‌توانی sort هم بکنی)
        return array_values(array_map('trim', $dateScope));
    }

    private function normalizeTimeRestrictions(array $items): array
    {
        return collect($items)->map(function ($item) {
            return [
                'date_scope'   => $this->normalizeDateScope($item['date_scope'] ?? []),
                'gender'       => array_values($item['gender'] ?? []),
                'time_ranges'  => collect($item['time_ranges'] ?? [])->map(function ($tr) {
                    return [
                        'start' => $tr['start'] ?? null,
                        'end'   => $tr['end'] ?? null,
                    ];
                })->values()->all(),
                'all_hours'    => (bool)($item['all_hours'] ?? false),
                // اگر فرانت برای time هم title بفرستد
                'title'        => $item['title'] ?? null,
            ];
        })->filter(function ($row) {
            // ردیف‌هایی که date_scope نرمال‌شده ندارند را حذف کن
            return !empty($row['date_scope']);
        })->values()->all();
    }

    private function normalizePrayerRestrictions(array $items): array
    {
        return collect($items)
            ->map(function ($item) {

                $gender = array_map(
                    static function ($value) {

                        $value = strtolower(
                            trim((string) $value)
                        );

                        return $value === 'family'
                            ? 'both'
                            : $value;
                    },
                    $item['gender'] ?? []
                );

                $gender = array_values(
                    array_unique(
                        array_filter(
                            $gender,
                            static fn($value) =>
                            in_array(
                                $value,
                                ['male', 'female', 'both'],
                                true
                            )
                        )
                    )
                );

                return [
                    'date_scope' =>
                    $this->normalizeDateScope(
                        $item['date_scope'] ?? []
                    ),

                    'events' =>
                    array_values(
                        $item['events'] ?? []
                    ),

                    'before_minutes' =>
                    (int) (
                        $item['before_minutes'] ?? 0
                    ),

                    'after_minutes' =>
                    (int) (
                        $item['after_minutes'] ?? 0
                    ),

                    'gender' => $gender,

                    'title' =>
                    $item['title'] ?? null,
                ];
            })
            ->filter(
                fn($row) =>
                !empty($row['date_scope'])
            )
            ->values()
            ->all();
    }


    /**
     * PUT /api/v1/doors/{id}/move
     *
     * جابجایی درب روی مرز نزدیک به کلیک جدید
     */
    public function move(Request $request, $id)
    {
        $doorId = (int) $id;

        $x = $request->input('x', $request->input('utm_x'));
        $y = $request->input('y', $request->input('utm_y'));
        $floor = $request->input('floor');

        if ($x === null || $y === null) {
            return response()->json([
                'message' => 'x and y are required.',
            ], 422);
        }

        $x = (float) $x;
        $y = (float) $y;
        $floor = $floor === null ? null : (int) $floor;

        $door = DB::table('doors')
            ->select('id', 'floor')
            ->where('id', $doorId)
            ->first();

        if (!$door) {
            return response()->json([
                'message' => 'Door not found',
            ], 404);
        }

        $row = DB::transaction(function () use ($doorId, $x, $y, $floor) {
            $beforeCount = DB::table('doors')->count();

            $rows = DB::select(
                "
            SELECT
                m.door_id,
                ST_AsGeoJSON(m.door_geom)::json AS door_geom,
                m.door_from_area,
                m.door_to_area,
                m.dap_id,
                ST_AsGeoJSON(m.dap_geom)::json AS dap_geom
            FROM public.fn_move_door_to_click(
                ?::bigint,
                ?::double precision,
                ?::double precision,
                ?::smallint
            ) AS m
            ",
                [
                    $doorId,
                    $x,
                    $y,
                    $floor,
                ]
            );

            if (empty($rows)) {
                throw new \RuntimeException('Door move failed: empty result.');
            }

            $row = $rows[0];

            if ((int) $row->door_id !== $doorId) {
                throw new \RuntimeException(
                    'Door move failed: returned door_id does not match requested door_id.'
                );
            }

            $afterCount = DB::table('doors')->count();

            if ($afterCount !== $beforeCount) {
                throw new \RuntimeException(
                    'Door move failed: doors count changed during move. Check if create endpoint/function is being called.'
                );
            }

            return $row;
        });

        $this->queueDoorGraphRebuild((int) $row->door_id);

        return response()->json([
            'message' => 'Door moved. Graph rebuild queued.',
            'graph_status' => 'queued',
            'door' => [
                'id' => (int) $row->door_id,
                'from_area' => $row->door_from_area !== null ? (int) $row->door_from_area : null,
                'to_area' => $row->door_to_area !== null ? (int) $row->door_to_area : null,
                'geometry' => $row->door_geom,
            ],
            'door_access_point' => [
                'id' => $row->dap_id !== null ? (int) $row->dap_id : null,
                'geometry' => $row->dap_geom,
            ],
        ], 202);
    }

    /**
     * DELETE /api/v1/doors/{id}
     *
     * حذف درب و تمام access_point های آن
     */
    /**
     * DELETE /api/v1/doors/{id}
     *
     * حذف async درب:
     * - در request فقط درب از routing خارج می‌شود.
     * - حذف گراف و حذف فیزیکی رکوردها در queue انجام می‌شود.
     */
    public function destroy($id)
    {
        $doorId = (int) $id;

        $door = DB::table('doors')
            ->select('id', 'floor', 'from_area', 'to_area', 'attrs')
            ->where('id', $doorId)
            ->first();

        if (!$door) {
            return response()->json([
                'message' => 'Door not found',
            ], 404);
        }

        DB::transaction(function () use ($doorId) {
            $payload = json_encode([
                'status'     => 'queued',
                'action'     => 'delete',
                'updated_at' => now()->toISOString(),
            ], JSON_UNESCAPED_UNICODE);

            /*
         * نکته مهم:
         * همین الان درب را از مسیر‌یابی خارج می‌کنیم تا تا زمان اجرای job
         * از این درب در routing استفاده نشود.
         */
            DB::statement(
                "
            UPDATE public.doors
            SET
                is_open = false,
                attrs = jsonb_set(
                    COALESCE(attrs, '{}'::jsonb),
                    '{graph}',
                    ?::jsonb,
                    true
                ),
                updated_at = now()
            WHERE id = ?
            ",
                [$payload, $doorId]
            );

            DeleteDoorGraphJob::dispatch($doorId)->afterCommit();
        });

        return response()->json([
            'status'       => 'queued',
            'message'      => 'Door delete queued. Graph cleanup is running in background.',
            'graph_status' => 'queued',
            'action'       => 'delete',
            'id'           => $doorId,
        ], 202);
    }

    /**
     * GET /api/v1/doors/{id}/info
     *
     * خواندن اطلاعات مودال (basic_info + grouping + operational + time/prayer)
     */
    public function getInfo($id)
    {
        $doorId = (int) $id;

        $door = DB::table('doors')->where('id', $doorId)->first();
        if (!$door) {
            return response()->json(['message' => 'Door not found'], 404);
        }

        // attrs
        $attrs = $door->attrs ? json_decode($door->attrs, true) : [];
        $operMeta  = $attrs['operational'] ?? [];
        $groupMeta = $attrs['grouping'] ?? [];
        $notesMeta = $attrs['notes'] ?? '';

        // 1) titles
        $i18nRows = DB::table('i18n_texts')
            ->where('entity_table', 'doors')
            ->where('entity_id', $doorId)
            ->get();

        $titles = [];
        foreach ($i18nRows as $row) {
            if ($row->field === 'name') {
                $titles[$row->lang] = $row->txt;
            }
        }
        foreach (['fa', 'en', 'ar', 'ur'] as $lang) {
            if (!array_key_exists($lang, $titles)) {
                $titles[$lang] = '';
            }
        }

        $description = $operMeta['description'] ?? '';

        // 2) grouping
        $grouping = [
            'group_id'        => $groupMeta['group_id'] ?? null,
            'sub_group_id'    => $groupMeta['sub_group_id'] ?? null,
            'sub_group_label' => $groupMeta['sub_group_label'] ?? null,
        ];

        // 3) operational
        $status = $operMeta['status'] ?? ($door->is_open ? 'active' : 'inactive');
        $transportModes = $this->parsePgArray($door->modes ?? null);
        $genderAccess   = $operMeta['gender_access'] ?? [];

        $operational = [
            'status'          => $status,
            'transport_modes' => $transportModes,
            'gender_access'   => $genderAccess,
            'place_function'  => $operMeta['place_function'] ?? 'door',
            'is_covered'      => $operMeta['is_covered'] ?? null,
        ];

        // 3.5) routing (direction / bidirectional)
        $routing = [
            'from_area'      => $door->from_area ?? null,
            'to_area'        => $door->to_area ?? null,
            'bidirectional'  => isset($door->bidirectional) ? (bool)$door->bidirectional : true,
        ];

        // 4) schedules -> از access_*_restrictions (برای doors)

        // time
        $timeRows = DB::table('access_time_restrictions')
            ->where('entity_table', 'doors')
            ->where('entity_id', $doorId)
            ->get();

        // گروه‌بندی مثل قبل: date_scope + gender + all_hours
        $timeGroups = [];
        foreach ($timeRows as $row) {
            $dateScope = $this->parsePgArray($row->date_scope ?? null);
            $gender    = $this->parsePgArray($row->gender ?? null);
            $allHours  = (bool)($row->all_hours ?? false);

            $key = md5(json_encode([$dateScope, $gender, $allHours], JSON_UNESCAPED_UNICODE));

            if (!isset($timeGroups[$key])) {
                $timeGroups[$key] = [
                    'date_scope'  => $dateScope,
                    'gender'      => $gender,
                    'time_ranges' => [],
                    'all_hours'   => $allHours,
                ];
            }

            // اگر all_hours=true هم باشد، time_ranges را برگردان
            $start = $row->start_time ? substr((string)$row->start_time, 0, 5) : null;
            $end   = $row->end_time   ? substr((string)$row->end_time,   0, 5) : null;

            // اگر زمان‌ها در DB ذخیره نشده بود ولی all_hours=true بود، پیش‌فرض برگردان
            if ($allHours && (!$start || !$end)) {
                $start = '00:00';
                $end   = '23:59';
            }

            $timeGroups[$key]['time_ranges'][] = [
                'start' => $start,
                'end'   => $end,
            ];
        }
        $timeRestrictions = array_values($timeGroups);

        // prayer
        $prRows = DB::table('access_prayer_restrictions')
            ->where('entity_table', 'doors')
            ->where('entity_id', $doorId)
            ->get();

        // گروه‌بندی مثل قبل: before/after + date_scope + date
        $prayerGroups = [];
        foreach ($prRows as $row) {
            $before = (int)$row->before_minutes;
            $after  = (int)$row->after_minutes;

            $attrs = $row->attrs ? (array)json_decode($row->attrs, true) : [];
            $dateScope = isset($attrs['date_scope']) && is_array($attrs['date_scope']) ? $attrs['date_scope'] : [];
            $date      = $row->specific_date ? (string)$row->specific_date : null;

            $eventCode  = $row->prayer_event ?? null;
            $eventLabel = $attrs['event_label'] ?? null;

            if ($eventLabel === null && $eventCode) {
                $eventLabel = $this->localizePrayerEvent($eventCode, 'fa');
            }

            $key = md5(json_encode([$before, $after, $dateScope, $date], JSON_UNESCAPED_UNICODE));

            if (!isset($prayerGroups[$key])) {
                $prayerGroups[$key] = [
                    'events'          => [],
                    'before_minutes'  => $before,
                    'after_minutes'   => $after,
                    'date_scope'      => $dateScope,
                    'date'            => $date,
                    'title'           => '',
                ];
            }

            if ($eventLabel && !in_array($eventLabel, $prayerGroups[$key]['events'], true)) {
                $prayerGroups[$key]['events'][] = $eventLabel;
            }
        }

        // ساخت title مثل قبل
        foreach ($prayerGroups as &$grp) {
            $events = $grp['events'];
            $before = $grp['before_minutes'];
            $after  = $grp['after_minutes'];

            if (!empty($events)) {
                $eventsTitle = implode(' و ', $events);
                $grp['title'] = $eventsTitle . ' : ' . $before . ' دقیقه قبل الی ' . $after . ' دقیقه بعد';
            }
        }
        unset($grp);

        $prayerRestrictions = array_values($prayerGroups);




        // 5) notes
        $notes = $notesMeta ?? '';

        // 6) خروجی نهایی در فرمت فرانت
        $response = [
            'basic_info' => [
                'title'       => $titles,
                'description' => $description,
            ],
            'grouping' => $grouping,
            'operational' => $operational,
            'routing'     => $routing,
            'time_restrictions'   => $timeRestrictions,
            'prayer_restrictions' => $prayerRestrictions,
            'notes'               => $notes,
        ];

        return response()->json($response);
    }



    /**
     * PUT /api/v1/doors/{id}/info
     *
     * ذخیره اطلاعات گام‌های مودال (basic_info, grouping, operational, time/prayer, notes)
     */
    public function saveInfo(Request $request, $id)
    {
        $data = $request->validate([
            'basic_info' => 'required|array',
            'basic_info.title' => 'required|array',
            'basic_info.title.fa' => 'required|string',
            'basic_info.description' => 'nullable|string',

            'grouping' => 'nullable|array',
            'grouping.group_id' => 'nullable|string',
            'grouping.sub_group_id' => 'nullable|string',
            'grouping.sub_group_label' => 'nullable|string',

            'operational' => 'nullable|array',
            'operational.status' => 'nullable|in:active,inactive',
            'operational.transport_modes' => 'nullable|array',
            'operational.transport_modes.*' => 'string',
            'operational.gender_access' => 'nullable|array',
            'operational.gender_access.*' => 'string',
            'operational.place_function' => 'nullable|string',
            'operational.is_covered' => 'nullable|boolean',

            'time_restrictions' => 'nullable|array',
            'time_restrictions.*.date_scope' => 'required|array|min:1',
            'time_restrictions.*.gender' => 'nullable|array',
            'time_restrictions.*.gender.*' => 'string',
            'time_restrictions.*.time_ranges' => 'nullable|array',
            'time_restrictions.*.time_ranges.*.start' => 'required_with:time_restrictions.*.time_ranges',
            'date_format:H:i',
            'time_restrictions.*.time_ranges.*.end' => 'required_with:time_restrictions.*.time_ranges',
            'date_format:H:i',
            'time_restrictions.*.all_hours' => 'required|boolean',

            'prayer_restrictions' => 'nullable|array',
            'prayer_restrictions.*.events'         => 'required|array|min:1',
            'prayer_restrictions.*.events.*'       => 'string',
            'prayer_restrictions.*.before_minutes' => 'required|integer',
            'prayer_restrictions.*.after_minutes'  => 'required|integer',
            'prayer_restrictions.*.date_scope'     => 'nullable|array',
            'prayer_restrictions.*.date_scope.*'   => 'string',
            'prayer_restrictions.*.date'           => 'nullable|string',
            'prayer_restrictions.*.title'          => 'nullable|string',

            'notes' => 'nullable|string',
            // routing
            'routing' => 'nullable|array',
            'routing.bidirectional' => 'nullable|boolean',
            'routing.from_area' => 'nullable|integer',
            'routing.to_area' => 'nullable|integer',

            'prayer_restrictions.*.gender' => 'nullable|array',

            'prayer_restrictions.*.gender.*' => 'required|string|in:male,female,both,family',
        ]);

        $doorId = (int) $id;

        // چک کنیم در هر ردیف حتماً یکی از date_scope یا date پر باشد
        foreach ($data['prayer_restrictions'] ?? [] as $idx => $pr) {
            $hasScope = !empty($pr['date_scope']);
            $hasDate  = isset($pr['date']) && $pr['date'] !== null && $pr['date'] !== '';

            if (!$hasScope && !$hasDate) {
                throw ValidationException::withMessages([
                    "prayer_restrictions.$idx" => 'برای هر محدودیت نماز، یکی از date_scope یا date باید پر باشد.',
                ]);
            }
        }
        DB::transaction(function () use ($doorId, $data) {
            // 1) عنوان‌ها
            $titles = $data['basic_info']['title'] ?? [];

            foreach ($titles as $lang => $txt) {
                $txt = trim((string)$txt);

                if ($txt === '') {
                    DB::table('i18n_texts')->where([
                        'entity_table' => 'doors',
                        'entity_id'    => $doorId,
                        'field'        => 'name',
                        'lang'         => $lang,
                    ])->delete();
                    continue;
                }

                DB::table('i18n_texts')->updateOrInsert(
                    [
                        'entity_table' => 'doors',
                        'entity_id'    => $doorId,
                        'field'        => 'name',
                        'lang'         => $lang,
                    ],
                    ['txt' => $txt]
                );
            }

            // 2) description + بقیه operational / grouping / notes در attrs
            $operReq   = $data['operational'] ?? [];
            $groupReq  = $data['grouping'] ?? [];
            $notesReq  = isset($data['notes']) ? trim((string)$data['notes']) : '';

            $desc = isset($data['basic_info']['description'])
                ? trim((string)$data['basic_info']['description'])
                : '';

            $operMeta = [
                'status'         => $operReq['status'] ?? null,
                'gender_access'  => $operReq['gender_access'] ?? [],
                'description'    => $desc,
                'place_function' => $operReq['place_function'] ?? 'door',
                'is_covered'     => $operReq['is_covered'] ?? null,
            ];

            $groupMeta = [
                'group_id'        => $groupReq['group_id'] ?? null,
                'sub_group_id'    => $groupReq['sub_group_id'] ?? null,
                'sub_group_label' => $groupReq['sub_group_label'] ?? null,
            ];

            $attrsPayload = [
                'operational' => $operMeta,
                'grouping'    => $groupMeta,
                'notes'       => $notesReq,
            ];

            $attrsJson = json_encode($attrsPayload, JSON_UNESCAPED_UNICODE);

            $transportModes = $operReq['transport_modes'] ?? [];

            $updates = [
                'attrs' => DB::raw("COALESCE(attrs, '{}'::jsonb) || '{$attrsJson}'::jsonb"),
            ];

            // ---- routing updates (from/to + bidirectional) ----
            $routingReq = $data['routing'] ?? [];

            // فقط bidirectional را هر وقت آمد آپدیت کن
            if (array_key_exists('bidirectional', $routingReq)) {
                $updates['bidirectional'] = (bool)$routingReq['bidirectional'];
            }

            /**
             * نکته مهم:
             * اگر فرانت from_area/to_area را نفرستاد یا null فرستاد،
             * ما نباید مقدار DB را پاک کنیم.
             * بنابراین فقط وقتی مقدار معتبر (عدد) داریم آپدیت می‌کنیم.
             */
            if (array_key_exists('from_area', $routingReq) && $routingReq['from_area'] !== null && $routingReq['from_area'] !== '') {
                $updates['from_area'] = (int)$routingReq['from_area'];
            }

            if (array_key_exists('to_area', $routingReq) && $routingReq['to_area'] !== null && $routingReq['to_area'] !== '') {
                $updates['to_area'] = (int)$routingReq['to_area'];
            }

            // status -> is_open
            if (!empty($operReq['status'])) {
                $updates['is_open'] = $operReq['status'] === 'active';
            }

            // modes -> text[]
            if (!empty($transportModes)) {
                $pgArray = 'ARRAY[' . collect($transportModes)->map(function ($m) {
                    $m = str_replace("'", "''", $m);
                    return "'{$m}'";
                })->implode(',') . ']::text[]';

                $updates['modes'] = DB::raw($pgArray);
            }

            DB::table('doors')->where('id', $doorId)->update($updates);

            // اگر جهت/مبدا/مقصد تغییر کرد، door_access_points هم sync شود
            if (!empty($routingReq) && (isset($updates['from_area']) || isset($updates['to_area']))) {
                DB::table('door_access_points')
                    ->where('door_id', $doorId)
                    ->update([
                        'from_area' => DB::raw('COALESCE((SELECT from_area FROM doors WHERE id = ' . $doorId . '), from_area)'),
                        'to_area'   => DB::raw('COALESCE((SELECT to_area   FROM doors WHERE id = ' . $doorId . '), to_area)'),
                    ]);
            }

            // بازسازی گراف همین درب (تا یکطرفه/دوطرفه فوری اثر کند)
            // DB::select("SELECT fn_rebuild_graph_for_door(:door_id)", [
            //     'door_id' => $doorId,
            // ]);

            // 3) access_*_restrictions: پاک و بازسازی (برای doors)
            DB::table('access_time_restrictions')
                ->where('entity_table', 'doors')
                ->where('entity_id', $doorId)
                ->delete();

            DB::table('access_prayer_restrictions')
                ->where('entity_table', 'doors')
                ->where('entity_id', $doorId)
                ->delete();

            // helper برای text[]
            $toPgTextArray = function (?array $arr) {
                $arr = $arr ?? [];
                $arr = array_values(array_filter($arr, fn($v) => $v !== null && $v !== ''));
                if (count($arr) === 0) return null;
                $pg = "ARRAY[" . collect($arr)->map(function ($m) {
                    $m = str_replace("'", "''", (string)$m);
                    return "'{$m}'";
                })->implode(',') . "]::text[]";
                return DB::raw($pg);
            };

            // helper مخصوص gender_enum[]
            $toPgGenderEnumArray = function (?array $arr) {
                $arr = $arr ?? [];
                $arr = array_map(
                    static fn($value) =>
                    strtolower(trim((string) $value)) === 'family'
                        ? 'both'
                        : strtolower(trim((string) $value)),
                    $arr
                );
                $arr = array_values(array_filter($arr, fn($v) => $v !== null && $v !== ''));

                // (اختیاری ولی خوب) فقط مقادیر مجاز
                $allowed = ['male', 'female', 'both'];
                $arr = array_values(array_filter($arr, fn($v) => in_array($v, $allowed, true)));

                if (count($arr) === 0) return null;

                $pg = "ARRAY[" . collect($arr)->map(function ($m) {
                    $m = str_replace("'", "''", (string)$m);
                    return "'{$m}'::gender_enum";
                })->implode(',') . "]::gender_enum[]";

                return DB::raw($pg);
            };


            // 3-الف) time_restrictions  -> access_time_restrictions
            foreach ($data['time_restrictions'] ?? [] as $tr) {
                $dateScope = $tr['date_scope'] ?? [];      // text[]
                $gender    = $tr['gender'] ?? [];          // text[]
                $allHours  = (bool)($tr['all_hours'] ?? false);
                $timeRanges = $tr['time_ranges'] ?? [];

                // اگر all_hours=true ولی time_ranges هم آمده، زمان‌ها را ذخیره کن
                if ($allHours && is_array($timeRanges) && count($timeRanges) > 0) {
                    foreach ($timeRanges as $range) {
                        DB::table('access_time_restrictions')->insert([
                            'entity_table'  => 'doors',
                            'entity_id'     => $doorId,
                            'gender'        => $toPgGenderEnumArray($gender),
                            'date_scope'    => $toPgTextArray($dateScope),
                            'specific_date' => null,
                            'start_time'    => $range['start'], // HH:MM
                            'end_time'      => $range['end'],   // HH:MM
                            'all_hours'     => true,            // همچنان true می‌ماند
                            'attrs'         => DB::raw("'{}'::jsonb"),
                        ]);
                    }
                    continue;
                }

                // حالت استاندارد all_hours=true بدون time_ranges => NULL
                if ($allHours) {
                    DB::table('access_time_restrictions')->insert([
                        'entity_table'  => 'doors',
                        'entity_id'     => $doorId,
                        'gender'        => $toPgGenderEnumArray($gender),
                        'date_scope'    => $toPgTextArray($dateScope),
                        'specific_date' => null,
                        'start_time'    => null,
                        'end_time'      => null,
                        'all_hours'     => true,
                        'attrs'         => DB::raw("'{}'::jsonb"),
                    ]);
                    continue;
                }

                // all_hours=false => به ازای هر بازه زمانی یک رکورد
                foreach ($tr['time_ranges'] as $range) {
                    DB::table('access_time_restrictions')->insert([
                        'entity_table'   => 'doors',
                        'entity_id'      => $doorId,
                        'gender'         => $toPgGenderEnumArray($gender),
                        'date_scope'     => $toPgTextArray($dateScope),
                        'specific_date'  => null,
                        'start_time'     => $range['start'], // HH:MM
                        'end_time'       => $range['end'],   // HH:MM
                        'all_hours'      => false,
                        'attrs'          => DB::raw("'{}'::jsonb"),
                    ]);
                }
            }

            // 3-ب) prayer_restrictions -> access_prayer_restrictions
            foreach ($data['prayer_restrictions'] ?? [] as $pr) {
                $before    = (int)$pr['before_minutes'];
                $after     = (int)$pr['after_minutes'];
                $dateScope = $pr['date_scope'] ?? null;  // چون جدول prayer ستون date_scope ندارد => می‌گذاریم داخل attrs
                $date      = $pr['date'] ?? null;        // اگر باشد => specific_date

                foreach ($pr['events'] as $eventLabelInput) {
                    // تبدیل لیبل به کد استاندارد
                    [$eventCode, $eventLabelFa] = $this->normalizePrayerEvent($eventLabelInput);

                    $attrs = [
                        'event_label' => $eventLabelFa,
                    ];
                    if (is_array($dateScope) && count($dateScope)) {
                        $attrs['date_scope'] = $dateScope;
                    }

                    DB::table('access_prayer_restrictions')->insert([
                        'entity_table'    => 'doors',
                        'entity_id'       => $doorId,
                        'prayer_event'    => $eventCode,               // fajr / dhuhr_asr / maghrib_isha ...
                        'before_minutes'  => $before,
                        'after_minutes'   => $after,
                        'specific_date'   => ($date && $date !== '') ? $date : null,
                        'gender' => $toPgGenderEnumArray(
                            $pr['gender'] ?? []
                        ),                     // فعلاً UI شما برای نماز جنسیت نمی‌فرستد
                        'attrs'           => DB::raw("'" . json_encode($attrs, JSON_UNESCAPED_UNICODE) . "'::jsonb"),
                    ]);
                }
            }
        });

        $this->queueDoorGraphRebuild((int) $doorId);

        return response()->json([
            'status' => 'ok',
            'message' => 'Door info saved. Graph rebuild queued.',
            'graph_status' => 'queued',
            'door' => [
                'id' => $doorId,
            ],
        ], 202);
    }

    private function parsePgArray($value): array
    {
        if ($value === null) return [];
        if (is_array($value)) return $value;

        $value = trim($value, '{}');
        if ($value === '') return [];

        $parts = explode(',', $value);
        return array_map(function ($item) {
            $item = trim($item);
            $item = trim($item, '"');
            return $item;
        }, $parts);
    }


    private function queueDoorGraphRebuild(int $doorId): void
    {
        $payload = json_encode([
            'status' => 'queued',
            'updated_at' => now()->toISOString(),
        ], JSON_UNESCAPED_UNICODE);

        DB::statement(
            "
        UPDATE public.doors
        SET attrs = jsonb_set(
            COALESCE(attrs, '{}'::jsonb),
            '{graph}',
            ?::jsonb,
            true
        )
        WHERE id = ?
        ",
            [
                $payload,
                $doorId,
            ]
        );

        RebuildDoorGraphJob::dispatch($doorId);
    }

    public function graphStatus(int $id)
    {
        $row = DB::table('doors')
            ->select('id', 'attrs')
            ->where('id', $id)
            ->first();

        if (!$row) {
            return response()->json([
                'message' => 'Door not found',
            ], 404);
        }

        $attrs = is_string($row->attrs)
            ? json_decode($row->attrs, true)
            : (array) $row->attrs;

        return response()->json([
            'door_id' => $id,
            'graph' => $attrs['graph'] ?? [
                'status' => 'unknown',
            ],
        ]);
    }

    private function queueAffectedAreasGraphRebuild(array $areaIds, int $floor): void
    {
        $areaIds = array_values(array_unique(array_filter($areaIds)));

        if (empty($areaIds)) {
            return;
        }

        RebuildAffectedAreasGraphJob::dispatch($areaIds, $floor)->afterCommit();
    }

    private function normalizeAllowedGender(array $values): string
    {
        $values = array_map(
            static function ($value) {
                $value = strtolower(trim((string) $value));

                if ($value === 'family') {
                    return 'both';
                }

                return $value;
            },
            $values
        );

        $values = array_values(array_unique(array_filter(
            $values,
            static fn($value) =>
            in_array($value, ['male', 'female', 'both'], true)
        )));

        if (in_array('both', $values, true)) {
            return 'both';
        }

        if (
            in_array('male', $values, true)
            && in_array('female', $values, true)
        ) {
            return 'both';
        }

        if (in_array('male', $values, true)) {
            return 'male';
        }

        if (in_array('female', $values, true)) {
            return 'female';
        }

        return 'both';
    }
}
