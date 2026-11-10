<?php

namespace Tests\Feature;

use App\Services\MultiFloorRoutingService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SameFloorStairRouteOutputTest extends TestCase
{
    public static function directions(): array
    {
        return [
            'ascending' => [false, 'up', 'از پله‌ها بالا بروید.'],
            'descending' => [true, 'down', 'از پله‌ها پایین بروید.'],
        ];
    }

    #[DataProvider('directions')]
    public function test_stair_edge_preserves_walk_geometry_and_emits_a_same_floor_step(
        bool $reverse, string $direction, string $instruction
    ): void {
        $line = [
            [59.61540, 36.28845], [59.61546, 36.28849], [59.61550, 36.28853],
            [59.61554, 36.28855], [59.61560, 36.28858],
        ];
        $nodes = [-1, 21, 22, -2];
        if ($reverse) {
            $line = array_reverse($line);
            $nodes = [-1, 22, 21, -2];
        }

        $edges = [];
        foreach ([[$line[0], $line[1]], array_slice($line, 1, 3), [$line[3], $line[4]]] as $index => $points) {
            $stair = $index === 1;
            $edges[] = (object) [
                'path_id' => 1,
                'edge_id' => $stair ? 60 : null,
                'door_id' => null,
                'connector_id' => null,
                'stair_id' => $stair ? '9' : null,
                'stair_lower_node' => $stair ? 21 : null,
                'from_floor' => -1,
                'to_floor' => -1,
                'from_node' => $nodes[$index],
                'to_node' => $nodes[$index + 1],
                'geometry_json' => json_encode(['type' => 'LineString', 'coordinates' => $points]),
                'to_point' => json_encode(['type' => 'Point', 'coordinates' => end($points)]),
                'distance_m' => $stair ? 6.0 : 2.0,
                'duration_s' => $stair ? 7.0 : 2.0,
            ];
        }

        DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
        DB::shouldReceive('select')->once()->withArgs(fn ($sql) => str_contains($sql, "stair.attrs->>'edge_type' = 'same_floor_stair'"))
            ->andReturn($edges);
        DB::shouldReceive('selectOne')->once()->andReturn((object) ['data' => json_encode([
            'steps' => [['type' => 'stepStart', 'routeM' => 0], ['type' => 'stepArriveDestination', 'routeM' => 1]],
        ])]);

        $route = (new MultiFloorRoutingService)->route(
            'female', 'walk', -1, -1,
            ['lon' => $line[0][0], 'lat' => $line[0][1]],
            ['lon' => $line[4][0], 'lat' => $line[4][1]],
            'fa', 0,
        );

        $this->assertFalse($route['multifloor']);
        $this->assertSame(['type' => 'LineString', 'coordinates' => $line], $route['geo']['geometry']);
        $this->assertSame(['walk'], array_column($route['segments'], 'kind'));
        $this->assertSame(['stepStart', 'stepUseStairs', 'stepArriveDestination'], array_column($route['steps'], 'type'));
        $step = $route['steps'][1];
        $this->assertSame($direction, $step['stairDirection']);
        $this->assertSame($instruction, $step['instruction']);
        $this->assertSame(-1, $step['floor']);
        $this->assertSame(['lat' => $line[1][1], 'lon' => $line[1][0]], $step['coord']);
        $this->assertSame(['lat' => $line[3][1], 'lon' => $line[3][0]], $step['endCoord']);
        $this->assertSame(0.2, $step['routeM']);
        $this->assertSame(0.8, $step['endRouteM']);
        $this->assertSame(11.0, $route['duration_s']);
        $this->assertSame(2.0, $step['elapsed_s']);
        $this->assertSame(9.0, $step['end_elapsed_s']);
        $this->assertSame(7.0, $step['duration_s']);
        $this->assertSame(9.0, $step['span_duration_s']);
    }
}
