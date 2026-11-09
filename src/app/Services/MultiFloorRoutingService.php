<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MultiFloorRoutingService
{
    public function route(string $gender, string $mode, int $originFloor, int $destinationFloor, array $origin, array $destination, string $lang, int $alternatives, bool $allowNearbyDestination = false, ?string $destinationName = null): array
    {
        return DB::transaction(function () use ($gender, $mode, $originFloor, $destinationFloor, $origin, $destination, $lang, $alternatives, $allowNearbyDestination, $destinationName) {
            $endpointArguments = 'now(), ?::gender_enum, ?::text, ?::smallint, ?::smallint,
                ST_Transform(ST_SetSRID(ST_MakePoint(?::float8,?::float8),4326),32640),
                ST_Transform(ST_SetSRID(ST_MakePoint(?::float8,?::float8),4326),32640)';
            $bindings = [$gender, $mode, $originFloor, $destinationFloor, $origin['lon'], $origin['lat'], $destination['lon'], $destination['lat']];
            $privateOrigin = $allowNearbyDestination && DB::scalar("SELECT EXISTS (
                SELECT 1 FROM areas a WHERE a.floor=?::smallint
                    AND a.attrs #>> '{access_policy,public_access}'='false'
                    AND ST_Contains(a.geom, ST_Transform(ST_SetSRID(ST_MakePoint(?::float8,?::float8),4326),32640))
            )", [$originFloor, $origin['lon'], $origin['lat']]);
            $rows = $privateOrigin ? [] : $this->selectEdges("fn_route_multifloor_edges($endpointArguments, ?::int)", [...$bindings, $alternatives + 1]);
            if (!$rows && $allowNearbyDestination) {
                $nearbyRows = $privateOrigin ? [] : $this->selectEdges("fn_route_nearby_edges($endpointArguments)", $bindings);
                $nearbyOrigin = !$nearbyRows;
                if ($nearbyOrigin) {
                    $nearbyRows = $this->selectEdges("fn_route_nearby_origin_edges($endpointArguments)", $bindings);
                }
                if ($nearbyRows) {
                    $route = $this->assemble($nearbyRows, $mode, $lang);
                    if ($nearbyOrigin) {
                        [$lon, $lat] = json_decode($nearbyRows[0]->from_point, true)['coordinates'];
                        $route['geo']['properties']['nearbyOrigin'] = [
                            'coordinates' => [$lat, $lon],
                            'floor' => (int) $nearbyRows[0]->from_floor,
                        ];
                        if ($route['steps'][0]['type'] === 'stepStart') {
                            $route['steps'][0]['instruction'] = match ($lang) {
                                'en' => 'Start at the marked point near your selected origin.',
                                'ar' => 'ابدأ من النقطة المحددة قرب نقطة انطلاقك.',
                                'ur' => 'اپنے منتخب مقام کے قریب نشان زدہ نقطے سے شروع کریں۔',
                                default => 'مسیر را از نقطهٔ مشخص‌شده در نزدیکی مبدأ شروع کنید.',
                            };
                        }
                    }
                    if (!$nearbyOrigin || $nearbyRows[0]->nearby_destination) {
                        $lastStep = array_key_last($route['steps']);
                        $step = $route['steps'][$lastStep];
                        $route['steps'][$lastStep]['instruction'] = $this->nearbyArrivalInstruction($lang, $destinationName);
                        $route['geo']['properties']['nearbyDestination'] = [
                            'coordinates' => [$step['coord']['lat'], $step['coord']['lon']],
                            'floor' => $step['floor'],
                        ];
                    }
                    $route['alternatives'] = [];

                    return $route;
                }
            }
            if (!$rows) return ['status' => 'NO_PATH', 'message' => 'هیچ مسیر قابل دسترسی بین طبقات انتخاب‌شده یافت نشد.', 'steps' => [], 'alternatives' => []];
            $routes = [];
            foreach (collect($rows)->groupBy('path_id') as $path) $routes[] = $this->assemble($path->all(), $mode, $lang);
            $main = array_shift($routes);
            $main['alternatives'] = $routes;
            return $main;
        });
    }

    private function selectEdges(string $functionCall, array $bindings): array
    {
        return DB::select("
            SELECT route_edges.*, stair.src AS stair_lower_node, stair.attrs->>'stair_id' AS stair_id
            FROM $functionCall AS route_edges
            LEFT JOIN routing_edges_static AS stair
                ON stair.id = route_edges.edge_id
                AND stair.attrs->>'edge_type' = 'same_floor_stair'
                AND route_edges.from_node IN (stair.src, stair.dst)
                AND route_edges.to_node IN (stair.src, stair.dst)
            ORDER BY route_edges.path_id, route_edges.path_seq
        ", $bindings);
    }

    private function nearbyArrivalInstruction(string $lang, ?string $destinationName): string
    {
        return match ($lang) {
            'en' => 'You have reached the closest accessible point to '.($destinationName ?: 'your destination').'.',
            'ar' => 'وصلت إلى أقرب نقطة متاحة من '.($destinationName ?: 'وجهتك').'.',
            'ur' => 'آپ '.($destinationName ?: 'اپنی منزل').' کے قریب ترین قابلِ رسائی مقام پر پہنچ گئے ہیں۔',
            default => 'به نزدیک‌ترین نقطهٔ قابل‌دسترسی به '.($destinationName ?: 'مقصد').' رسیدید.',
        };
    }

    private function assemble(array $edges, string $mode, string $lang): array
    {
        $segments = []; $walk = []; $steps = []; $sahns = [];
        $flush = function () use (&$walk, &$segments, &$steps, &$sahns, $mode, $lang) {
            if (!$walk) return;
            $coordinates = []; $pathEdges = []; $stairSteps = [];
            $distance = array_sum(array_column($walk, 'distance_m'));
            $walkedDistance = 0.0;
            foreach ($walk as $edge) {
                $points = json_decode($edge->geometry_json, true)['coordinates'];
                foreach ($points as $point) {
                    if (!$coordinates || end($coordinates) !== $point) $coordinates[] = $point;
                }
                $pathEdges[] = ['seq'=>count($pathEdges)+1,'edgeId'=>$edge->edge_id,'doorId'=>$edge->door_id,
                    'fromNode'=>$edge->from_node,'toNode'=>$edge->to_node];
                if ($edge->stair_id !== null && $distance > 0) {
                    $stairSteps[] = $this->stairStep($edge, $points, $walkedDistance, $distance, $lang);
                }
                $walkedDistance += (float) $edge->distance_m;
            }
            if (count($coordinates) === 1) $coordinates[] = $coordinates[0];
            $geom = ['type'=>'LineString','coordinates'=>$coordinates];
            $floor = (int) $walk[0]->from_floor;
            $row = DB::selectOne("SELECT fn_build_route_json(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?),4326),32640),?::smallint,?::text,?::lang_enum,'computed',?::jsonb) AS data",
                [json_encode($geom),$floor,$mode,$lang,json_encode($pathEdges)]);
            $built = json_decode($row->data, true);
            $segmentId = count($segments);
            $duration = array_sum(array_column($walk, 'duration_s'));
            $builtSteps = array_merge($built['steps'] ?? [], $stairSteps);
            if ($stairSteps) {
                usort($builtSteps, fn ($a, $b) => $a['routeM'] <=> $b['routeM']);
            }
            $segmentSteps = [];
            foreach ($builtSteps as $step) {
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
            'floor'=>(int)end($edges)->to_floor,'segmentId'=>count($segments)-1,'routeM'=>1];
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

    private function stairStep(object $edge, array $points, float $walkedDistance, float $distance, string $lang): array
    {
        $up = (int) $edge->from_node === (int) $edge->stair_lower_node;
        $instruction = match ($lang) {
            'en' => $up ? 'Go up the stairs.' : 'Go down the stairs.',
            'ar' => $up ? 'اصعد الدرج.' : 'انزل الدرج.',
            'ur' => $up ? 'سیڑھیوں سے اوپر جائیں۔' : 'سیڑھیوں سے نیچے جائیں۔',
            default => $up ? 'از پله‌ها بالا بروید.' : 'از پله‌ها پایین بروید.',
        };
        [$fromLon, $fromLat] = $points[0];
        [$toLon, $toLat] = end($points);

        return [
            'type' => 'stepUseStairs',
            'title' => $instruction,
            'instruction' => $instruction,
            'coord' => ['lat' => $fromLat, 'lon' => $fromLon],
            'endCoord' => ['lat' => $toLat, 'lon' => $toLon],
            'routeM' => $walkedDistance / $distance,
            'endRouteM' => min(1.0, ($walkedDistance + $edge->distance_m) / $distance),
            'edgeId' => (int) $edge->edge_id,
            'stairId' => (int) $edge->stair_id,
            'stairDirection' => $up ? 'up' : 'down',
            'distance_m' => (float) $edge->distance_m,
            'duration_s' => (float) $edge->duration_s,
        ];
    }
}
