<?php

namespace App\Services\Admin;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * خلاصه کلی داشبورد
     */
    public function summary(): array
    {
        // TODO: نام جدول‌ها/ستون‌ها را با پروژه خودت تطبیق بده
        $totalUsers = DB::table('users')->count();

        // route_logs: فرض بر این است که ستون ok و created_at دارد
        $successfulNavigations = DB::table('route_logs')
            ->where('ok', true)
            ->count();

        // فرض: poi_points و ستون has_content یا مشابه
        $culturalCenters = DB::table('poi_points')
            ->where('has_content', true)
            ->count();

        $lastUpdated = DB::table('route_logs')->max('ts');

        return [
            'totalUsers'            => (int)$totalUsers,
            'successfulNavigations' => (int)$successfulNavigations,
            'culturalCenters'       => (int)$culturalCenters,
            'lastUpdated'           => $lastUpdated ? Carbon::parse($lastUpdated)->toDateTimeString() : null,
        ];
    }

    /**
     * آمار بازدید کاربران
     * خروجی: labels[], data[], maxYAxis
     */
    public function userVisits(string $range): array
    {
        // اینجا فرض کردیم route_logs.meta دارای user_id/userId است و created_at هم داریم
        // اگر شما جدول دیگری برای Visit دارید، همین بخش Query را عوض کن.

        return match ($range) {
            'week'    => $this->visitsByLastDays(7,  $this->weekdayFaLabels()),
            'month'   => $this->visitsByLastDays(30, $this->persianNumberLabels(1, 30)),
            'quarter' => $this->visitsByWeeks(12),
            'year'    => $this->visitsByMonths(12),
            default   => $this->visitsByLastDays(7,  $this->weekdayFaLabels()),
        };
    }

    public function commentStats(string $range): array
    {
        $to = Carbon::now();
        $from = match ($range) {
            'week'    => $to->copy()->subDays(6)->startOfDay(),
            'month'   => $to->copy()->subDays(29)->startOfDay(),
            'quarter' => $to->copy()->subDays(89)->startOfDay(),
            'year'    => $to->copy()->subDays(364)->startOfDay(),
            default   => $to->copy()->subDays(6)->startOfDay(),
        };

        // user_feedbacks: فرض status: approved/rejected/pending
        $base = DB::table('user_feedbacks')
            ->whereBetween('created_at', [$from, $to]);

        $total    = (clone $base)->count();
        $approved = (clone $base)->where('status', 'approved')->count();
        $rejected = (clone $base)->where('status', 'rejected')->count();

        return [
            'total'    => (int)$total,
            'approved' => (int)$approved,
            'rejected' => (int)$rejected,
        ];
    }

    /** The feed is derived from source rows; only per-admin acknowledgements persist. */
    public function notifications(int $adminUserId, int $limit = 10, bool $unreadOnly = false, bool $includeMeta = false): array
    {
        $sources = $this->notificationSourcesSql();
        // Count and page share one statement snapshot. Filter before LIMIT so
        // older unread rows are not hidden behind a page of read notifications.
        $row = DB::selectOne(<<<SQL
            WITH sources AS ({$sources}),
            feed AS (
                SELECT sources.*, receipts.read_at IS NOT NULL AS is_read
                FROM sources
                LEFT JOIN public.admin_notification_reads AS receipts
                    ON receipts.admin_user_id = ?::bigint
                    AND receipts.notification_type = sources.notification_type
                    AND receipts.entity_id = sources.entity_id
            ),
            page AS (
                SELECT * FROM feed
                WHERE (?::boolean = false OR is_read = false)
                ORDER BY created_at DESC, notification_type ASC, entity_id DESC
                LIMIT ?
            )
            SELECT
                (SELECT COUNT(*) FROM feed WHERE is_read = false) AS unread_count,
                COALESCE((
                    SELECT jsonb_agg(jsonb_build_object(
                        'id', notification_type || ':' || entity_id::text,
                        'type', notification_type,
                        'title', title,
                        'message', message,
                        'entityId', entity_id,
                        'createdAt', to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
                        'read', is_read
                    ) ORDER BY created_at DESC, notification_type ASC, entity_id DESC)
                    FROM page
                ), '[]'::jsonb) AS items
            SQL, [$adminUserId, $unreadOnly, $limit]);

        $items = json_decode($row->items, true, 512, JSON_THROW_ON_ERROR);

        return $includeMeta ? ['items' => $items, 'unreadCount' => (int) $row->unread_count] : $items;
    }

    /** Acknowledge only the explicitly requested, currently existing source rows. */
    public function markNotificationsRead(int $adminUserId, array $ids): array
    {
        $values = [];
        $bindings = [];
        foreach ($ids as $id) {
            [$type, $entityId] = explode(':', $id, 2);
            $values[] = '(?::text, ?::bigint)';
            array_push($bindings, $type, $entityId);
        }

        $sources = $this->notificationSourcesSql();
        $requested = implode(', ', $values);
        DB::statement(<<<SQL
            WITH sources AS ({$sources}),
            requested(notification_type, entity_id) AS (VALUES {$requested})
            INSERT INTO public.admin_notification_reads (admin_user_id, notification_type, entity_id)
            SELECT ?::bigint, sources.notification_type, sources.entity_id
            FROM sources
            INNER JOIN requested
                ON requested.notification_type = sources.notification_type
                AND requested.entity_id = sources.entity_id
            WHERE true
            ORDER BY sources.notification_type ASC, sources.entity_id ASC
            ON CONFLICT (admin_user_id, notification_type, entity_id) DO NOTHING
            SQL, [...$bindings, $adminUserId]);

        return ['success' => true, 'unreadCount' => $this->unreadNotificationCount($adminUserId)];
    }

    /** One INSERT SELECT snapshots all existing sources, including those beyond the page limit. */
    public function markAllNotificationsRead(int $adminUserId): array
    {
        $sources = $this->notificationSourcesSql();
        DB::statement(<<<SQL
            WITH sources AS ({$sources})
            INSERT INTO public.admin_notification_reads (admin_user_id, notification_type, entity_id)
            SELECT ?::bigint, notification_type, entity_id FROM sources WHERE true
            ORDER BY notification_type ASC, entity_id ASC
            ON CONFLICT (admin_user_id, notification_type, entity_id) DO NOTHING
            SQL, [$adminUserId]);

        return ['success' => true, 'unreadCount' => $this->unreadNotificationCount($adminUserId)];
    }

    private function unreadNotificationCount(int $adminUserId): int
    {
        $sources = $this->notificationSourcesSql();
        $row = DB::selectOne(<<<SQL
            WITH sources AS ({$sources})
            SELECT COUNT(*) AS unread_count FROM sources
            WHERE NOT EXISTS (
                SELECT 1 FROM public.admin_notification_reads AS receipts
                WHERE receipts.admin_user_id = ?::bigint
                    AND receipts.notification_type = sources.notification_type
                    AND receipts.entity_id = sources.entity_id
            )
            SQL, [$adminUserId]);

        return (int) $row->unread_count;
    }

    private function notificationSourcesSql(): string
    {
        // users.created_at stores naive UTC; the other source timestamps carry
        // zones. Normalize to timestamptz before ordering or formatting them.
        // Undated rows are not new events and receive no invented timestamp.
        $sql = <<<'SQL'
            SELECT 'new_user'::text AS notification_type, id AS entity_id,
                created_at AT TIME ZONE 'UTC' AS created_at,
                'ثبت‌نام جدید'::text AS title,
                CASE WHEN COALESCE(name, '') <> '' THEN name || ' ثبت‌نام کرد'
                    ELSE 'کاربر جدید ثبت‌نام کرد' END AS message
            FROM public.users
            WHERE is_admin = false AND created_at IS NOT NULL AND id > 0
            UNION ALL
            SELECT 'new_comment'::text, id, created_at,
                'دیدگاه جدید'::text,
                COALESCE(NULLIF(title, ''), NULLIF(LEFT(body, 80), ''), 'یک دیدگاه جدید ثبت شد')
            FROM public.user_feedbacks
            WHERE created_at IS NOT NULL AND id > 0
            SQL;

        if ($this->tableExists('support_feedbacks')) {
            $sql .= <<<'SQL'

                UNION ALL
                SELECT 'support_message'::text, id, created_at,
                    'پیام پشتیبانی'::text,
                    'کاربر: ' || TRIM(COALESCE(subject, 'پیام جدید'))
                FROM public.support_feedbacks
                WHERE created_at IS NOT NULL AND id > 0
                SQL;
        }

        return $sql;
    }


    /**
     * کاربران اخیر + successCount
     * successCount از route_logs.meta->user_id/userId خوانده می‌شود (اگر موجود باشد)
     */
    public function recentUsers(?string $search, int $page, int $pageSize): array
    {
        $q = DB::table('users');

        if ($search) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('mobile', 'ilike', "%{$search}%");
            });
        }

        $total = (clone $q)->count();

        $users = (clone $q)
            ->orderByDesc('created_at')
            ->forPage($page, $pageSize)
            ->get(['id', 'name', 'email', 'mobile', 'created_at', 'birth_date', 'gender', 'address', 'last_login_at']);

        // successCount: اگر meta.user_id / meta.userId ثبت شده باشد
        $ids = $users->pluck('id')->map(fn($v) => (int)$v)->all();

        $counts = [];
        if ($this->tableExists('route_logs')) {
            // Legacy meta may contain malformed identifiers. Guard before casting
            // so an unrelated log cannot fail the whole dashboard request.
            $userIdSql = "CASE WHEN COALESCE(meta->>'user_id', meta->>'userId') ~ '^[0-9]{1,19}$'
                THEN COALESCE(meta->>'user_id', meta->>'userId')::numeric END";
            $rows = DB::table('route_logs')
                ->selectRaw("{$userIdSql} as uid, COUNT(*)::int as cnt")
                ->where('ok', true)
                ->whereIn(DB::raw($userIdSql), $ids)
                ->groupBy('uid')
                ->get();

            foreach ($rows as $r) {
                $counts[(int)$r->uid] = (int)$r->cnt;
            }
        }

        $data = [];
        foreach ($users as $u) {
            $address = json_decode($u->address ?? '{}', true);
            $address = is_array($address) ? $address : [];
            $data[] = [
                'id'           => (int)$u->id,
                'name'         => $u->name,
                'email'        => $u->email,
                'mobile'       => $u->mobile,
                'phone'        => $u->mobile,
                // Birthdays are calendar dates, not instants to timezone-shift.
                'birthDate'    => $u->birth_date,
                'gender'       => in_array($u->gender, ['male', 'female', 'other'], true) ? $u->gender : null,
                'province'     => is_string($address['province'] ?? null) ? $address['province'] : null,
                'city'         => is_string($address['city'] ?? null) ? $address['city'] : null,
                // users.created_at is naive UTC; never replace unknown history with now().
                'joinDate'     => $u->created_at === null ? null : Carbon::parse($u->created_at, 'UTC')->utc()->toISOString(),
                'lastLoginAt'  => $u->last_login_at === null ? null : Carbon::parse($u->last_login_at)->utc()->toISOString(),
                'successCount' => $counts[(int)$u->id] ?? 0,
                // No server source currently records saved routes or confirmed place visits.
                'savedRoutesCount' => null,
                'placeVisitsCount' => null,
            ];
        }

        return [
            'data' => $data,
            'pagination' => [
                'page'     => $page,
                'pageSize' => $pageSize,
                'total'    => (int)$total,
                'pages'    => (int)ceil($total / max(1, $pageSize)),
            ],
        ];
    }

    // ------------------------
    // Helpers
    // ------------------------

    private function visitsByLastDays(int $days, array $labels): array
    {
        $to = Carbon::now()->endOfDay();
        $from = Carbon::now()->subDays($days - 1)->startOfDay();

        $rows = DB::table('route_logs')
            ->selectRaw("DATE(ts) as d, COUNT(*)::int as c")
            ->whereBetween('ts', [$from, $to])
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r->d] = (int)$r->c;
        }

        $data = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();
            $data[] = $map[$day] ?? 0;
        }

        $max = max($data ?: [0]);

        return [
            'labels'   => $labels,
            'data'     => $data,
            'maxYAxis' => $this->niceMax($max),
        ];
    }

    private function visitsByWeeks(int $weeks): array
    {
        $to = Carbon::now()->endOfDay();
        $from = Carbon::now()->subWeeks($weeks - 1)->startOfWeek();

        $rows = DB::table('route_logs')
            ->selectRaw("DATE_TRUNC('week', ts) as w, COUNT(*)::int as c")
            ->whereBetween('ts', [$from, $to])
            ->groupBy('w')
            ->orderBy('w')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[Carbon::parse($r->w)->toDateString()] = (int)$r->c;
        }

        $labels = [];
        $data = [];
        for ($i = 0; $i < $weeks; $i++) {
            $start = $from->copy()->addWeeks($i)->toDateString();
            $labels[] = "هفته " . $this->toPersianNumber((string)($i + 1));
            $data[] = $map[$start] ?? 0;
        }

        $max = max($data ?: [0]);

        return [
            'labels'   => $labels,
            'data'     => $data,
            'maxYAxis' => $this->niceMax($max),
        ];
    }

    private function visitsByMonths(int $months): array
    {
        $to = Carbon::now()->endOfMonth();
        $from = Carbon::now()->subMonths($months - 1)->startOfMonth();

        $rows = DB::table('route_logs')
            ->selectRaw("DATE_TRUNC('month', ts) as m, COUNT(*)::int as c")
            ->whereBetween('ts', [$from, $to])
            ->groupBy('m')
            ->orderBy('m')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[Carbon::parse($r->m)->format('Y-m')] = (int)$r->c;
        }

        $labels = [];
        $data = [];
        for ($i = 0; $i < $months; $i++) {
            $d = $from->copy()->addMonths($i);
            $labels[] = $this->monthNameFa((int)$d->month);
            $data[] = $map[$d->format('Y-m')] ?? 0;
        }

        $max = max($data ?: [0]);

        return [
            'labels'   => $labels,
            'data'     => $data,
            'maxYAxis' => $this->niceMax($max),
        ];
    }

    private function weekdayFaLabels(): array
    {
        // آخرین 7 روز: از 6 روز قبل تا امروز
        $base = Carbon::now()->subDays(6);
        $names = [];
        for ($i = 0; $i < 7; $i++) {
            $names[] = $this->weekdayNameFa((int)$base->copy()->addDays($i)->dayOfWeekIso);
        }
        return $names;
    }

    private function weekdayNameFa(int $iso): string
    {
        return match ($iso) {
            1 => 'دوشنبه',
            2 => 'سه‌شنبه',
            3 => 'چهارشنبه',
            4 => 'پنجشنبه',
            5 => 'جمعه',
            6 => 'شنبه',
            7 => 'یکشنبه',
            default => '—',
        };
    }

    private function monthNameFa(int $m): string
    {
        return match ($m) {
            1 => 'ژانویه',
            2 => 'فوریه',
            3 => 'مارس',
            4 => 'آوریل',
            5 => 'مه',
            6 => 'ژوئن',
            7 => 'ژوئیه',
            8 => 'اوت',
            9 => 'سپتامبر',
            10 => 'اکتبر',
            11 => 'نوامبر',
            12 => 'دسامبر',
            default => '—',
        };
    }

    private function persianNumberLabels(int $from, int $to): array
    {
        $out = [];
        for ($i = $from; $i <= $to; $i++) {
            $out[] = $this->toPersianNumber((string)$i);
        }
        return $out;
    }

    private function toPersianNumber(string $s): string
    {
        $map = ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'];
        return strtr($s, $map);
    }

    private function niceMax(int $max): int
    {
        if ($max <= 0) return 10;
        $p = pow(10, (int)floor(log10($max)));
        $n = (int)ceil($max / $p) * $p;
        return max(10, $n);
    }

    private function tableExists(string $name): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($name);
        } catch (\Throwable) {
            return false;
        }
    }
}
