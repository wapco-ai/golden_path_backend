<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Services\ConnectorService;
use App\Services\MultiFloorRoutingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MultiFloorConnectorTest extends TestCase
{
    private array $areas = [];
    private array $point;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') $this->markTestSkipped('Requires isolated PostgreSQL/PostGIS database.');
        Queue::fake();
        DB::beginTransaction();
        DB::table('routing_floors')->insertOrIgnore(['floor'=>1,'label'=>'طبقه ۱','sort_order'=>1]);
        foreach ([-1,0,1] as $floor) {
            $row = DB::selectOne("INSERT INTO areas(geom,area_type,floor) VALUES (
              ST_Multi(ST_GeomFromText('POLYGON((500000 4000000,500020 4000000,500020 4000020,500000 4000020,500000 4000000))',32640)),
              'courtyard',?) RETURNING id", [$floor]);
            $this->areas[$floor] = $row->id;
        }
        $point = DB::selectOne('SELECT ST_X(g) AS lon,ST_Y(g) AS lat FROM (SELECT ST_Transform(ST_SetSRID(ST_MakePoint(500005,4000005),32640),4326) g) p');
        $this->point = ['lat'=>(float)$point->lat,'lon'=>(float)$point->lon];
    }

    protected function tearDown(): void
    {
        if (isset($this->point)) DB::rollBack();
        parent::tearDown();
    }

    private function payload(string $kind = 'elevator'): array
    {
        return ['kind'=>$kind,'direction'=>'both','wait_seconds'=>20,
            'info'=>['basic_info'=>['title'=>['fa'=>'آسانسور آزمایشی','en'=>'Test lift'],'description'=>''],
                'operational'=>['status'=>'active','place_function'=>$kind,
                    'transport_modes'=>$kind==='stair'?['walk']:['walk','wheelchair'],'gender_access'=>['both']],
                'time_restrictions'=>[],'prayer_restrictions'=>[]],
            'stops'=>array_map(fn ($floor)=>['floor'=>$floor,'travel_seconds'=>8]+$this->point,[-1,0,1])];
    }

    private function create(array $payload = []): array
    {
        $this->withoutMiddleware(AdminAuth::class);
        return $this->postJson('/api/v1/admin/connectors',$payload ?: $this->payload())->assertCreated()->json();
    }

    private function rebuild(): void
    {
        foreach ([-1,0,1] as $floor) DB::select('SELECT fn_build_routing_nodes(?::smallint)',[$floor]);
        DB::statement("UPDATE doors SET attrs=jsonb_set(attrs,'{graph}', '{\"status\":\"ready\"}')");
        DB::statement('REFRESH MATERIALIZED VIEW mv_area_door_stats');
    }

    private function route(int $from = -1, int $to = 1, string $mode = 'walk'): array
    {
        return app(MultiFloorRoutingService::class)->route('both',$mode,$from,$to,$this->point,$this->point,'fa',0);
    }

    public function test_admin_auth_is_required(): void
    {
        $this->postJson('/api/v1/admin/connectors',$this->payload())->assertUnauthorized();
    }

    public function test_three_stop_lift_resolves_areas_and_is_shared_from_every_stop(): void
    {
        $c=$this->create();
        $this->assertCount(3,$c['stops']);
        foreach ($c['stops'] as $stop) {
            $this->assertSame($this->areas[$stop['floor']],$stop['area_id']);
            $this->getJson('/api/v1/doors/'.$stop['door_id'].'/info')->assertOk()->assertJsonPath('connector.id',$c['id']);
        }
        $this->assertSame('NO_PATH',$this->route()['status']); // queued nodes are never active
        $this->rebuild();
        $r=$this->route();
        $this->assertSame('OK',$r['status']);
        $transfers=array_values(array_filter($r['steps'],fn($s)=>$s['type']==='stepChangeFloor'));
        $this->assertCount(1,$transfers);
        $this->assertSame(-1,$transfers[0]['fromFloor']);
        $this->assertSame(1,$transfers[0]['toFloor']);
        $this->assertSame(36.0,$transfers[0]['duration_s']);
        $this->assertNull($r['segments'][1]['geometry']);
    }

    public function test_rebuilding_node_ids_preserves_linkage_and_identical_xy_stays_on_distinct_floors(): void
    {
        $this->create(); $this->rebuild();
        $old=DB::table('routing_nodes')->pluck('id')->all();
        $this->rebuild();
        $this->assertEmpty(array_intersect($old,DB::table('routing_nodes')->pluck('id')->all()));
        $this->assertSame('OK',$this->route()['status']);
        $this->assertSame('OK',$this->route(1,-1,'wheelchair')['status']);
    }

    public function test_stairs_use_adjacent_landings_and_directional_times(): void
    {
        $p=$this->payload('stair'); $p['direction']='forward';
        $this->create($p); $this->rebuild();
        $r=$this->route();
        $this->assertCount(2,array_filter($r['steps'],fn($s)=>$s['type']==='stepChangeFloor'));
        $this->assertSame('NO_PATH',$this->route(1,-1)['status']);
        $this->assertSame('NO_PATH',$this->route(-1,1,'wheelchair')['status']);
    }

    public function test_wait_is_not_repeated_at_a_closed_intermediate_lift_stop(): void
    {
        $c=$this->create(); $this->rebuild();
        DB::table('doors')->where('id',$c['stops'][1]['door_id'])->update(['is_open'=>false]);
        $this->assertSame('OK',$this->route()['status']);
        $this->assertSame('NO_PATH',$this->route(-1,0)['status']);
    }

    public function test_stale_edit_does_not_overwrite_and_invalid_last_stop_rolls_back_all_writes(): void
    {
        $c=$this->create(); $p=$this->payload();
        $p['version']=0;
        $this->putJson('/api/v1/admin/connectors/'.$c['id'],$p)->assertUnprocessable();
        $p['version']=$c['version']+1;
        $this->putJson('/api/v1/admin/connectors/'.$c['id'],$p)->assertStatus(409);
        $before=DB::table('doors')->count();
        $p=$this->payload(); $p['stops'][2]['lon']=0;
        $this->postJson('/api/v1/admin/connectors',$p)->assertUnprocessable();
        $this->assertSame($before,DB::table('doors')->count());
        $this->assertSame(1,DB::table('routing_connectors')->count());
    }

    public function test_floor_mismatch_and_wheelchair_on_stairs_are_rejected(): void
    {
        $c=$this->create(); $p=$this->payload('stair');
        $p['info']['operational']['transport_modes']=['walk','wheelchair'];
        $this->postJson('/api/v1/admin/connectors',$p)->assertUnprocessable();
        $p=$this->payload(); $p['stops'][0]['access_id']=$c['stops'][1]['access_id'];
        $this->postJson('/api/v1/admin/connectors',$p)->assertUnprocessable();
    }

    public function test_ambiguous_area_requires_explicit_valid_candidate(): void
    {
        DB::statement('INSERT INTO areas(geom,area_type,floor) SELECT geom,area_type,floor FROM areas WHERE id=?',[$this->areas[0]]);
        $this->withoutMiddleware(AdminAuth::class);
        $this->postJson('/api/v1/admin/connectors',$this->payload())->assertUnprocessable()->assertJsonValidationErrors('stops.1.area_id');
        $p=$this->payload(); $p['stops'][1]['area_id']=$this->areas[0];
        $this->create($p);
    }

    public function test_removing_a_stop_disables_the_connector(): void
    {
        $c=$this->create(); $this->rebuild();
        DB::table('routing_connector_stops')->where('id',$c['stops'][1]['id'])->delete();
        $this->assertSame('NO_PATH',$this->route()['status']);
    }
}
