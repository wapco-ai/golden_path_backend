<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SameFloorStairService
{
    public function __construct(private ConnectorService $connectors) {}

    public function index(int $floor): array
    {
        return [
            'data' => $this->records(DB::table('same_floor_stairs')->where('floor', $floor)->orderBy('id')),
        ];
    }

    public function forAccessPoint(int $accessId): ?array
    {
        if (!Schema::hasTable('same_floor_stairs')) {
            return null;
        }

        $stairs = $this->records(DB::table('same_floor_stairs')->where(function (Builder $query) use ($accessId) {
            $query->where('start_access_id', $accessId)->orWhere('end_access_id', $accessId);
        })->limit(2));

        return count($stairs) === 1 ? $stairs[0] : null;
    }

    public function candidates(array $landings): array
    {
        // The bounding boxes are a coarse filter. The graph's validator checks exact geometry.
        return DB::select(<<<'SQL'
            WITH nearby AS MATERIALIZED (
                SELECT a.id, a.floor, lower_dap.id AS start_access_id, upper_dap.id AS end_access_id
                FROM areas a
                JOIN door_access_points lower_dap ON lower_dap.id = ? AND lower_dap.floor = a.floor
                JOIN door_access_points upper_dap ON upper_dap.id = ? AND upper_dap.floor = a.floor
                WHERE a.floor = ? AND a.area_type = 'stair_area'
                  AND a.geom && ST_Expand(lower_dap.geom, 1.0)
                  AND a.geom && ST_Expand(upper_dap.geom, 1.0)
            )
            SELECT a.id, a.floor, COALESCE(t.txt, 'محدوده ' || a.id) AS name
            FROM nearby a
            CROSS JOIN LATERAL fn_same_floor_stair_candidate(
                a.id, a.floor, a.start_access_id, a.end_access_id, ?::bigint, ?::bigint
            ) candidate
            LEFT JOIN i18n_texts t ON t.entity_table = 'areas' AND t.entity_id = a.id
                AND t.field = 'name' AND t.lang = 'fa'
            ORDER BY name, a.id
            SQL, [
            $landings['start_access_id'], $landings['end_access_id'], $landings['floor'],
            $landings['start_area_id'], $landings['end_area_id'],
        ]);
    }

    private function show(int $id): array
    {
        $records = $this->records(DB::table('same_floor_stairs')->where('id', $id));
        abort_if(!$records, 404);

        return $records[0];
    }

    private function lockStair(int $id, int $version): object
    {
        $stair = DB::table('same_floor_stairs')->where('id', $id)->lockForUpdate()->first();
        abort_unless($stair, 404);
        abort_if($version !== (int) $stair->version, 409, 'اطلاعات پله تغییر کرده است. فرم را دوباره باز کنید.');

        return $stair;
    }

    public function deactivate(int $id, int $version): array
    {
        return DB::transaction(function () use ($id, $version) {
            $stair = $this->lockStair($id, $version);
            if ($stair->is_active) {
                $info = json_decode($stair->info, true, flags: JSON_THROW_ON_ERROR);
                $info['operational']['status'] = 'inactive';
                DB::table('same_floor_stairs')->where('id', $id)->update([
                    'is_active' => false,
                    'info' => json_encode($info, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'version' => $version + 1,
                    'updated_at' => now(),
                ]);
            }

            return $this->show($id);
        }, 3);
    }

    public function save(array $data, ?int $id = null): array
    {
        [$lower, $upper] = $data['stops'];
        $floor = (int) $lower['floor'];
        if ((int) $upper['floor'] !== $floor) {
            throw ValidationException::withMessages(['stops.1.floor' => 'هر دو پاگرد باید در یک طبقه باشند.']);
        }

        $id = DB::transaction(function () use ($data, $id, $floor, $lower, $upper) {
            $stairArea = DB::table('areas')->where('id', $data['stair_area_id'])->where('floor', $floor)
                ->where('area_type', 'stair_area')->lockForUpdate()->first(['id']);
            if (!$stairArea) {
                throw ValidationException::withMessages(['stair_area_id' => 'محدودهٔ پله را در همین طبقه انتخاب کنید.']);
            }

            $version = 1;
            if ($id !== null) {
                $current = $this->lockStair($id, (int) ($data['version'] ?? 0));
                $version = (int) $current->version + 1;
            }

            $duplicate = DB::table('same_floor_stairs')->where('stair_area_id', $stairArea->id)
                ->when($id !== null, fn (Builder $query) => $query->where('id', '<>', $id))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['stair_area_id' => 'برای این محدوده پله ثبت شده است. همان پله را برای ویرایش انتخاب کنید.']);
            }

            DB::table('door_access_points')->whereIn('id', [$lower['access_id'], $upper['access_id']])
                ->orderBy('id')->lockForUpdate()->get(['id']);
            foreach ([$lower, $upper] as $position => $stop) {
                $candidateIds = array_map(fn ($area) => (int) $area->id, $this->connectors->candidates($stop));
                if (!in_array((int) $stop['area_id'], $candidateIds, true)) {
                    throw ValidationException::withMessages(["stops.$position.area_id" => 'فضای قابل‌عبور متصل به این پاگرد را انتخاب کنید.']);
                }
            }

            $info = $data['info'];
            $info['operational']['place_function'] = 'same_floor_stair';
            $values = [
                'stair_area_id' => $stairArea->id,
                'floor' => $floor,
                'start_access_id' => $lower['access_id'],
                'start_area_id' => $lower['area_id'],
                'end_access_id' => $upper['access_id'],
                'end_area_id' => $upper['area_id'],
                'direction' => $data['direction'],
                'travel_seconds' => $lower['travel_seconds'] ?? null,
                'reverse_seconds' => $lower['reverse_seconds'] ?? null,
                'info' => json_encode($info, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'is_active' => $info['operational']['status'] === 'active',
                'version' => $version,
                'updated_at' => now(),
            ];
            if ($id === null) {
                $id = DB::table('same_floor_stairs')->insertGetId($values);
            } else {
                DB::table('same_floor_stairs')->where('id', $id)->update($values);
            }

            if (!DB::scalar('SELECT fn_same_floor_stair_edge_geom(?::bigint) IS NOT NULL', [$id])) {
                throw ValidationException::withMessages(['stops' => 'مسیر بین دو پاگرد باید از محدودهٔ پله بگذرد و داخل فضای مجاز بماند. نقطه‌ها و آماده‌بودن گراف آن‌ها را بررسی کنید.']);
            }

            return (int) $id;
        }, 3);

        return $this->show($id);
    }

    private function records(Builder $query): array
    {
        $stairs = $query->get();
        if ($stairs->isEmpty()) {
            return [];
        }

        $accessIds = $stairs->flatMap(fn ($stair) => [$stair->start_access_id, $stair->end_access_id])->filter()->unique();
        $points = DB::table('door_access_points')->whereIn('id', $accessIds)->selectRaw(
            'id, door_id, floor, from_area, to_area, needs_review, ST_Y(ST_Transform(geom,4326)) AS lat, ST_X(ST_Transform(geom,4326)) AS lon'
        )->get()->keyBy('id');
        $areaIds = $stairs->flatMap(fn ($stair) => [$stair->start_area_id, $stair->end_area_id])->filter()->unique();
        $names = DB::table('i18n_texts')->where('entity_table', 'areas')->where('field', 'name')
            ->where('lang', 'fa')->whereIn('entity_id', $areaIds)->pluck('txt', 'entity_id');

        return $stairs->map(function ($stair) use ($points, $names) {
            $stops = [];
            foreach (['start', 'end'] as $position => $end) {
                $accessId = $stair->{$end.'_access_id'};
                $areaId = $stair->{$end.'_area_id'};
                $point = $points->get($accessId);
                $valid = $point && (int) $point->floor === (int) $stair->floor && !$point->needs_review
                    && in_array((int) $areaId, [(int) $point->from_area, (int) $point->to_area], true);
                $stops[] = [
                    'floor' => $stair->floor, 'position' => $position,
                    'access_id' => $accessId, 'door_id' => $point?->door_id,
                    'area_id' => $areaId, 'area_name' => $names->get($areaId, 'محدوده '.$areaId),
                    'lat' => $point?->lat, 'lon' => $point?->lon,
                    'error' => $valid ? null : ConnectorService::INVALID_ACCESS_MESSAGE,
                    'travel_seconds' => $stair->travel_seconds !== null ? (float) $stair->travel_seconds : null,
                    'reverse_seconds' => $position === 0 && $stair->reverse_seconds !== null ? (float) $stair->reverse_seconds : null,
                ];
            }

            $info = json_decode($stair->info, true, flags: JSON_THROW_ON_ERROR);
            $info['operational']['status'] = $stair->is_active ? 'active' : 'inactive';

            return [
                'id' => $stair->id, 'kind' => 'same_floor_stair', 'floor' => $stair->floor,
                'stair_area_id' => $stair->stair_area_id, 'version' => $stair->version,
                'direction' => $stair->direction, 'is_active' => $stair->is_active,
                'wait_seconds' => 0, 'info' => $info, 'stops' => $stops,
            ];
        })->all();
    }
}
