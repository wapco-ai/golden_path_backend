<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\AdminSectionAccess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AreaPublicAccessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires the isolated PostgreSQL integration database.');
        }
        $this->withoutMiddleware([AdminAuth::class, AdminSectionAccess::class]);
    }

    public function test_visibility_and_routing_follow_access_changes_without_deleting_the_graph(): void
    {
        $area = $this->area(500000, ['source_properties' => ['owner' => 'عمومي']]);
        $neighbor = $this->area(500020);
        $this->buildEdge($area, $neighbor);
        $this->assertTrue($this->allows($area, 'walk'));
        $this->assertGreaterThan(0, $this->tileSize());

        $this->putJson("/api/v1/areas/{$area}", ['public_access' => false])->assertOk();
        $this->putJson("/api/v1/areas/{$area}", ['public_access' => false])->assertOk();
        $this->getJson("/api/v1/areas/{$area}")->assertOk()
            ->assertJsonPath('attrs_raw.access_policy.public_access', false);
        $this->assertSame(1, DB::table('admin_restrictions')->where('target_table', 'areas')
            ->where('target_id', $area)->count());
        foreach (['walk', 'wheelchair', 'van'] as $mode) {
            $this->assertFalse($this->allows($area, $mode));
        }
        $this->assertSame(0, $this->tileSize());
        $this->assertTrue(DB::table('routing_edges_static')->where('attrs->area_id', $area)->exists());

        $this->putJson("/api/v1/areas/{$area}", ['public_access' => true])->assertOk();
        $this->getJson("/api/v1/areas/{$area}")->assertOk()
            ->assertJsonPath('attrs_raw.access_policy.public_access', true);
        $this->assertTrue($this->allows($area, 'walk'));
        $this->assertGreaterThan(0, $this->tileSize());
    }

    public function test_public_access_releases_only_the_imported_ownership_restriction(): void
    {
        $area = $this->area(500000, [
            'access_policy' => ['public_access' => false, 'source_explicit_denial' => false],
            'source_ref' => ['dataset' => 'fava14050813'],
            'source_properties' => ['owner' => 'خصوصی'],
            'import_review' => ['declared_modes' => ['walk', 'wheelchair']],
        ]);
        $neighbor = $this->area(500020);
        $this->buildEdge($area, $neighbor);
        DB::table('admin_restrictions')->insert([
            'target_table' => 'areas', 'target_id' => $area,
            'reason' => 'Fava 14050813: non-public or explicit visitor prohibition',
        ]);
        DB::table('admin_restrictions')->insert([
            'target_table' => 'areas', 'target_id' => $area,
            'reason' => 'Manual wheelchair restriction', 'modes' => '{wheelchair}',
        ]);

        $this->assertFalse($this->allows($area, 'walk'));
        $this->assertSame(0, $this->tileSize());
        $this->putJson("/api/v1/areas/{$area}", ['public_access' => 'invalid'])->assertUnprocessable();
        $this->putJson("/api/v1/areas/{$area}", ['public_access' => true])->assertOk();
        $this->getJson("/api/v1/areas/{$area}")->assertOk()
            ->assertJsonPath('attrs_raw.source_properties.owner', 'خصوصی')
            ->assertJsonPath('attrs_raw.access_policy.public_access', true);
        $this->assertTrue($this->allows($area, 'walk'));
        $this->assertFalse($this->allows($area, 'wheelchair'));
        $this->assertFalse($this->allows($area, 'van'));
        $this->assertGreaterThan(0, $this->tileSize());
    }

    private function area(int $x, array $attrs = []): int
    {
        return (int) DB::selectOne(
            "INSERT INTO areas (geom, area_type, floor, attrs)
             VALUES (ST_Multi(ST_MakeEnvelope(?,4000000,?,4000020,32640)), 'courtyard', 0, ?::jsonb)
             RETURNING id",
            [$x, $x + 20, json_encode((object) $attrs)]
        )->id;
    }

    private function buildEdge(int $area, int $neighbor): void
    {
        foreach ([4000006, 4000014] as $y) {
            $door = DB::selectOne(
                'INSERT INTO doors (geom, floor, from_area, to_area)
                 VALUES (ST_SetSRID(ST_MakeLine(ST_MakePoint(500020, ?), ST_MakePoint(500020, ?)),32640),
                         0, ?, ?) RETURNING id',
                [$y - 1, $y + 1, $area, $neighbor]
            )->id;
            DB::insert(
                "WITH dap AS (
                    INSERT INTO door_access_points (geom, door_id, floor, from_area, to_area, needs_review)
                    VALUES (ST_SetSRID(ST_MakePoint(500020, ?),32640), ?, 0, ?, ?, false)
                    RETURNING *
                 )
                 INSERT INTO routing_nodes (geom, floor, kind, ref_table, ref_id, area_id)
                 SELECT geom, floor, 'door_access', 'door_access_points', id, from_area FROM dap",
                [$y, $door, $area, $neighbor]
            );
        }
        DB::insert(
            "INSERT INTO routing_edges_static (floor, src, dst, geom, base_cost, attrs)
             SELECT s.floor, s.id, t.id, ST_MakeLine(s.geom,t.geom), ST_Distance(s.geom,t.geom),
                    jsonb_build_object('area_id',s.area_id,'edge_type','intra_area')
             FROM routing_nodes s JOIN routing_nodes t ON s.area_id=t.area_id AND s.id<t.id
             WHERE s.area_id=?",
            [$area]
        );
        DB::statement('REFRESH MATERIALIZED VIEW mv_area_door_stats');
    }

    private function allows(int $area, string $mode): bool
    {
        return (bool) DB::selectOne(
            "SELECT is_allowed FROM fn_allowed_areas(now(), 'both', ?, 0::smallint) WHERE id=?",
            [$mode, $area]
        )->is_allowed;
    }

    private function tileSize(): int
    {
        return (int) DB::selectOne(
            'WITH point AS (
                SELECT ST_Transform(ST_SetSRID(ST_MakePoint(500020,4000010),32640),3857) AS p
             )
             SELECT octet_length(fn_routing_edges_static_mvt(
                 16, floor((ST_X(p) + 20037508.342789244) / 611.49622628141)::int,
                 floor((20037508.342789244 - ST_Y(p)) / 611.49622628141)::int, 0::smallint
             )) AS size FROM point'
        )->size;
    }
}
