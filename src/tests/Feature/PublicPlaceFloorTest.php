<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicPlaceFloorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function poi(int $floor): int
    {
        return DB::selectOne("INSERT INTO poi_points(geom,poi_type,floor,has_content)
            VALUES (ST_SetSRID(ST_MakePoint(500005,4000005),32640),'landmark',?,true) RETURNING id", [$floor])->id;
    }

    private function qr(string $code, string $type = 'coordinate', ?int $id = null, array $attrs = []): void
    {
        DB::statement("INSERT INTO qrcodes(code,geom,target_type,target_id,attrs)
            VALUES (?,ST_SetSRID(ST_MakePoint(500005,4000005),32640),?, ?,?::jsonb)",
            [$code, $type, $id, json_encode((object) $attrs)]);
    }

    public function test_public_catalog_and_identical_xy_landmarks_keep_distinct_floors(): void
    {
        $this->getJson('/api/v1/floors')->assertOk()->assertJsonFragment(['floor' => 0]);
        foreach ([-1, 0] as $floor) {
            $id = $this->poi($floor);
            $payload = json_decode(DB::selectOne("SELECT fn_landmark_places_json('fa',20,NULL,NULL,?) AS data", [$id])->data, true);
            $places = $payload['places']['landmarkPlaces'];
            $this->assertCount(1, $places);
            $this->assertSame($floor, $places[0]['floor']);
            $this->assertSame((string) $id, $places[0]['id']);
        }
    }

    public function test_qr_uses_placement_or_target_floor_and_returns_coordinates(): void
    {
        $id = $this->poi(-1);
        $this->qr('floor-target-test', 'poi', $id);
        $this->getJson('/api/v1/qrcodes/floor-target-test')->assertOk()->assertJsonPath('floor', -1)
            ->assertJsonCount(2, 'coordinates');
        $this->qr('floor-explicit-zero-test', 'poi', $id, ['floor' => 0]);
        $this->getJson('/api/v1/qrcodes/floor-explicit-zero-test')->assertOk()->assertJsonPath('floor', 0);
    }

    public function test_unknown_invalid_and_inactive_qr_do_not_guess_a_floor(): void
    {
        foreach ([[], ['floor' => true], ['floor' => ''], ['floor' => 9999], ['floor' => -0.5]] as $index => $attrs) {
            $code = 'floor-unknown-test-'.$index;
            $this->qr($code, attrs: $attrs);
            $this->getJson('/api/v1/qrcodes/'.$code)->assertOk()->assertJsonPath('floor', null);
        }
        DB::table('qrcodes')->where('code', 'floor-unknown-test-0')->update(['is_active' => false]);
        $this->getJson('/api/v1/qrcodes/floor-unknown-test-0')->assertNotFound();
    }
}
