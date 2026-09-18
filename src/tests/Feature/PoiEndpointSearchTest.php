<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PoiEndpointSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        // Run the actual deployment migration inside the existing integration DB.
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_09_18_160000_enable_all_poi_endpoint_search.php');
    }

    private function poi(int $floor, string $name, bool $hasContent = false): int
    {
        $id = (int) DB::selectOne("INSERT INTO poi_points(geom,poi_type,floor,has_content)
            VALUES (ST_SetSRID(ST_MakePoint(500005,4000005),32640),'landmark',?,?::boolean) RETURNING id",
            [$floor, $hasContent ? 'true' : 'false'])->id;
        DB::table('i18n_texts')->insert([
            'entity_table' => 'poi_points', 'entity_id' => $id,
            'field' => 'name', 'lang' => 'fa', 'txt' => $name,
        ]);
        return $id;
    }

    private function places(array $query = []): array
    {
        return $this->getJson('/api/v1/landmark-places?'.http_build_query([
            'language' => 'fa', ...$query,
        ]))->assertOk()->json('places.landmarkPlaces');
    }

    public function test_endpoint_search_includes_contentless_imageless_uncategorized_pois_on_all_floors(): void
    {
        $ids = [];
        foreach ([-1, 0, 1] as $floor) {
            $ids[] = (string) $this->poi($floor, 'رواق آزمون جستجوی همگانی');
        }
        $places = $this->places(['search' => 'آزمون جستجوی همگانی', 'limit' => 30]);
        $this->assertCount(3, $places);
        $this->assertEqualsCanonicalizing($ids, array_column($places, 'id'));
        $this->assertEqualsCanonicalizing([-1, 0, 1], array_column($places, 'floor'));
        foreach ($places as $place) {
            $this->assertNull($place['image']);
            $this->assertNull($place['content']);
            $this->assertCount(2, $place['coordinates']);
        }
        $this->assertSame(0, DB::table('contents')->whereIn('poi_id', $ids)->count());
        $this->assertSame(3, DB::table('poi_points')->whereIn('id', $ids)->whereNull('category_leaf_id')->count());
    }

    public function test_normal_discovery_and_empty_search_do_not_become_all_poi_browsing(): void
    {
        $plain = (string) $this->poi(-1, 'آزمون بدون محتوا');
        $cultural = (string) $this->poi(0, 'آزمون دارای محتوا', true);
        foreach ([[], ['search' => ''], ['search' => '   ']] as $query) {
            $ids = array_column($this->places($query), 'id');
            $this->assertContains($cultural, $ids);
            $this->assertNotContains($plain, $ids);
        }
    }

    public function test_featured_discovery_keeps_its_existing_content_requirement(): void
    {
        $plain = $this->poi(-1, 'آزمون منتخب بدون محتوا');
        $cultural = $this->poi(0, 'آزمون منتخب فرهنگی', true);
        foreach ([$plain, $cultural] as $id) {
            DB::table('featured_landmark_places')->insert([
                'poi_id' => $id, 'is_active' => true, 'sort_order' => 0,
            ]);
        }
        foreach ([['featured' => 1], ['featured' => 1, 'search' => 'آزمون']] as $query) {
            $ids = array_column($this->places($query), 'id');
            $this->assertContains((string) $cultural, $ids);
            $this->assertNotContains((string) $plain, $ids);
        }
    }

    public function test_search_keeps_limits_language_fallback_and_real_poi_identity(): void
    {
        $first = $this->poi(-1, 'آزمون محدودیت');
        $this->poi(0, 'آزمون محدودیت');
        $places = $this->places(['search' => 'آزمون محدودیت', 'language' => 'en', 'limit' => 1]);
        $this->assertCount(1, $places);
        $this->assertSame((string) $first, $places[0]['id']);
        $this->assertSame(-1, $places[0]['floor']);
        $this->assertSame('آزمون محدودیت', $places[0]['title']);
        $this->assertSame([], $this->places(['search' => "no-match' OR true --"]));
    }

    public function test_rollback_and_reapply_preserve_poi_data_and_floor_metadata(): void
    {
        $id = $this->poi(-1, 'آزمون بازگشت');
        $query = ['search' => 'آزمون بازگشت'];
        $this->assertCount(1, $this->places($query));
        $this->migration()->down();
        $this->assertSame([], $this->places($query));
        $this->assertSame(-1, (int) DB::table('poi_points')->where('id', $id)->value('floor'));
        $this->migration()->up();
        $this->migration()->up();
        $places = $this->places($query);
        $this->assertCount(1, $places);
        $this->assertSame((string) $id, $places[0]['id']);
        $this->assertSame(-1, $places[0]['floor']);
    }
}
