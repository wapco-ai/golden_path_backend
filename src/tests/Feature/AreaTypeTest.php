<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\AdminSectionAccess;
use App\Services\ConnectorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AreaTypeTest extends TestCase
{
    use DatabaseTransactions;

    private int $area;
    private int $nearby;
    private array $access = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([AdminAuth::class, AdminSectionAccess::class]);
        DB::table('routing_floors')->insertOrIgnore(['floor' => -1, 'label' => 'منفی یک', 'sort_order' => -1]);
        $this->area = $this->polygon('stair_area', 701000, 'transparent');
        $this->nearby = $this->polygon('elevator_area', 701020, 'transparent');
        foreach ([4000003, 4000017] as $y) {
            $door = DB::selectOne("INSERT INTO doors (geom,floor,from_area,to_area)
                VALUES (ST_SetSRID(ST_MakeLine(ST_MakePoint(701020,?),ST_MakePoint(701020,?)),32640),
                -1,?,?) RETURNING id", [$y-1, $y+1, $this->area, $this->nearby])->id;
            $dap = DB::selectOne("INSERT INTO door_access_points (door_id,floor,geom,from_area,to_area,needs_review)
                VALUES (?,-1,ST_SetSRID(ST_MakePoint(701020,?),32640),?,?,false) RETURNING id",
                [$door, $y, $this->area, $this->nearby])->id;
            $this->access[] = $dap;
            DB::insert("INSERT INTO routing_nodes (geom,floor,kind,ref_table,ref_id,area_id)
                SELECT geom,floor,'door_access','door_access_points',id,from_area
                FROM door_access_points WHERE id=?", [$dap]);
        }
    }

    private function polygon(string $type, int $x, string $role): int
    {
        return DB::selectOne("INSERT INTO areas (geom,area_type,floor,attrs)
            VALUES (ST_Multi(ST_MakeEnvelope(?,4000000,?,4000020,32640)),?,-1,?::jsonb) RETURNING id",
            [$x, $x+20, $type, json_encode(['routing_role'=>$role, 'access_policy'=>['public_access'=>false]])])->id;
    }

    private function candidates(): array
    {
        return array_column(app(ConnectorService::class)->candidates(['floor'=>-1,'access_id'=>$this->access[0]]), 'id');
    }

    public function test_vertical_area_becomes_a_distinct_corridor_and_loses_internal_edges_when_changed_back(): void
    {
        $url = "/api/v1/areas/{$this->area}";
        $this->assertSame([], $this->candidates());
        $this->putJson($url, ['meta'=>['area_type'=>'corridor','expected_area_type'=>'stair_area']])
            ->assertOk()->assertJsonPath('area_graph.status', 'ready');
        $this->assertContains($this->area, $this->candidates());
        $edges = DB::table('routing_edges_static AS e')->join('routing_nodes AS s','e.src','=','s.id')
            ->join('routing_nodes AS t','e.dst','=','t.id')->whereNull('e.door_id')
            ->where('s.area_id',$this->area)->where('t.area_id',$this->area);
        $this->assertTrue($edges->exists());
        $this->getJson($url)->assertOk()->assertJsonPath('meta.area_type','corridor')
            ->assertJsonPath('attrs_raw.access_policy.public_access', false);
        $this->putJson($url, ['meta'=>['area_type'=>'ramp_area','expected_area_type'=>'stair_area']])->assertConflict();
        foreach (['elevator_area','ramp_area','stair_area'] as $type) {
            $this->putJson($url, ['meta'=>['area_type'=>$type]])->assertOk()->assertJsonPath('area_graph.status','connector_only');
            $this->assertFalse($edges->exists());
            $this->assertSame([], $this->candidates());
        }
        $this->putJson($url, ['meta'=>['area_type'=>'invalid']])->assertUnprocessable();
    }

    public function test_active_lift_stop_blocks_loss_of_walkable_area_and_returns_name_and_floor(): void
    {
        DB::table('areas')->where('id',$this->area)->update(['area_type'=>'functional_area','attrs'=>'{"routing_role":"routable"}']);
        $group = DB::table('routing_connectors')->insertGetId(['kind'=>'elevator','is_active'=>true,
            'info'=>json_encode(['basic_info'=>['title'=>['fa'=>'آسانسور غربی']]])]);
        DB::table('routing_connector_stops')->insert(['connector_id'=>$group,'position'=>0,'floor'=>-1,
            'area_id'=>$this->area,'access_id'=>$this->access[0],'travel_seconds'=>8,
            'door_id'=>DB::table('door_access_points')->where('id',$this->access[0])->value('door_id')]);
        $url = "/api/v1/areas/{$this->area}";
        $reply=$this->putJson($url,['meta'=>['area_type'=>'stair_area']])->assertUnprocessable();
        $message=$reply->json('errors')['meta.area_type'][0];
        $this->assertStringContainsString('آسانسور غربی', $message);
        $this->assertStringContainsString('طبقه -1', $message);
        $this->assertContains($this->area, $this->candidates());
        $this->getJson($url)->assertJsonPath('meta.area_type','functional_area');
        DB::table('routing_connectors')->where('id',$group)->update(['is_active'=>false]);
        $this->putJson($url,['meta'=>['area_type'=>'stair_area']])->assertOk();
    }
}
