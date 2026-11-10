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
        // The baseline catalog contains -1/0; keep its real POI floor FK enabled.
        DB::statement("INSERT INTO routing_floors(floor,label,sort_order)
            VALUES (1,'طبقه ۱',1) ON CONFLICT (floor) DO NOTHING");
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_11_10_130000_localize_place_search.php');
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

    public function test_search_uses_only_the_selected_language_and_keeps_limits_and_identity(): void
    {
        $first = $this->poi(-1, 'آزمون کوی');
        $this->poi(0, 'آزمون کوی');
        DB::table('i18n_texts')->insert([
            'entity_table' => 'poi_points', 'entity_id' => $first,
            'field' => 'name', 'lang' => 'en', 'txt' => 'English Search Fixture',
        ]);
        $places = $this->places(['search' => 'آزمون  كُوى', 'limit' => 1]);
        $this->assertCount(1, $places);
        $this->assertSame((string) $first, $places[0]['id']);
        $this->assertSame(-1, $places[0]['floor']);
        $this->assertSame('آزمون کوی', $places[0]['title']);
        $this->assertSame([], $this->places(['search' => 'آزمون کوی', 'language' => 'en']));
        $this->assertSame([], $this->places(['search' => 'English Search Fixture']));
        $this->assertSame('English Search Fixture', $this->places([
            'search' => 'english search fixture', 'language' => 'en',
        ])[0]['title']);
        $this->assertSame([], $this->places(['search' => '%']));
        $this->assertSame([], $this->places(['search' => "no-match' OR true --"]));
    }

    public function test_rollback_and_reapply_preserve_poi_data_and_floor_metadata(): void
    {
        $id = $this->poi(-1, 'آزمون بازگشت');
        $query = ['search' => 'آزمون بازگشت', 'language' => 'en'];
        $this->assertSame([], $this->places($query));
        $this->migration()->down();
        $this->assertSame((string) $id, $this->places($query)[0]['id']);
        $this->assertSame(-1, (int) DB::table('poi_points')->where('id', $id)->value('floor'));
        $this->migration()->up();
        $this->migration()->up();
        $this->assertSame([], $this->places($query));
        $places = $this->places([...$query, 'language' => 'fa']);
        $this->assertSame((string) $id, $places[0]['id']);
        $this->assertSame(-1, $places[0]['floor']);
    }

    public function test_area_aliases_respect_language_and_keep_the_area_identity(): void
    {
        $area = $this->area('روضه آزمون', 0, 500000, 500040);
        $this->poi(0, 'روضه آزمونی');
        config(['area_endpoints.search_aliases' => [
            'fa' => [$area => ['روضه آزمونی']],
            'ar' => [$area => ['روضة الاختبار']],
        ]]);

        $query = ['search' => 'روضه آزموني', 'include_exact_areas' => 1];
        $this->assertSame(["area-{$area}"], array_column($this->places($query), 'id'));
        $this->assertSame([], $this->places([...$query, 'language' => 'ar']));
        $this->assertSame([], $this->places([...$query, 'search' => 'روضة الاختبار']));
        $places = $this->places([...$query, 'search' => 'روضة الاختبار', 'language' => 'ar']);
        $this->assertSame(["area-{$area}"], array_column($places, 'id'));
        $this->assertSame('روضة الاختبار', $places[0]['title']);
        $this->assertSame(["area-{$area}"], array_column($this->places([
            ...$query, 'search' => 'الاختبار', 'language' => 'ar',
        ]), 'id'));
    }

    public function test_area_search_preserves_a_matching_poi_with_localized_cultural_content(): void
    {
        $area = $this->area('روضه آزمون', 0, 500000, 500040);
        $title = 'روضه آزمونی';
        $cultural = $this->poi(0, $title);
        $this->poi(0, $title, true);
        config(['area_endpoints.search_aliases.fa' => [$area => [$title]]]);
        DB::table('contents')->insert([
            'poi_id' => $cultural, 'lang' => 'fa', 'title' => $title, 'body' => 'متن فرهنگی آزمون',
        ]);

        foreach ([$title, 'روضه'] as $search) {
            $matches = $this->places(['search' => $search, 'include_exact_areas' => 1]);
            $this->assertSame(["area-{$area}", (string) $cultural], array_column($matches, 'id'));
            $this->assertSame(['area', 'cultural'], array_column($matches, 'kind'));
            $this->assertSame('روضه آزمون', $matches[0]['title']);
            $this->assertSame($title, $matches[1]['title']);
            $this->assertSame('متن فرهنگی آزمون', $matches[1]['content']['body']);
        }

        $details = $this->places(['poi_id' => $cultural]);
        $this->assertSame($title, $details[0]['title']);
        $this->assertArrayNotHasKey('kind', $details[0]);
    }

    public function test_destination_search_includes_partial_public_areas_and_distinct_pois(): void
    {
        $title = 'صحن کوی آزمون';
        $ground = $this->area($title, 0, 500000, 500040);
        $lower = $this->area($title, -1, 500000, 500040);
        $this->area($title, 0, 500000, 500040, ['access_policy' => ['public_access' => false]]);
        $this->area($title, 0, 500060, 500061);
        $proxy = (string) $this->poi(0, $title);
        $internal = (string) $this->poi(0, 'مسئول صحن کوی آزمون');

        $this->assertEqualsCanonicalizing([$proxy, $internal], array_column($this->places(['search' => $title]), 'id'));
        $partial = ['search' => 'كوي آزمون', 'include_exact_areas' => 1];
        $this->assertSame(["area-{$lower}", $internal, "area-{$ground}"], array_column($this->places($partial), 'id'));
        // The omitted ground-floor area must not hide its otherwise eligible POI.
        $this->assertSame(["area-{$lower}", $proxy], array_column($this->places([...$partial, 'limit' => 2]), 'id'));

        $matches = $this->places(['search' => $title, 'include_exact_areas' => 1]);
        $this->assertSame(["area-{$lower}", "area-{$ground}", $internal], array_column($matches, 'id'));
        $this->assertSame([$lower, $ground], array_column($matches, 'areaId'));
        $this->assertSame([-1, 0], array_column(array_slice($matches, 0, 2), 'floor'));
        $this->assertSame(['area', 'area'], array_column($matches, 'kind'));

        $normalizedMatches = $this->places(['search' => 'صحن  كوي‌ آزمون', 'include_exact_areas' => 1]);
        $this->assertSame([$lower, $ground], array_column($normalizedMatches, 'areaId'));
    }

    public function test_exact_area_names_rank_above_partial_matches(): void
    {
        $this->area('صحن کوچک آزمون', -1, 500000, 500040);
        $exact = $this->area('صحن کوچک', 0, 500000, 500040);

        $matches = $this->places(['search' => 'صحن کوچک', 'include_exact_areas' => 1, 'limit' => 1]);
        $this->assertSame(["area-{$exact}"], array_column($matches, 'id'));
    }

    public function test_area_doors_use_the_selected_area_id_floor_and_access_geometry(): void
    {
        $outer = $this->area('صحن آزمون', 0, 500000, 500040);
        $inner = $this->area('رواق آزمون', 0, 500010, 500030);
        $private = $this->area('صحن خصوصی', 0, 500040, 500080, [
            'access_policy' => ['public_access' => false],
        ]);
        $outerAccess = $this->accessPoint($outer, 500000);
        $innerAccess = $this->accessPoint($inner, 500010);

        $this->getJson("/api/v1/area-doors?area_id={$outer}&floor=0")
            ->assertOk()
            ->assertJsonPath('data.area.id', $outer)
            ->assertJsonPath('data.area.floor', 0)
            ->assertJsonCount(1, 'data.doors')
            ->assertJsonPath('data.doors.0.accessId', $outerAccess)
            ->assertJsonPath('data.doors.0.coord32640', [500000, 4000015]);
        $this->getJson("/api/v1/area-doors?area_id={$outer}&floor=-1")->assertNotFound();
        $this->getJson("/api/v1/area-doors?area_id={$private}")->assertNotFound();

        $point = DB::selectOne('SELECT ST_X(geom) AS lon, ST_Y(geom) AS lat
            FROM (SELECT ST_Transform(ST_SetSRID(ST_MakePoint(500020,4000020),32640),4326) AS geom) AS point');
        $this->getJson("/api/v1/area-doors?lat={$point->lat}&lon={$point->lon}&floor=0")
            ->assertOk()
            ->assertJsonPath('data.area.id', $inner)
            ->assertJsonPath('data.doors.0.accessId', $innerAccess);
    }

    public function test_configured_area_group_is_one_result_with_only_public_outer_doors(): void
    {
        $title = 'رواق مرکب آزمون';
        $members = [
            $this->area($title, 0, 500000, 500040),
            $this->area($title, 0, 500040, 500080),
            $this->area($title, 0, 500080, 500120),
            $this->area($title, 0, 500120, 500160),
        ];
        config(['area_endpoints.groups' => [
            'test-riwaq' => ['floor' => 0, 'area_type' => 'courtyard', 'area_ids' => $members],
        ]]);
        $separate = $this->area($title, -1, 500000, 500040);
        $neighbor = $this->area('راهرو عمومی', 0, 500160, 500200);
        $private = $this->area('اتاق خصوصی', 0, 500200, 500240, [
            'access_policy' => ['public_access' => false],
        ]);
        $internalPoi = (string) $this->poi(0, 'کفشداری رواق مرکب آزمون');
        $this->poi(0, $title);
        $this->accessPoint($members[0], 500040, $members[1]);
        $this->accessPoint($members[1], 500080, $private);
        $this->accessPoint($members[2], 500120, $neighbor, true);
        $this->accessPoint($members[2], 500122);
        $outer = $this->accessPoint($members[3], 500160, $neighbor);
        $reverse = $this->accessPoint($neighbor, 500165, $members[3]);

        $matches = $this->places(['search' => $title, 'include_exact_areas' => 1]);
        $this->assertSame(["area-{$separate}", 'area-group-test-riwaq', $internalPoi], array_column($matches, 'id'));
        $this->assertSame('area', $matches[1]['kind']);
        $this->assertSame('test-riwaq', $matches[1]['areaGroupId']);
        $this->assertSame($members, $matches[1]['memberAreaIds']);
        $this->assertSame(0, $matches[1]['floor']);
        $this->assertArrayNotHasKey('areaId', $matches[1]);

        $response = $this->getJson('/api/v1/area-doors?area_group_id=test-riwaq&floor=0')
            ->assertOk()
            ->assertJsonPath('data.area.id', 'area-group-test-riwaq')
            ->assertJsonPath('data.area.areaGroupId', 'test-riwaq')
            ->assertJsonPath('data.area.memberAreaIds', $members)
            ->assertJsonPath('data.area.floor', 0)
            ->assertJsonCount(2, 'data.doors');
        $doors = array_column($response->json('data.doors'), null, 'accessId');
        $this->assertEqualsCanonicalizing([$outer, $reverse], array_keys($doors));
        $this->assertSame([1, 2], array_column($doors, 'doorNo'));
        $this->assertSame('راهرو عمومی', $doors[$outer]['otherAreaName']);
        $this->assertSame('راهرو عمومی', $doors[$reverse]['otherAreaName']);
        $this->assertSame([500160, 4000015], $doors[$outer]['coord32640']);

        $this->getJson("/api/v1/area-doors?area_id={$members[0]}&floor=0")
            ->assertOk()
            ->assertJsonPath('data', $response->json('data'));
        $this->getJson('/api/v1/area-doors?area_group_id=test-riwaq&floor=-1')->assertNotFound();
        $this->getJson('/api/v1/area-doors?area_group_id=unknown')->assertNotFound();
        $this->getJson("/api/v1/area-doors?area_group_id=test-riwaq&area_id={$members[0]}")
            ->assertUnprocessable();
    }

    public function test_an_exact_group_match_hides_only_configured_same_name_auxiliary_areas(): void
    {
        $title = 'رواق کوی آزمون';
        $groupArea = $this->area($title, 0, 500040, 500080);
        $auxiliary = $this->area($title, 0, 500000, 500040);
        $separate = $this->area($title, 0, 500080, 500120);
        $independentPoi = (string) $this->poi(0, $title);
        $group = [
            'floor' => 0,
            'area_type' => 'courtyard',
            'area_ids' => [$groupArea],
            'search_excluded_area_ids' => [$auxiliary],
        ];
        config(['area_endpoints.groups' => ['test-riwaq' => $group]]);

        $exact = ['search' => $title, 'include_exact_areas' => 1];
        $expected = ["area-{$separate}", 'area-group-test-riwaq', $independentPoi];
        $this->assertSame($expected, array_column($this->places($exact), 'id'));
        $normalized = $this->places([...$exact, 'search' => 'رواق  كوي‌ آزمون']);
        $this->assertSame([$separate], array_column($normalized, 'areaId'));
        $this->assertSame(['test-riwaq'], array_column($normalized, 'areaGroupId'));
        $this->getJson("/api/v1/area-doors?area_id={$auxiliary}&floor=0")
            ->assertOk()->assertJsonPath('data.area.id', $auxiliary);

        // Keep the auxiliary result if its own title is requested or the group is unavailable.
        DB::table('i18n_texts')->where('entity_table', 'areas')->where('entity_id', $auxiliary)
            ->update(['txt' => 'اتاق خدمات آزمون']);
        $renamed = $this->places([...$exact, 'search' => 'اتاق خدمات آزمون']);
        $this->assertSame([$auxiliary], array_column($renamed, 'areaId'));
        $partial = $this->places([...$exact, 'search' => 'آزمون']);
        $this->assertContains($auxiliary, array_column($partial, 'areaId'));
        $this->assertContains('test-riwaq', array_column($partial, 'areaGroupId'));
        DB::table('i18n_texts')->where('entity_table', 'areas')->where('entity_id', $auxiliary)
            ->update(['txt' => $title]);
        config(['area_endpoints.groups' => ['test-riwaq' => [...$group, 'floor' => -1]]]);
        $this->assertSame([$groupArea, $auxiliary, $separate], array_column($this->places($exact), 'areaId'));
    }

    private function area(string $name, int $floor, int $left, int $right, array $attrs = []): int
    {
        $id = (int) DB::selectOne(
            "INSERT INTO areas (geom, area_type, floor, attrs)
             VALUES (ST_Multi(ST_MakeEnvelope(?,4000000,?,4000040,32640)), 'courtyard', ?, ?::jsonb)
             RETURNING id",
            [$left, $right, $floor, json_encode((object) $attrs)]
        )->id;
        DB::table('i18n_texts')->insert([
            'entity_table' => 'areas', 'entity_id' => $id,
            'field' => 'name', 'lang' => 'fa', 'txt' => $name,
        ]);
        return $id;
    }

    private function accessPoint(int $area, int $x, ?int $otherArea = null, bool $needsReview = false): int
    {
        $doorId = DB::selectOne(
            'INSERT INTO doors (geom, floor, from_area, to_area)
             VALUES (ST_SetSRID(ST_MakeLine(ST_MakePoint(?,4000014),ST_MakePoint(?,4000016)),32640), 0, ?, ?)
             RETURNING id',
            [$x + 3, $x + 3, $area, $otherArea]
        )->id;
        return (int) DB::selectOne(
            'INSERT INTO door_access_points (geom, door_id, floor, from_area, to_area, needs_review)
             VALUES (ST_SetSRID(ST_MakePoint(?,4000015),32640), ?, 0, ?, ?, ?::boolean)
             RETURNING id',
            [$x, $doorId, $area, $otherArea, $needsReview ? 'true' : 'false']
        )->id;
    }
}
