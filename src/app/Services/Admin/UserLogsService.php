<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;

class UserLogsService
{
    public function list(
        ?string $search,
        int $page,
        int $pageSize,
        string $sortBy,
        string $sortOrder,
        ?string $fromDate,
        ?string $toDate,
    ): array {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $sortOrder = strtolower($sortOrder) === 'asc' ? 'asc' : 'desc';
        $sortBy = $sortBy ?: 'lastLogin';

        $base = $this->baseUsersQuery($search, $fromDate, $toDate);

        $total = (clone $base)->count('u.id');

        $rows = $this->applySorting(
            q: $base,
            sortBy: $sortBy,
            sortOrder: $sortOrder
        )
            ->forPage($page, $pageSize)
            ->get([
                'u.id',
                'u.name as full_name',
                DB::raw("COALESCE(u.last_login_at, ull.last_login_at) as last_login_at"),
                DB::raw("COALESCE(ra.successful_routes, 0)::int as successful_routes"),
                DB::raw("COALESCE(ra.total_routes, 0)::int as total_routes"),
                DB::raw("lr.last_routing_ts as last_routing_ts"),
                DB::raw("lr.last_meta as last_meta"),
            ]);

        $items = [];
        foreach ($rows as $r) {
            $meta = $this->jsonToArray($r->last_meta);

            $items[] = [
                'id'               => (int)$r->id,
                'fullName'         => $r->full_name,
                'lastLogin'        => $r->last_login_at ? $this->toIsoZ($r->last_login_at) : null,
                'successfulRoutes' => (int)$r->successful_routes,
                'totalRoutes'      => (int)$r->total_routes,
                'lastRoutingDate'  => $r->last_routing_ts ? substr((string)$r->last_routing_ts, 0, 10) : null,
                'lastRouting'      => $r->last_routing_ts ? $this->buildLastRoutingText($meta) : null,
            ];
        }

        return [
            'items' => $items,
            'pagination' => [
                'page'     => $page,
                'pageSize' => $pageSize,
                'total'    => (int)$total,
                'pages'    => (int)ceil($total / max(1, $pageSize)),
            ],
        ];
    }

