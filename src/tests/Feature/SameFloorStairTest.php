<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\AdminSectionAccess;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SameFloorStairTest extends TestCase
{
    private const API = '/api/v1/admin/same-floor-stairs';

    private bool $transaction = false;
    private array $stops = [];
    private int $stairArea;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql' || !str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
            $this->markTestSkipped('Requires isolated PostgreSQL/PostGIS database.');
        }

        config(['app.jwt_secret' => str_repeat('test-only-', 8)]);
        DB::beginTransaction();
        $this->transaction = true;
        DB::table('routing_floors')->insertOrIgnore([
            ['floor' => -1, 'label' => 'منفی ۱', 'sort_order' => -1],
            ['floor' => 0, 'label' => 'همکف', 'sort_order' => 0],
        ]);

        $this->stairArea = $this->area('stair_area', 500020, 4000007, 500025, 4000013, '{"routing_role":"transparent"}');
        DB::table('areas')->where('id', $this->stairArea)->update(['allowed_gender' => 'female']);
        $this->stops = [
            $this->landing(500020, $this->area('courtyard', 500000, 4000000, 500020, 4000020)),
            $this->landing(500025, $this->area('courtyard', 500025, 4000000, 500045, 4000020)),
        ];

        DB::select('SELECT fn_build_routing_nodes((-1)::smallint)');
        DB::statement('REFRESH MATERIALIZED VIEW mv_area_door_stats');
    }

    protected function tearDown(): void
    {
        if ($this->transaction) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function area(string $kind, int $x1, int $y1, int $x2, int $y2, string $attrs = '{}'): int
    {
        return (int) DB::selectOne("INSERT INTO areas (geom, area_type, floor, attrs)
            VALUES (ST_Multi(ST_MakeEnvelope(?, ?, ?, ?, 32640)), ?::area_type_enum, -1, ?::jsonb) RETURNING id",
            [$x1, $y1, $x2, $y2, $kind, $attrs])->id;
    }

    private function landing(int $x, int $area): array
    {
        $point = DB::selectOne("WITH door AS (
              INSERT INTO doors (geom, from_area, floor, is_open, modes, attrs)
              VALUES (ST_GeomFromText(?, 32640), ?, -1, true, ARRAY['walk','wheelchair'], '{\"graph\":{\"status\":\"ready\"}}'::jsonb)
              RETURNING id, floor, from_area
            ) INSERT INTO door_access_points (door_id, geom, floor, from_area, needs_review)
              SELECT id, ST_SetSRID(ST_MakePoint(?, 4000010), 32640), floor, from_area, false FROM door
              RETURNING id, door_id", ["LINESTRING($x 4000007,$x 4000008)", $area, $x]);

        return ['floor' => -1, 'access_id' => $point->id, 'area_id' => $area, 'door_id' => $point->door_id,
            'travel_seconds' => 12, 'reverse_seconds' => 10];
    }

    private function payload(): array
    {
        return [
            'kind' => 'same_floor_stair', 'stair_area_id' => $this->stairArea, 'direction' => 'both',
            'info' => ['basic_info' => ['title' => ['fa' => 'پلهٔ آزمایشی']],
                'operational' => ['status' => 'active', 'gender_access' => ['female'], 'transport_modes' => ['walk']]],
            'stops' => $this->stops,
        ];
    }

    private function point(int $x): array
    {
        $p = DB::selectOne('SELECT ST_X(g) AS lon, ST_Y(g) AS lat FROM
            (SELECT ST_Transform(ST_SetSRID(ST_MakePoint(?, 4000010), 32640), 4326) AS g) p', [$x]);

        return ['type' => 'coordinate', 'floor' => -1, 'lon' => $p->lon, 'lat' => $p->lat];
    }

    private function route(string $mode = 'walk', bool $reverse = false, string $gender = 'female')
    {
        return $this->postJson('/api/v1/routing/route', [
            'mode' => $mode, 'gender' => $gender, 'floor' => -1, 'lang' => 'fa', 'maxAlternatives' => 0,
            'origin' => $this->point($reverse ? 500030 : 500015),
            'destination' => $this->point($reverse ? 500015 : 500030),
        ]);
    }

    public function test_registered_stair_routes_in_both_directions_and_respects_access(): void
    {
        $this->withoutMiddleware([AdminAuth::class, AdminSectionAccess::class]);
        $payload = $this->payload();
        $saved = $this->postJson(self::API, $payload)->assertCreated()
            ->assertJsonPath('data.stops.0.floor', -1)->assertJsonPath('data.stops.1.floor', -1)->json('data');
        $this->getJson(self::API.'?floor=-1')->assertOk()->assertJsonPath('data.0.id', $saved['id']);
        $this->assertSame(1, (int) DB::scalar("SELECT count(*)
            FROM fn_routing_edges_live_param(now(), 'female', 'walk', (-1)::smallint) live
            JOIN routing_edges_static e ON e.id=live.id
            WHERE e.attrs->>'edge_type'='same_floor_stair' AND (e.attrs->>'stair_id')::bigint=?", [$saved['id']]));

        $up = $this->route()->assertOk()->assertJsonPath('multifloor', false)->assertJsonPath('segments.0.floor', -1);
        $down = $this->route(reverse: true)->assertOk()->assertJsonPath('multifloor', false);
        $this->assertNotContains('stepChangeFloor', array_column($up->json('steps'), 'type'));
        $up->assertJsonFragment(['type' => 'stepUseStairs', 'stairDirection' => 'up']);
        $down->assertJsonFragment(['type' => 'stepUseStairs', 'stairDirection' => 'down']);
        $this->assertSame(12.0, (float) collect($up->json('steps'))->firstWhere('type', 'stepUseStairs')['duration_s']);
        $this->assertSame(10.0, (float) collect($down->json('steps'))->firstWhere('type', 'stepUseStairs')['duration_s']);
        $this->route('wheelchair')->assertNotFound()->assertJsonPath('status', 'NO_PATH');
        $this->route('van')->assertNotFound()->assertJsonPath('status', 'NO_PATH');
        $this->route(gender: 'male')->assertNotFound()->assertJsonPath('status', 'NO_PATH');

        $payload['version'] = $saved['version'];
        $payload['info']['operational']['status'] = 'inactive';
        $this->putJson(self::API.'/'.$saved['id'], $payload)->assertOk()->assertJsonPath('data.is_active', false);
        $this->putJson(self::API.'/'.$saved['id'], $payload)->assertConflict();
        $this->route()->assertNotFound()->assertJsonPath('status', 'NO_PATH');
    }

    public function test_rebuild_keeps_one_node_derived_edge_and_removing_a_landing_disables_it(): void
    {
        $this->withoutMiddleware([AdminAuth::class, AdminSectionAccess::class]);
        $saved = $this->postJson(self::API, $this->payload())->assertCreated()->json('data');
        DB::select('SELECT fn_build_routing_nodes((-1)::smallint)');
        $edges = DB::select("SELECT ST_Equals(e.geom, ST_MakeLine(src.geom, dst.geom)) AS matches
            FROM routing_edges_static e JOIN routing_nodes src ON src.id=e.src JOIN routing_nodes dst ON dst.id=e.dst
            WHERE e.attrs->>'edge_type'='same_floor_stair' AND (e.attrs->>'stair_id')::bigint=?", [$saved['id']]);
        $this->assertCount(1, $edges);
        $this->assertTrue($edges[0]->matches);

        DB::table('door_access_points')->where('id', $this->stops[0]['access_id'])->delete();
        $this->assertFalse(DB::table('same_floor_stairs')->where('id', $saved['id'])->value('is_active'));
        $this->route()->assertNotFound()->assertJsonPath('status', 'NO_PATH');
    }

    public function test_unset_stair_time_stays_unset_when_the_saved_stops_are_resubmitted(): void
    {
        $this->withoutMiddleware([AdminAuth::class, AdminSectionAccess::class]);
        $payload = $this->payload();
        unset($payload['stops'][0]['travel_seconds'], $payload['stops'][0]['reverse_seconds']);
        $saved = $this->postJson(self::API, $payload)->assertCreated()
            ->assertJsonPath('data.stops.0.travel_seconds', null)->json('data');

        $record = $this->getJson(self::API.'?floor=-1')->assertOk()
            ->assertJsonPath('data.0.stops.0.travel_seconds', null)->json('data.0');
        $payload['version'] = $record['version'];
        $payload['stops'] = $record['stops'];
        $this->putJson(self::API.'/'.$saved['id'], $payload)->assertOk()
            ->assertJsonPath('data.stops.0.travel_seconds', null);
        $this->assertNull(DB::table('same_floor_stairs')->where('id', $saved['id'])->value('travel_seconds'));

        $route = $this->route()->assertOk();
        $stairStep = collect($route->json('steps'))->firstWhere('type', 'stepUseStairs');
        $this->assertEqualsWithDelta(5 / 1.2, (float) $stairStep['duration_s'], 0.001);
    }

    public function test_deactivation_only_requires_the_current_version_even_with_an_invalid_landing(): void
    {
        $this->patchJson(self::API.'/1/deactivate', ['version' => 1])->assertUnauthorized();
        $this->withoutMiddleware([AdminAuth::class, AdminSectionAccess::class]);
        $saved = $this->postJson(self::API, $this->payload())->assertCreated()->json('data');
        $url = self::API.'/'.$saved['id'].'/deactivate';
        $accessId = $this->stops[0]['access_id'];
        DB::table('door_access_points')->where('id', $accessId)->update(['needs_review' => true]);

        $this->patchJson($url, ['version' => $saved['version'] + 1])->assertConflict();
        $this->patchJson($url, ['version' => $saved['version']])->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.info.operational.status', 'inactive')
            ->assertJsonPath('data.version', $saved['version'] + 1)
            ->assertJsonPath('data.stops.0.access_id', $accessId);
        $this->assertSame('inactive', DB::table('same_floor_stairs')->where('id', $saved['id'])
            ->value(DB::raw("info #>> '{operational,status}'")));

        DB::table('door_access_points')->where('id', $accessId)->delete();
        $currentVersion = DB::table('same_floor_stairs')->where('id', $saved['id'])->value('version');
        $this->patchJson($url, ['version' => $currentVersion])->assertOk()
            ->assertJsonPath('data.is_active', false)->assertJsonPath('data.stops.0.access_id', null);
    }

    public function test_candidate_areas_match_both_landings_and_the_validated_stair_geometry(): void
    {
        $query = [
            'floor' => -1,
            'start_access_id' => $this->stops[0]['access_id'],
            'end_access_id' => $this->stops[1]['access_id'],
            'start_area_id' => $this->stops[0]['area_id'],
            'end_area_id' => $this->stops[1]['area_id'],
        ];
        $url = self::API.'/candidates?';
        $this->getJson($url.http_build_query($query))->assertUnauthorized();
        $this->withoutMiddleware([AdminAuth::class, AdminSectionAccess::class]);

        $this->area('stair_area', 500030, 4000040, 500035, 4000045, '{"routing_role":"transparent"}');
        DB::insert("INSERT INTO areas (geom, area_type, floor, attrs)
            VALUES (
                ST_Multi(ST_Union(
                    ST_MakeEnvelope(500020, 4000009, 500021, 4000011, 32640),
                    ST_MakeEnvelope(500024, 4000009, 500025, 4000011, 32640)
                )), 'stair_area', -1, '{\"routing_role\":\"transparent\"}'::jsonb
            )");

        $this->getJson($url.http_build_query($query))->assertOk()
            ->assertExactJson(['areas' => [['id' => $this->stairArea, 'floor' => -1, 'name' => 'محدوده '.$this->stairArea]]]);
        $this->getJson($url.http_build_query(array_replace($query, ['floor' => 0])))
            ->assertOk()->assertExactJson(['areas' => []]);
        $this->getJson($url.http_build_query(array_replace($query, ['end_area_id' => $query['start_area_id']])))
            ->assertOk()->assertExactJson(['areas' => []]);
        $this->getJson($url.http_build_query(array_replace($query, ['end_access_id' => $query['start_access_id']])))
            ->assertUnprocessable()->assertJsonValidationErrors('end_access_id');
        $this->getJson(self::API.'?floor=-1')->assertOk()->assertExactJson(['data' => []]);

        DB::update('UPDATE door_access_points SET geom=ST_Translate(geom, 0.0005, 0) WHERE id=?', [$query['start_access_id']]);
        DB::update('UPDATE door_access_points SET geom=ST_Translate(geom, -0.0005, 0) WHERE id=?', [$query['end_access_id']]);
        DB::select('SELECT fn_build_routing_nodes((-1)::smallint)');
        $this->getJson($url.http_build_query($query))->assertOk()->assertJsonPath('areas.0.id', $this->stairArea);

        DB::update('UPDATE door_access_points SET geom=ST_Translate(geom, 0.002, 0) WHERE id=?', [$query['start_access_id']]);
        DB::select('SELECT fn_build_routing_nodes((-1)::smallint)');
        $this->getJson($url.http_build_query($query))->assertOk()->assertExactJson(['areas' => []]);
    }

    public function test_moving_a_landing_preserves_the_stair_and_its_editable_record(): void
    {
        $this->withoutMiddleware([AdminAuth::class, AdminSectionAccess::class]);
        \Illuminate\Support\Facades\Queue::fake();
        $payload = $this->payload();
        $stair = $this->postJson(self::API, $payload)->assertCreated()->json('data');
        $landing = $payload['stops'][0];
        $doorUrl = '/api/v1/doors/'.$landing['door_id'];

        $this->getJson($doorUrl.'/info?access_id='.$landing['access_id'])->assertOk()
            ->assertJsonPath('connector.id', $stair['id'])
            ->assertJsonPath('connector.kind', 'same_floor_stair');
        $this->putJson($doorUrl.'/move', ['x' => 500020, 'y' => 4000002, 'floor' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors('point');
        $this->getJson($doorUrl.'/info')->assertOk()->assertJsonPath('connector.version', $stair['version']);

        $this->putJson($doorUrl.'/move', ['x' => 500020, 'y' => 4000011, 'floor' => -1])
            ->assertAccepted()->assertJsonPath('door_access_point.id', $landing['access_id']);
        $this->getJson($doorUrl.'/info')->assertOk()
            ->assertJsonPath('connector.is_active', true)
            ->assertJsonPath('connector.version', $stair['version'] + 1)
            ->assertJsonPath('connector.stops.0.error', null);

        DB::select('SELECT fn_build_routing_nodes((-1)::smallint)');
        $this->assertTrue((bool) DB::scalar(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM routing_edges_static e
                JOIN routing_nodes n ON n.id = e.src
                JOIN door_access_points p ON p.id = n.ref_id AND n.ref_table = 'door_access_points'
                WHERE e.attrs->>'stair_id' = ?::text AND p.id = ?
                  AND ST_Equals(ST_StartPoint(e.geom), p.geom) AND ST_Y(p.geom) = 4000011
            )
            SQL, [$stair['id'], $landing['access_id']]));
        $payload['version'] = $stair['version'];
        $this->putJson(self::API.'/'.$stair['id'], $payload)->assertConflict();
        $payload['version']++;
        $payload['direction'] = 'reverse';
        $this->putJson(self::API.'/'.$stair['id'], $payload)->assertOk()
            ->assertJsonPath('data.direction', 'reverse');
    }

    public function test_admin_permissions_and_validation_reject_invalid_landings(): void
    {
        $this->postJson(self::API, $this->payload())->assertUnauthorized();
        $this->withoutMiddleware([AdminAuth::class, AdminSectionAccess::class]);
        $payload = $this->payload();
        $payload['stops'][1]['access_id'] = $payload['stops'][0]['access_id'];
        $this->postJson(self::API, $payload)->assertUnprocessable()->assertJsonValidationErrors('stops.1.access_id');

        $payload = $this->payload();
        $payload['stops'][1]['floor'] = 0;
        $this->postJson(self::API, $payload)->assertUnprocessable()->assertJsonValidationErrors('stops.1.floor');

        $payload = $this->payload();
        $payload['stops'][1]['area_id'] = $payload['stops'][0]['area_id'];
        $this->postJson(self::API, $payload)->assertUnprocessable()->assertJsonValidationErrors('stops.1.area_id');

        DB::update('UPDATE door_access_points SET geom=ST_Translate(geom,-4,0) WHERE id=?', [$this->stops[0]['access_id']]);
        DB::select('SELECT fn_build_routing_nodes((-1)::smallint)');
        $this->postJson(self::API, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('stops');
        $this->assertSame(0, DB::table('same_floor_stairs')->count());
    }
}
