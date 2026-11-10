<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExplicitDoorRouteTest extends TestCase
{
    public function test_open_and_closed_doors_at_both_ends_of_the_route(): void
    {
        $this->assertSame('golden_path_connector_test', DB::connection()->getDatabaseName());
        DB::beginTransaction();
        try {
            DB::unprepared(<<<'SQL'
                INSERT INTO routing_floors(floor,label,sort_order) VALUES (0,'Ground',0) ON CONFLICT DO NOTHING;
                INSERT INTO areas(id,geom,area_type,floor,allowed_gender,is_closed,attrs) VALUES
                  (991600001,ST_Multi(ST_MakeEnvelope(700000,4000000,700020,4000020,32640)),'courtyard',0,'both',false,'{"routing_role":"routable"}'),
                  (991600002,ST_Multi(ST_MakeEnvelope(700020,4000000,700035,4000020,32640)),'courtyard',0,'both',false,'{"routing_role":"routable"}');
                INSERT INTO doors(id,geom,floor,from_area,to_area,is_open,allowed_gender,modes,bidirectional,attrs)
                SELECT id, ST_MakeLine(ST_SetSRID(ST_MakePoint(x,4000004),32640),ST_SetSRID(ST_MakePoint(x,4000006),32640)),
                  0,991600001,other,true,'both',ARRAY['walk'],true,'{"graph":{"status":"ready"}}'
                FROM (VALUES(991600011,700020,991600002),(991600012,700010,NULL),(991600013,700005,NULL)) d(id,x,other);
                INSERT INTO door_access_points(id,door_id,geom,floor,from_area,to_area,needs_review)
                SELECT id,id,ST_SetSRID(ST_MakePoint(x,4000005),32640),0,991600001,other,false
                FROM (VALUES(991600011,700020,991600002),(991600012,700010,NULL),(991600013,700005,NULL)) d(id,x,other);
                INSERT INTO routing_nodes(id,floor,geom,kind,ref_table,ref_id,area_id)
                SELECT id,floor,fn_dap_node_geom_inside_area(geom,from_area,0.20),
                  'door_access','door_access_points',id,from_area FROM door_access_points
                WHERE id BETWEEN 991600011 AND 991600013;
                INSERT INTO routing_nodes(id,floor,geom,kind,ref_table,ref_id,area_id)
                SELECT 991600021,floor,fn_dap_node_geom_inside_area(geom,to_area,0.20),
                  'door_access','door_access_points',id,to_area FROM door_access_points WHERE id=991600011;
                INSERT INTO routing_edges_static(id,floor,src,dst,geom,base_cost,door_id)
                SELECT e.id,0,s.id,t.id,ST_MakeLine(s.geom,t.geom),GREATEST(ST_Distance(s.geom,t.geom),0.01),e.door_id
                FROM (VALUES (991600031,991600011,991600012,NULL::bigint),
                  (991600032,991600012,991600013,NULL),(991600033,991600011,991600021,991600011)) e(id,src,dst,door_id)
                JOIN routing_nodes s ON s.id=e.src JOIN routing_nodes t ON t.id=e.dst;
                REFRESH MATERIALIZED VIEW mv_area_door_stats;
                SQL);

            $door = ['type' => 'door', 'accessId' => 991600011, 'areaId' => 991600001, 'floor' => 0,
                'lat' => 1, 'lon' => 1];
            $coordinate = ['type' => 'coordinate', 'floor' => 0, ...$this->point(700003)];
            $point = $this->point(700020);
            $picker = '/api/v1/area-doors?area_id=991600001&floor=0&mode=walk&gender=both';
            $findDoor = fn () => collect($this->getJson($picker)->assertOk()->json('data.doors'))
                ->firstWhere('accessId', 991600011);
            $this->assertGreaterThan(0.19, (float) DB::scalar('SELECT ST_Distance(n.geom,p.geom)
                FROM routing_nodes n JOIN door_access_points p ON p.id=n.ref_id WHERE n.id=991600011'));
            $this->assertSame('available', $findDoor()['routingStatus']);
            $this->assertSame(991600001, $findDoor()['selectedAreaId']);

            foreach (['origin', 'destination'] as $side) {
                $data = $this->route($door, $coordinate, $side)->assertOk()->assertJsonPath('status', 'OK')->json();
                $step = $side === 'origin' ? $data['steps'][0] : end($data['steps']);
                $this->assertEqualsWithDelta($point['lat'], $step['coord']['lat'], 0.00000001);
                $this->assertEqualsWithDelta($point['lon'], $step['coord']['lon'], 0.00000001);
                $line = $data['geo']['geometry']['coordinates'];
                $linePoint = $side === 'origin' ? $line[0] : end($line);
                $this->assertEqualsWithDelta($point['lon'], $linePoint[0], 0.00000001);
                $this->assertEqualsWithDelta($point['lat'], $linePoint[1], 0.00000001);
                $this->assertArrayNotHasKey('nearbyOrigin', $data['geo']['properties']);
                $this->assertArrayNotHasKey('nearbyDestination', $data['geo']['properties']);
            }

            DB::table('doors')->where('id', 991600011)->update(['is_open' => false]);
            $this->assertSame('closed', $findDoor()['routingStatus']);
            foreach (['origin', 'destination'] as $side) {
                $result = $this->route($door, $coordinate, $side)->assertStatus(409)
                    ->assertJsonPath('status', 'DOOR_UNAVAILABLE')->assertJsonPath('endpoint', $side)
                    ->assertJsonPath('reason', 'closed')->assertJsonPath('replacement.accessId', 991600012)
                    ->assertJsonMissingPath('geo');
                $this->assertEqualsWithDelta(10, $result->json('replacement.distanceMeters'), 0.01);
                $this->route([...$door, 'accessId' => 991600012], $coordinate, $side)->assertOk();
            }

            $this->route([...$door, 'areaId' => 991600003], $coordinate, 'origin')->assertStatus(409)
                ->assertJsonPath('replacement', null);
        } finally {
            DB::rollBack();
        }
    }

    private function point(int $x): array
    {
        $point = DB::selectOne('SELECT ST_Y(p) AS lat, ST_X(p) AS lon FROM
            (SELECT ST_Transform(ST_SetSRID(ST_MakePoint(?,4000005),32640),4326) AS p) wgs', [$x]);
        return ['lat' => (float) $point->lat, 'lon' => (float) $point->lon];
    }

    private function route(array $door, array $coordinate, string $side)
    {
        return $this->postJson('/api/v1/routing/route', [
            'origin' => $side === 'origin' ? $door : $coordinate,
            'destination' => $side === 'destination' ? $door : $coordinate,
            'mode' => 'walk', 'gender' => 'both', 'maxAlternatives' => 0, 'allowNearbyDestination' => true,
        ]);
    }
}