    /**
     * Stream export CSV rows (بدون اینکه همه داده‌ها یکجا در RAM بیاید)
     */
    public function streamExportCsv(
        $out,
        ?string $search,
        string $sortBy,
        string $sortOrder,
        ?string $fromDate,
        ?string $toDate,
    ): void {
        $sortOrder = strtolower($sortOrder) === 'asc' ? 'asc' : 'desc';
        $sortBy = $sortBy ?: 'lastLogin';

        $q = $this->applySorting(
            q: $this->baseUsersQuery($search, $fromDate, $toDate),
            sortBy: $sortBy,
            sortOrder: $sortOrder
        );

        // chunk برای پرفورمنس
        $q->orderBy('u.id')
            ->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $r) {
                    $meta = $this->jsonToArray($r->last_meta);

                    fputcsv($out, [
                        (int)$r->id,
                        $r->full_name,
                        $r->last_login_at ? $this->toIsoZ($r->last_login_at) : '',
                        (int)$r->successful_routes,
                        (int)$r->total_routes,
                        $r->last_routing_ts ? substr((string)$r->last_routing_ts, 0, 10) : '',
                        $r->last_routing_ts ? $this->buildLastRoutingText($meta) : '',
                    ]);
                }
            }, column: 'u.id');
    }

    // ----------------------------
    // Query Builder
    // ----------------------------

    private function baseUsersQuery(?string $search, ?string $fromDate, ?string $toDate)
    {
        $search = is_string($search) ? trim($search) : null;
        $fromDate = is_string($fromDate) ? trim($fromDate) : null;
        $toDate = is_string($toDate) ? trim($toDate) : null;

        // login aggregate
        $loginAgg = DB::table('user_login_logs')
            ->selectRaw('user_id, MAX(created_at) as last_login_at')
            ->where('success', true)
            ->groupBy('user_id');

        // route aggregate
        $routeAgg = DB::table('route_logs')
            ->selectRaw("
                COALESCE((meta->>'user_id')::int, (meta->>'userId')::int) as uid,
                COUNT(*)::int as total_routes,
                COUNT(*) FILTER (WHERE ok = true)::int as successful_routes,
                MAX(ts) as last_routing_ts
            ")
            ->whereRaw("COALESCE((meta->>'user_id')::int, (meta->>'userId')::int) IS NOT NULL")
            ->groupBy('uid');

        // last route per user (meta + ts)
        $lastRoute = DB::table('route_logs')
            ->selectRaw("
                DISTINCT ON (COALESCE((meta->>'user_id')::int, (meta->>'userId')::int))
                COALESCE((meta->>'user_id')::int, (meta->>'userId')::int) as uid,
                ts as last_routing_ts,
                meta as last_meta
            ")
            ->whereRaw("COALESCE((meta->>'user_id')::int, (meta->>'userId')::int) IS NOT NULL")
            ->orderByRaw("COALESCE((meta->>'user_id')::int, (meta->>'userId')::int), ts DESC");

        $q = DB::table('users as u')
            ->where('u.is_admin', false)
            ->leftJoinSub($loginAgg, 'ull', fn($j) => $j->on('ull.user_id', '=', 'u.id'))
            ->leftJoinSub($routeAgg, 'ra', fn($j) => $j->on('ra.uid', '=', 'u.id'))
            ->leftJoinSub($lastRoute, 'lr', fn($j) => $j->on('lr.uid', '=', 'u.id'));

        // search روی نام + (متن meta آخرین مسیر)
        if ($search !== null && $search !== '') {
            $like = '%' . $search . '%';
            $q->where(function ($w) use ($like) {
                $w->where('u.name', 'ilike', $like)
                  ->orWhereRaw("COALESCE(lr.last_meta::text, '') ILIKE ?", [$like]);
            });
        }

        // فیلتر تاریخ (روی ts مسیرها) اگر لازم باشد
        if ($fromDate) {
            $q->whereRaw(" (lr.last_routing_ts IS NULL OR lr.last_routing_ts::date >= ?::date) ", [$fromDate]);
        }
        if ($toDate) {
            $q->whereRaw(" (lr.last_routing_ts IS NULL OR lr.last_routing_ts::date <= ?::date) ", [$toDate]);
        }

        return $q;
    }

    private function applySorting($q, string $sortBy, string $sortOrder)
    {
        // نکته: NULLS LAST برای lastLogin / lastRouting
        return match ($sortBy) {
            'fullName' => $q->orderBy('u.name', $sortOrder),

            'successfulRoutes' => $q->orderByRaw("COALESCE(ra.successful_routes, 0) {$sortOrder}"),

            'totalRoutes' => $q->orderByRaw("COALESCE(ra.total_routes, 0) {$sortOrder}"),

            'lastRoutingDate' => $q->orderByRaw("lr.last_routing_ts {$sortOrder} NULLS LAST"),

            default => $q->orderByRaw("COALESCE(u.last_login_at, ull.last_login_at) {$sortOrder} NULLS LAST"),
        };
    }

    // ----------------------------
    // Formatting helpers
    // ----------------------------

    private function buildLastRoutingText(?array $meta): string
    {
        $o = data_get($meta, 'request.origin');
        $d = data_get($meta, 'request.destination');

        $oTxt = $this->formatEndpoint($o);
        $dTxt = $this->formatEndpoint($d);

        return trim($oTxt) . ' → ' . trim($dTxt);
    }

    private function formatEndpoint($ep): string
    {
        if (!is_array($ep)) return 'unknown';

        $type = $ep['type'] ?? 'unknown';

        // coordinate
        if ($type === 'coordinate') {
            $lat = $ep['lat'] ?? null;
            $lon = $ep['lon'] ?? null;
            if (is_numeric($lat) && is_numeric($lon)) {
                $lat = number_format((float)$lat, 6, '.', '');
                $lon = number_format((float)$lon, 6, '.', '');
                return "coord($lat,$lon)";
            }
            return "coord";
        }

        // by id/code
        if (isset($ep['id']) && is_numeric($ep['id'])) {
            return "{$type}#" . (int)$ep['id'];
        }
        if (!empty($ep['code'])) {
            return "{$type}:" . (string)$ep['code'];
        }

        return (string)$type;
    }

    private function jsonToArray($val): ?array
    {
        if ($val === null) return null;
        if (is_array($val)) return $val;

        if (is_string($val)) {
            $j = json_decode($val, true);
            return is_array($j) ? $j : null;
        }

        // jsonb ممکن است به شکل stdClass بیاید
        if (is_object($val)) {
            $j = json_decode(json_encode($val, JSON_UNESCAPED_UNICODE), true);
            return is_array($j) ? $j : null;
        }

        return null;
    }

    private function toIsoZ($ts): string
    {
        // خروجی قابل parse در JS
        return \Carbon\Carbon::parse($ts)->utc()->toIso8601ZuluString();
    }
}