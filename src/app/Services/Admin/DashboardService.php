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

    /**
     * نوتیفیکیشن‌ها
     * فعلاً read/unread واقعی نداریم (اگر جدول داشتی می‌سازیم/وصل می‌کنیم)
     */
    public function notifications(int $limit = 10, bool $unreadOnly = false): array
    {
        // طبق کانتکست فرانت: خروجی باید "آرایه خام" باشد، بدون wrapper
        // read/unread واقعی نداریم -> فعلاً همه false (اگر داری بعداً وصلش می‌کنیم)
        // createdAt باید ISO 8601 باشد و JS بتواند parse کند.

        $items = [];

        // 1) new_user
        $latestUsers = DB::table('users')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'name', 'created_at']);

        foreach ($latestUsers as $u) {
            $entityId = (int)$u->id;
            $type = 'new_user';

            $createdAt = $u->created_at
                ? \Carbon\Carbon::parse($u->created_at)->utc()->toIso8601ZuluString()
                : null;

            $items[] = [
                // id ترجیحی است؛ برای جلوگیری از تداخل typeها، از typeCode*1e9 + entityId می‌سازیم
                'id'        => 1000000000 + $entityId,
                'type'      => $type,
                'title'     => 'ثبت‌نام جدید',
                'message'   => $u->name ? "{$u->name} ثبت‌نام کرد" : "کاربر جدید ثبت‌نام کرد",
                'entityId'  => $entityId,
                'createdAt' => $createdAt,
                'read'      => false,
            ];
        }

        // 2) new_comment (user_feedbacks)
        $latestComments = DB::table('user_feedbacks')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'title', 'body', 'created_at']);


        foreach ($latestComments as $c) {
            $msg = $c->title ?: ($c->body ? mb_substr($c->body, 0, 80) : 'یک دیدگاه جدید ثبت شد');

            $items[] = [
                'type'      => 'new_comment',
                'title'     => 'دیدگاه جدید',
                'message'   => $msg,
                'entityId'  => (int)$c->id,
                'createdAt' => Carbon::parse($c->created_at)->toDateTimeString(),
                'read'      => false,
            ];
        }


        // 3) support_message (support_feedbacks اگر وجود داشت)
        if ($this->tableExists('support_feedbacks')) {
            $latestSupport = DB::table('support_feedbacks')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get(['id', 'subject', 'created_at']);

            foreach ($latestSupport as $s) {
                $entityId = (int)$s->id;
                $type = 'support_message';

                $createdAt = $s->created_at
                    ? \Carbon\Carbon::parse($s->created_at)->utc()->toIso8601ZuluString()
                    : null;

                $subject = trim((string)($s->subject ?? 'پیام جدید'));
                $msg = "کاربر: {$subject}";

                $items[] = [
                    'id'        => 3000000000 + $entityId,
                    'type'      => $type,
                    'title'     => 'پیام پشتیبانی',
                    'message'   => $msg,
                    'entityId'  => $entityId,
                    'createdAt' => $createdAt,
                    'read'      => false,
                ];
            }
        }

        // مرتب‌سازی نزولی بر اساس createdAt
        usort($items, fn($a, $b) => strcmp((string)$b['createdAt'], (string)$a['createdAt']));

        // unreadOnly: چون read واقعی نداریم فعلاً اثری ندارد (اما ساختار پارامتر حفظ می‌شود)
        if ($unreadOnly) {
            $items = array_values(array_filter($items, fn($x) => empty($x['read'])));
        }

        // خروجی: آرایه خام
        return array_slice($items, 0, $limit);
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
            ->get(['id', 'name', 'email', 'mobile', 'created_at']);

        // successCount: اگر meta.user_id / meta.userId ثبت شده باشد
        $ids = $users->pluck('id')->map(fn($v) => (int)$v)->all();

        $counts = [];
        if ($this->tableExists('route_logs')) {
            $rows = DB::table('route_logs')
                ->selectRaw("
                    COALESCE((meta->>'user_id')::int, (meta->>'userId')::int) as uid,
                    COUNT(*)::int as cnt
                ")
                ->where('ok', true)
                ->whereRaw("COALESCE((meta->>'user_id')::int, (meta->>'userId')::int) IS NOT NULL")
                ->whereIn(DB::raw("COALESCE((meta->>'user_id')::int, (meta->>'userId')::int)"), $ids)
                ->groupBy('uid')
                ->get();

            foreach ($rows as $r) {
                $counts[(int)$r->uid] = (int)$r->cnt;
            }
        }

        $data = [];
        foreach ($users as $u) {
            $data[] = [
                'id'           => (int)$u->id,
                'name'         => $u->name,
                'email'        => $u->email,
                'mobile' => $u->mobile,
                'joinDate'     => Carbon::parse($u->created_at)->toDateTimeString(),
                'successCount' => $counts[(int)$u->id] ?? 0,
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
