<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Services\MultiFloorRoutingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RouteAlternativeDiversityTest extends TestCase
{
    private bool $transactionStarted = false;
    private array $origin;
    private array $destination;
    private array $corridorAreas = [];
    private array $corridorDoors = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL/PostGIS/pgRouting database.');
        }
        Queue::fake();
        DB::beginTransaction();
        $this->transactionStarted = true;
        DB::table('routing_floors')->insertOrIgnore(['floor'=>1,'label'=>'Floor 1','sort_order'=>1]);
        $this->origin = $this->point(10,60);
        $this->destination = $this->point(90,60);
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function point(float $x, float $y): array
    {
        $p = DB::selectOne('SELECT ST_X(g) lon,ST_Y(g) lat FROM (
            SELECT ST_Transform(ST_SetSRID(ST_MakePoint(?::float8,?::float8),32640),4326) g
        ) p', [500000+$x,4000000+$y]);
        return ['lat'=>(float)$p->lat,'lon'=>(float)$p->lon];
    }

    private function area(array $bounds, int $floor = 0): int
    {
        return (int) DB::selectOne("INSERT INTO areas(geom,area_type,floor)
            VALUES (ST_Multi(ST_MakeEnvelope(?::float8,?::float8,?::float8,?::float8,32640)),
            'courtyard',?) RETURNING id",
            [500000+$bounds[0],4000000+$bounds[1],500000+$bounds[2],4000000+$bounds[3],$floor])->id;
    }

    private function door(int $from, int $to, float $x, float $y): int
    {
        $door = (int) DB::selectOne("INSERT INTO doors(geom,from_area,to_area,floor,attrs)
            VALUES (ST_MakeLine(ST_SetSRID(ST_MakePoint(?::float8,?::float8),32640),
                ST_SetSRID(ST_MakePoint(?::float8,?::float8),32640)),?,?,0,
                '{\"graph\":{\"status\":\"ready\"}}') RETURNING id",
            [500000+$x,4000000+$y-0.25,500000+$x,4000000+$y+0.25,$from,$to])->id;
        DB::insert('INSERT INTO door_access_points(door_id,geom,floor,from_area,to_area,needs_review)
            VALUES (?,ST_SetSRID(ST_MakePoint(?::float8,?::float8),32640),0,?,?,false)',
            [$door,500000+$x,4000000+$y,$from,$to]);
        return $door;
    }

    private function buildCorridors(bool $withDistinctCorridors = true): void
    {
        $start = $this->area([0,0,20,120]);
        $finish = $this->area([80,0,100,120]);
        $middle = $this->area([20,40,80,70]);
        $this->corridorAreas['middle'] = $middle;
        foreach ([55.0,55.2] as $y) {
            $this->corridorDoors['middle'][] = $this->door($start,$middle,20,$y);
            $this->corridorDoors['middle'][] = $this->door($middle,$finish,80,$y);
        }
        if ($withDistinctCorridors) {
            foreach (['north'=>[[20,70,80,120],95.0],'south'=>[[20,0,80,40],20.0]] as $name=>$config) {
                $area = $this->area($config[0]);
                $this->corridorAreas[$name] = $area;
                $this->corridorDoors[$name] = [
                    $this->door($start,$area,20,$config[1]),
                    $this->door($area,$finish,80,$config[1]),
                ];
            }
        }
        $this->rebuild([0]);
    }

    private function rebuild(array $floors): void
    {
        foreach ($floors as $floor) {
            DB::select('SELECT fn_build_routing_nodes(?::smallint)',[$floor]);
            $result = DB::selectOne('SELECT fn_build_routing_edges(?::smallint) data',[$floor]);
            $this->assertSame(0,json_decode($result->data,true)['areas_failed']);
        }
        DB::statement("UPDATE doors SET attrs=jsonb_set(attrs,'{graph}','{\"status\":\"ready\"}')");
        DB::statement('REFRESH MATERIALIZED VIEW mv_area_door_stats');
    }

    private function route(int $alternatives = 2, string $mode = 'walk', int $from = 0, int $to = 0): array
    {
        return app(MultiFloorRoutingService::class)->route(
            'both',$mode,$from,$to,$this->origin,$this->destination,'fa',$alternatives
        );
    }

    private function applySql(string $direction): void
    {
        $sql = file_get_contents(database_path('sql/20261005_170000_diverse_route_alternatives.'.$direction.'.sql'));
        DB::unprepared(preg_replace('/^(?:BEGIN|COMMIT);[ \\t]*\\r?$/m','',$sql));
    }

    private function functionDefinition(): string
    {
        return DB::scalar("SELECT pg_get_functiondef(
            'fn_route_multifloor_edges(timestamptz,gender_enum,text,smallint,smallint,geometry,geometry,integer)'::regprocedure
        )");
    }

    private function usedCorridor(array $route): string
    {
        $visited = array_column($route['sahns'],'areaId');
        foreach ($this->corridorAreas as $name=>$id) {
            if (in_array($id,$visited)) return $name;
        }
        $this->fail('Route did not visit a fixture corridor.');
    }

    public function test_original_near_duplicate_regression_is_replaced_by_distinct_corridors(): void
    {
        $this->buildCorridors();
        $this->applySql('down');
        $original = $this->route();
        $this->assertCount(2,$original['alternatives']);
        $this->assertSame('middle',$this->usedCorridor($original));
        foreach ($original['alternatives'] as $alt) {
            $this->assertSame('middle',$this->usedCorridor($alt));
        }

        $this->applySql('up');
        $fixed = $this->route();
        $this->assertSame('OK',$fixed['status']);
        $this->assertSame($original['geo'],$fixed['geo']);
        $this->assertSame($original['steps'],$fixed['steps']);
        $this->assertSame($original['distanceMeters'],$fixed['distanceMeters']);
        $this->assertSame($original['estimatedMinutes'],$fixed['estimatedMinutes']);
        $this->assertCount(2,$fixed['alternatives']);
        $corridors = array_map(fn($r)=>$this->usedCorridor($r),[$fixed,...$fixed['alternatives']]);
        sort($corridors);
        $this->assertSame(['middle','north','south'],$corridors);

        // Drawing geometry cannot supply routing geometry, even after a display edit.
        DB::statement('UPDATE doors SET geom=ST_Translate(geom,2000,2000)');
        $afterDisplayEdit = $this->route();
        $this->assertSame($fixed['geo'],$afterDisplayEdit['geo']);
        $this->assertSame(array_column($fixed['alternatives'],'geo'),array_column($afterDisplayEdit['alternatives'],'geo'));
    }

    public function test_main_and_alternative_routes_end_with_an_arrival_at_route_m_one(): void
    {
        $this->buildCorridors();
        $body = ['mode'=>'walk','gender'=>'both','origin'=>$this->origin+['type'=>'coordinate','floor'=>0],
            'destination'=>$this->destination+['type'=>'coordinate','floor'=>0],'maxAlternatives'=>2];
        $route = $this->postJson('/api/v1/routing/route',$body)->assertOk()->json();
        $this->assertCount(2,$route['alternatives']);
        foreach ([$route,...$route['alternatives']] as $candidate) {
            $arrival = $candidate['steps'][count($candidate['steps'])-1];
            $this->assertSame('stepArriveDestination',$arrival['type']);
            $this->assertSame(1,$arrival['routeM']);
            $this->assertSame(0,$arrival['floor']);
            $this->assertSame(count($candidate['segments'])-1,$arrival['segmentId']);
            $this->assertSame(count($candidate['steps']),$arrival['stepOrder']);
            $this->assertCount(1,array_filter($candidate['steps'],fn($s)=>$s['type']==='stepArriveDestination'));
            $end = $candidate['geo']['geometry']['coordinates'][count($candidate['geo']['geometry']['coordinates'])-1];
            $this->assertEqualsWithDelta($end[0],$arrival['coord']['lon'],0.00000001);
            $this->assertEqualsWithDelta($end[1],$arrival['coord']['lat'],0.00000001);
        }
    }

    public function test_nearby_door_choices_do_not_fill_missing_alternative_slots(): void
    {
        $this->buildCorridors(false);
        $route = $this->route(3);
        $this->assertSame('OK',$route['status']);
        $this->assertSame('middle',$this->usedCorridor($route));
        $this->assertSame([],$route['alternatives']);
    }

    public function test_http_alternative_limit_and_zero_keep_the_same_main_geometry(): void
    {
        $this->buildCorridors();
        $body = ['mode'=>'walk','gender'=>'both','origin'=>$this->origin+['type'=>'coordinate','floor'=>0],
            'destination'=>$this->destination+['type'=>'coordinate','floor'=>0],'maxAlternatives'=>0];
        $main = $this->postJson('/api/v1/routing/route',$body)->assertOk()
            ->assertJsonPath('multifloor',false)->assertJsonPath('alternatives',[])->json();
        $body['maxAlternatives'] = 1;
        $one = $this->postJson('/api/v1/routing/route',$body)->assertOk()->json();
        $this->assertCount(1,$one['alternatives']);
        $this->assertSame($main['geo'],$one['geo']);
        $this->assertSame($main['segments'],$one['segments']);
    }

    public function test_closed_corridors_are_not_reintroduced_as_alternatives(): void
    {
        $this->buildCorridors();
        DB::table('doors')->whereIn('id',[...$this->corridorDoors['north'],...$this->corridorDoors['south']])
            ->update(['is_open'=>false]);
        $route = $this->route();
        $this->assertSame('OK',$route['status']);
        $this->assertSame('middle',$this->usedCorridor($route));
        $this->assertSame([],$route['alternatives']);
    }

    public function test_distinct_accessible_connectors_are_useful_with_identical_xy(): void
    {
        $areas = [$this->area([0,0,100,120],0),$this->area([0,0,100,120],1)];
        $this->withoutMiddleware(AdminAuth::class);
        $connectorIds = [];
        foreach (['elevator','elevator','stair'] as $i=>$kind) {
            $payload = ['kind'=>$kind,'direction'=>'both','wait_seconds'=>20+$i*5,
                'info'=>['basic_info'=>['title'=>['fa'=>'گزینه '.($i+1)],'description'=>''],
                    'operational'=>['status'=>'active','place_function'=>$kind,
                        'transport_modes'=>$kind==='stair'?['walk']:['walk','wheelchair'],'gender_access'=>['both']],
                    'time_restrictions'=>[],'prayer_restrictions'=>[]],
                'stops'=>array_map(fn($floor)=>$this->point(50,60)+[
                    'floor'=>$floor,'area_id'=>$areas[$floor],'travel_seconds'=>8,
                ],[0,1])];
            $connectorIds[] = $this->postJson('/api/v1/admin/connectors',$payload)->assertCreated()->json('id');
        }
        $this->rebuild([0,1]);
        $route = $this->route(2,'wheelchair',0,1);
        $this->assertSame('OK',$route['status']);
        $this->assertTrue($route['multifloor']);
        $this->assertCount(1,$route['alternatives']);
        $chosen = [];
        foreach ([$route,...$route['alternatives']] as $r) {
            $transfers = array_values(array_filter($r['steps'],fn($s)=>$s['type']==='stepChangeFloor'));
            $this->assertCount(1,$transfers);
            $this->assertSame('elevator',$transfers[0]['connectorType']);
            $chosen[] = $transfers[0]['connectorId'];
            $walk = array_values(array_filter($r['segments'],fn($s)=>$s['kind']==='walk'));
            $this->assertSame([0,1],array_column($walk,'floor'));
            $this->assertSame('MultiLineString',$r['geo']['geometry']['type']);
            $this->assertNull($r['segments'][1]['geometry']);
        }
        $this->assertEqualsCanonicalizing(array_slice($connectorIds,0,2),$chosen);
    }

    public function test_migration_packaging_and_transactional_rollback_restore_the_function(): void
    {
        $key = '20261005_170000_diverse_route_alternatives';
        foreach (['up','down'] as $direction) {
            $this->assertSame(file_get_contents(base_path('../db/changes/'.$key.'.'.$direction.'.sql')),
                file_get_contents(database_path('sql/'.$key.'.'.$direction.'.sql')));
        }
        $fixed = $this->functionDefinition();
        $migration = require database_path('migrations/2026_10_05_170000_restore_diverse_route_alternatives.php');
        $migration->down();
        $this->assertNotSame($fixed,$this->functionDefinition());
        $migration->up();
        $this->assertSame($fixed,$this->functionDefinition());
    }
}
