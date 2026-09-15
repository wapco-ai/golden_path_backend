<?php

namespace App\Services;

use App\Http\Controllers\Api\DoorCrudController;
use App\Jobs\RebuildDoorGraphJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConnectorService
{
    public const KINDS = ['stair', 'ramp', 'elevator', 'escalator'];

    public function candidates(array $point): array
    {
        $floor = (int) $point['floor'];
        if (!empty($point['access_id'])) {
            $dap = DB::table('door_access_points')->where('id', $point['access_id'])->first();
            if (!$dap || (int) $dap->floor !== $floor || $dap->needs_review) {
                throw ValidationException::withMessages(['access_id' => 'نقطهٔ دسترسی معتبر در این طبقه انتخاب کنید.']);
            }
            return DB::select("SELECT a.id, a.floor, COALESCE(t.txt, 'محدوده ' || a.id) AS name
                FROM areas a LEFT JOIN LATERAL (SELECT txt FROM i18n_texts
                  WHERE entity_table='areas' AND entity_id=a.id AND field='name' AND lang='fa' LIMIT 1) t ON true
                WHERE a.floor=? AND a.id IN (?,?) AND fn_area_routing_role(a.area_type,a.attrs)='routable'
                ORDER BY a.id", [$floor, $dap->from_area, $dap->to_area]);
        }
        if (!isset($point['lat'], $point['lon'])) {
            throw ValidationException::withMessages(['point' => 'نقطهٔ دسترسی را روی نقشه مشخص کنید.']);
        }
        return DB::select("WITH p AS (SELECT ST_Transform(ST_SetSRID(ST_MakePoint(?::float8,?::float8),4326),32640) AS geom)
            SELECT a.id, a.floor, COALESCE(t.txt, 'محدوده ' || a.id) AS name
            FROM areas a CROSS JOIN p LEFT JOIN LATERAL (SELECT txt FROM i18n_texts
              WHERE entity_table='areas' AND entity_id=a.id AND field='name' AND lang='fa' LIMIT 1) t ON true
            WHERE a.floor=? AND fn_area_routing_role(a.area_type,a.attrs)='routable'
              AND ST_DWithin(a.geom,p.geom,0.75) ORDER BY a.id", [$point['lon'], $point['lat'], $floor]);
    }

    public function show(int $id): array
    {
        $connector = DB::table('routing_connectors')->find($id);
        abort_unless($connector, 404);
        $stops = DB::select("SELECT s.id,s.floor,s.door_id,s.position,s.area_id,
            COALESCE(s.access_id, resolved.id) AS access_id,s.travel_seconds,s.reverse_seconds,
            ST_Y(ST_Transform(dap.geom,4326)) AS lat,ST_X(ST_Transform(dap.geom,4326)) AS lon,
            COALESCE(t.txt,'محدوده ' || s.area_id) AS area_name
            FROM routing_connector_stops s
            LEFT JOIN LATERAL (SELECT min(d.id) AS id FROM door_access_points d
              WHERE d.door_id=s.door_id AND d.floor=s.floor AND NOT d.needs_review
                AND s.area_id IN (d.from_area,d.to_area) HAVING count(*)=1) resolved ON true
            LEFT JOIN door_access_points dap ON dap.id=COALESCE(s.access_id,resolved.id)
            LEFT JOIN LATERAL (SELECT txt FROM i18n_texts WHERE entity_table='areas'
              AND entity_id=s.area_id AND field='name' AND lang='fa' LIMIT 1) t ON true
            WHERE s.connector_id=? ORDER BY s.position", [$id]);
        $info = json_decode($connector->info, true);
        $info['operational']['status'] = $connector->is_active ? 'active' : 'inactive';
        return [
            'id' => $connector->id, 'kind' => $connector->kind, 'version' => $connector->version,
            'direction' => $connector->direction, 'wait_seconds' => (float) $connector->wait_seconds,
            'info' => $info, 'stops' => $stops,
        ];
    }

    public function forDoor(int $doorId): ?array
    {
        $id = DB::table('routing_connector_stops')->where('door_id', $doorId)->value('connector_id');
        return $id ? $this->show((int) $id) : null;
    }

    public function save(array $data, ?int $id = null): array
    {
        $modes = $data['info']['operational']['transport_modes'];
        if (in_array($data['kind'], ['stair', 'escalator'], true) && in_array('wheelchair', $modes, true)) {
            throw ValidationException::withMessages(['info.operational.transport_modes' => 'عبور ویلچر از پله و پله‌برقی مجاز نیست.']);
        }
        if ($data['kind'] === 'escalator' && $data['direction'] === 'both') {
            throw ValidationException::withMessages(['direction' => 'جهت حرکت پله‌برقی را انتخاب کنید.']);
        }
        $id = DB::transaction(function () use ($data, $id) {
            $previous = collect();
            if ($id !== null) {
                $current = DB::table('routing_connectors')->where('id', $id)->lockForUpdate()->first();
                abort_unless($current, 404);
                abort_if((int) ($data['version'] ?? 0) !== $current->version, 409, 'اطلاعات اتصال تغییر کرده است؛ فرم را دوباره باز کنید.');
                $previous = DB::table('routing_connector_stops')->where('connector_id', $id)->get();
                $version = $current->version + 1;
            } else {
                $version = 1;
                $id = DB::table('routing_connectors')->insertGetId(['kind' => $data['kind']]);
            }
            $stops = [];
            foreach ($data['stops'] as $position => $stop) {
                $candidates = $this->candidates($stop);
                $areaIds = array_map(fn ($area) => (int) $area->id, $candidates);
                $area = isset($stop['area_id']) ? (int) $stop['area_id'] : (count($areaIds) === 1 ? $areaIds[0] : null);
                if (!$area || !in_array($area, $areaIds, true)) {
                    throw ValidationException::withMessages(["stops.$position.area_id" => count($areaIds) ? 'فضای دسترسی این توقف مبهم است؛ یکی از فضاهای نمایش‌داده‌شده را انتخاب کنید.' : 'فضای قابل‌تردد کنار این نقطه یافت نشد.']);
                }
                if (!empty($stop['access_id'])) {
                    $dap = DB::table('door_access_points')->where('id', $stop['access_id'])->lockForUpdate()->first();
                    $doorId = (int) $dap->door_id;
                    DB::table('doors')->where('id', $doorId)->lockForUpdate()->first();
                    $other = DB::table('routing_connector_stops')->where('door_id', $doorId)->where('connector_id', '<>', $id)->exists();
                    if ($other) throw ValidationException::withMessages(["stops.$position.access_id" => 'این نقطه عضو اتصال دیگری است.']);
                    $accessId = (int) $dap->id;
                } else {
                    // The clicked point is the DAP geometry source. doors.geom is display only.
                    $row = DB::selectOne("WITH p AS (
                        SELECT ST_ClosestPoint(a.geom,ST_Transform(ST_SetSRID(ST_MakePoint(?::float8,?::float8),4326),32640)) AS geom
                        FROM areas a WHERE a.id=? AND a.floor=?
                      ), d AS (
                        INSERT INTO doors(geom,from_area,to_area,floor,is_open,attrs)
                        SELECT ST_MakeLine(ST_Translate(p.geom,-0.10,0),ST_Translate(p.geom,0.10,0)),?,NULL,?,false,
                          '{\"connector_managed\":true}'::jsonb FROM p RETURNING id
                      ) INSERT INTO door_access_points(door_id,geom,floor,from_area,to_area,confidence,build_method,needs_review)
                        SELECT d.id,p.geom,?,?,NULL,1,'connector_stop',false FROM d CROSS JOIN p RETURNING id,door_id",
                        [$stop['lon'], $stop['lat'], $area, $stop['floor'], $area, $stop['floor'], $stop['floor'], $area]);
                    $doorId = (int) $row->door_id; $accessId = (int) $row->id;
                }
                if (in_array($doorId, array_column($stops, 'door_id'), true)) {
                    throw ValidationException::withMessages(["stops.$position.access_id" => 'نقطهٔ تکراری در توقف‌ها مجاز نیست.']);
                }
                $stops[] = [
                    'connector_id' => $id, 'door_id' => $doorId, 'access_id' => $accessId,
                    'area_id' => $area, 'floor' => $stop['floor'], 'position' => $position,
                    'travel_seconds' => $stop['travel_seconds'] ?? ($data['kind'] === 'elevator' ? 8 : 30),
                    'reverse_seconds' => $stop['reverse_seconds'] ?? null,
                ];
            }
            DB::table('routing_connector_stops')->where('connector_id', $id)->delete();
            DB::table('routing_connector_stops')->insert($stops);
            $info = $data['info'];
            $info['operational']['place_function'] = $data['kind'];
            // Vertical direction is independent of local threshold from_area/to_area.
            $info['routing'] = ['bidirectional' => true];
            $controller = app(DoorCrudController::class);
            foreach ($stops as $stop) {
                $controller->saveInfo(Request::create('/', 'PUT', $info), $stop['door_id'], true);
            }
            foreach ($previous as $removed) {
                if (in_array((int) $removed->door_id, array_column($stops, 'door_id'), true)) continue;
                DB::table('doors')->where('id', $removed->door_id)
                    ->whereRaw("attrs->>'connector_managed'='true'")->update(['is_open' => false]);
            }
            DB::table('routing_connectors')->where('id', $id)->update([
                'kind' => $data['kind'], 'info' => json_encode($info, JSON_UNESCAPED_UNICODE),
                'is_active' => $info['operational']['status'] === 'active',
                'direction' => $data['direction'],
                'wait_seconds' => $data['kind'] === 'elevator' ? ($data['wait_seconds'] ?? 30) : 0,
                'version' => $version, 'updated_at' => now(),
            ]);
            return $id;
        });
        return $this->show($id);
    }
}
