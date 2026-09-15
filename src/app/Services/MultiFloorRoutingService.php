<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MultiFloorRoutingService
{
    public function route(string $gender, string $mode, int $originFloor, int $destinationFloor, array $origin, array $destination, string $lang, int $alternatives): array
    {
        return DB::transaction(function () use ($gender, $mode, $originFloor, $destinationFloor, $origin, $destination, $lang, $alternatives) {
            $rows = DB::select('SELECT * FROM fn_route_multifloor_edges(now(), ?::gender_enum, ?::text, ?::smallint, ?::smallint,
                ST_Transform(ST_SetSRID(ST_MakePoint(?::float8,?::float8),4326),32640),
                ST_Transform(ST_SetSRID(ST_MakePoint(?::float8,?::float8),4326),32640),?::int)',
                [$gender,$mode,$originFloor,$destinationFloor,$origin['lon'],$origin['lat'],$destination['lon'],$destination['lat'],$alternatives+1]);
            if (!$rows) return ['status' => 'NO_PATH', 'message' => 'هیچ مسیر قابل دسترسی بین طبقات انتخاب‌شده یافت نشد.', 'steps' => [], 'alternatives' => []];
            $routes = [];
            foreach (collect($rows)->groupBy('path_id') as $path) $routes[] = $this->assemble($path->all(), $mode, $lang);
            $main = array_shift($routes);
            $main['alternatives'] = $routes;
            return $main;
        });
    }

    private function assemble(array $edges, string $mode, string $lang): array
    {
        $segments = []; $walk = []; $steps = []; $sahns = [];
        $flush = function () use (&$walk, &$segments, &$steps, &$sahns, $mode, $lang) {
            if (!$walk) return;
            $coordinates = []; $pathEdges = [];
            foreach ($walk as $edge) {
                $points = json_decode($edge->geometry_json, true)['coordinates'];
                foreach ($points as $point) {
                    if (!$coordinates || end($coordinates) !== $point) $coordinates[] = $point;
                }
                $pathEdges[] = ['seq'=>count($pathEdges)+1,'edgeId'=>$edge->edge_id,'doorId'=>$edge->door_id,
                    'fromNode'=>$edge->from_node,'toNode'=>$edge->to_node];
            }
            if (count($coordinates) === 1) $coordinates[] = $coordinates[0];
            $geom = ['type'=>'LineString','coordinates'=>$coordinates];
            $floor = (int) $walk[0]->from_floor;
            $row = DB::selectOne("SELECT fn_build_route_json(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?),4326),32640),?::smallint,?::text,?::lang_enum,'computed',?::jsonb) AS data",
                [json_encode($geom),$floor,$mode,$lang,json_encode($pathEdges)]);
            $built = json_decode($row->data, true);
            $segmentId = count($segments);
            $duration = array_sum(array_column($walk, 'duration_s'));
            $distance = array_sum(array_column($walk, 'distance_m'));
            $segmentSteps = [];
            foreach ($built['steps'] ?? [] as $step) {
                if (($step['type'] ?? '') === 'stepArriveDestination') continue;
                $step['floor'] = $floor; $step['segmentId'] = $segmentId;
                $segmentSteps[] = $step;
                $steps[] = $step;
            }
            $segments[] = ['id'=>$segmentId,'kind'=>'walk','floor'=>$floor,'geometry'=>$geom,
                'distance_m'=>$distance,'duration_s'=>$duration,'steps'=>$segmentSteps];
            $sahns = array_merge($sahns, $built['sahns'] ?? []);
            $walk = [];
        };
        foreach ($edges as $edge) {
            if ($edge->connector_id === null) { $walk[] = $edge; continue; }
            $flush();
            $connector = DB::table('routing_connectors')->find($edge->connector_id);
            $info = json_decode($connector->info, true);
            $title = data_get($info, "basic_info.title.$lang") ?: data_get($info, 'basic_info.title.fa', '');
            $from = json_decode($edge->from_point, true)['coordinates'];
            $to = json_decode($edge->to_point, true)['coordinates'];
            $fromFloor = (int) $edge->from_floor; $toFloor = (int) $edge->to_floor;
            $segmentId = count($segments);
            $instruction = match ($lang) {
                'en' => "Take {$title} to floor {$toFloor}.",
                'ar' => "استخدم {$title} إلى الطابق {$toFloor}.",
                'ur' => "{$title} سے منزل {$toFloor} پر جائیں۔",
                default => "از {$title} به طبقهٔ {$toFloor} بروید.",
            };
            $step = ['type'=>'stepChangeFloor','title'=>$title,'instruction'=>$instruction,
                'coord'=>['lat'=>$from[1],'lon'=>$from[0]],'endCoord'=>['lat'=>$to[1],'lon'=>$to[0]],
                'floor'=>$fromFloor,'fromFloor'=>$fromFloor,'toFloor'=>$toFloor,
                'connectorId'=>(int)$edge->connector_id,'connectorType'=>$edge->connector_kind,
                'segmentId'=>$segmentId,'duration_s'=>(float)$edge->duration_s,'distance_m'=>0];
            $steps[] = $step;
            $segments[] = ['id'=>$segmentId,'kind'=>$edge->connector_kind,'floor'=>$fromFloor,
                'fromFloor'=>$fromFloor,'toFloor'=>$toFloor,'connectorId'=>(int)$edge->connector_id,
                'fromPoint'=>$from,'toPoint'=>$to,'geometry'=>null,'duration_s'=>(float)$edge->duration_s,
                'distance_m'=>0,'steps'=>[$step]];
        }
        $flush();
        $end = json_decode(end($edges)->to_point, true)['coordinates'];
        $steps[] = ['type'=>'stepArriveDestination','title'=>'','coord'=>['lat'=>$end[1],'lon'=>$end[0]],
            'floor'=>(int)end($edges)->to_floor,'segmentId'=>count($segments)-1];
        foreach ($steps as $i => &$step) $step['stepOrder'] = $i + 1;
        unset($step);
        $distance = array_sum(array_column($segments,'distance_m'));
        $duration = array_sum(array_column($segments,'duration_s'));
        $lines = array_values(array_map(fn ($s) => $s['geometry']['coordinates'], array_filter($segments, fn ($s) => $s['kind']==='walk')));
        $multifloor = count(array_filter($segments, fn ($s) => $s['kind']!=='walk')) > 0;
        return ['status'=>'OK','source'=>'computed','distanceMeters'=>$distance,'estimatedMinutes'=>$duration/60,
            'steps'=>$steps,'sahns'=>$sahns,'segments'=>$segments,'multifloor'=>$multifloor,
            'geo'=>['type'=>'Feature','geometry'=>['type'=>$multifloor ? 'MultiLineString' : 'LineString','coordinates'=>$multifloor ? $lines : ($lines[0] ?? [])],
                'properties'=>['segments'=>$segments,'multifloor'=>$multifloor,'distanceMeters'=>$distance,'durationSeconds'=>$duration]]];
    }
}
