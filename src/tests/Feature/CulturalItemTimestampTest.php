<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Integration tests against an isolated PostgreSQL/PostGIS baseline. */
class CulturalItemTimestampTest extends TestCase
{
    private string $marker;
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL/PostGIS database.');
        }
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->migration()->up();
        $this->withoutMiddleware([AdminAuth::class, \App\Http\Middleware\AdminSectionAccess::class]);
        $this->marker = 'cultural-created-at-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_18_160000_add_cultural_content_created_at.php');
    }

    private function poi(array $attrs = []): int
    {
        $id = (int) DB::selectOne(
            "INSERT INTO poi_points(geom,poi_type,floor,has_content,attrs)
             VALUES (ST_SetSRID(ST_MakePoint(500005,4000005),32640),'other',0,false,?::jsonb)
             RETURNING id",
            [json_encode((object) $attrs)]
        )->id;
        DB::table('i18n_texts')->insert([
            'entity_table' => 'poi_points', 'entity_id' => $id, 'field' => 'name',
            'lang' => 'fa', 'txt' => $this->marker.' نام مکان',
        ]);

        return $id;
    }

    private function content(int $poiId, string $lang, ?string $createdAt, array $extra = []): int
    {
        return (int) DB::table('contents')->insertGetId(array_replace([
            'poi_id' => $poiId, 'lang' => $lang, 'title' => $this->marker.' عنوان '.$lang,
            'body' => 'متن فرهنگی '.$lang, 'media' => '[]', 'created_at' => $createdAt,
        ], $extra));
    }

    private function details(int $poiId): array
    {
        return $this->getJson('/api/v1/cultural-items/'.$poiId)->assertOk()->json();
    }

    private function listed(int $poiId): array
    {
        $response = $this->getJson('/api/v1/cultural-items?'.http_build_query([
            'search' => $this->marker, 'pageSize' => 100,
        ]))->assertOk();
        $item = collect($response->json('items'))->firstWhere('poiId', $poiId);
        $this->assertNotNull($item, 'The cultural list must retain the existing POI.');

        return $item;
    }

    private function assertCreationDate(array $item, ?string $expected): void
    {
        $this->assertArrayHasKey('createdAt', $item);
        if ($expected === null) {
            $this->assertNull($item['createdAt']);

            return;
        }

        $this->assertIsString($item['createdAt']);
        $this->assertMatchesRegularExpression('/(?:Z|\+00:00)$/', $item['createdAt']);
        $this->assertSame(
            CarbonImmutable::parse($expected)->getTimestamp(),
            CarbonImmutable::parse($item['createdAt'])->getTimestamp()
        );
    }

    public function test_main_group_only_is_used_by_both_public_map_endpoints_for_legacy_data(): void
    {
        $groupId = DB::table('categories')->insertGetId([
            'code' => $this->marker, 'property_target' => 'group', 'level' => 1, 'is_active' => true,
        ]);
        $oldGroupId = DB::table('categories')->insertGetId([
            'code' => $this->marker.'-old', 'property_target' => 'group', 'level' => 1, 'is_active' => true,
        ]);
        $id = $this->poi(['grouping' => ['group_id' => (string) $groupId, 'sub_group_id' => null], 'group' => 'other']);
        $this->content($id, 'fa', null);
        DB::table('poi_points')->where('id', $id)->update(['category_leaf_id' => $oldGroupId]);

        $list = '/api/v1/groups/subgroups?withImages=false&search='.$this->marker;
        $this->getJson($list)->assertOk()
            ->assertJsonCount(1, 'subGroups')
            ->assertJsonPath('subGroups.'.$this->marker.'.0.label.fa', $this->marker.' نام مکان')
            ->assertJsonPath('subGroups.'.$this->marker.'.0.value', 'poi-'.$id);
        $this->getJson($list.'&group='.$this->marker.'-old')->assertOk()->assertJsonCount(0, 'subGroups');
        $this->getJson('/api/v1/landmark-places?poi_id='.$id)->assertOk()
            ->assertJsonPath('places.landmarkPlaces.0.group', $this->marker)
            ->assertJsonPath('places.landmarkPlaces.0.subGroup', null)
            ->assertJsonPath('places.landmarkPlaces.0.subGroupValue', 'poi-'.$id);
        $features = $this->getJson('/api/v1/maps/geojson?floor=0')->assertOk()->json('features');
        $feature = collect($features)->firstWhere('properties.id', 'poi_points-'.$id);
        $this->assertNotNull($feature);
        $this->assertSame($this->marker, $feature['properties']['group']);
        $this->assertNull($feature['properties']['subGroup']);
        $this->assertSame('poi-'.$id, $feature['properties']['subGroupValue']);
    }

    public function test_creating_and_editing_a_cultural_poi_persists_the_effective_category(): void
    {
        $groupId = DB::table('categories')->insertGetId([
            'code' => $this->marker, 'property_target' => 'group', 'level' => 1, 'is_active' => true,
        ]);
        $subGroupId = DB::table('categories')->insertGetId([
            'code' => $this->marker.'-child', 'property_target' => 'subGroup', 'level' => 2,
            'parent_id' => $groupId, 'is_active' => true,
        ]);
        $id = $this->postJson('/api/v1/cultural-items', [
            'poi' => [
                'location' => ['lat' => 36.2853, 'lng' => 59.6134],
                'grouping' => ['group_id' => $groupId],
            ],
            'translations' => ['fa' => ['title' => $this->marker]],
        ])->assertOk()->json('poiId');
        $this->assertDatabaseHas('poi_points', ['id' => $id, 'category_leaf_id' => $groupId]);

        $url = '/api/v1/cultural-items/'.$id;
        $this->putJson($url, [
            'poi_id' => $id, 'grouping' => ['group_id' => $groupId, 'sub_group_id' => $subGroupId],
        ])->assertOk();
        $this->assertDatabaseHas('poi_points', ['id' => $id, 'category_leaf_id' => $subGroupId]);
        $this->putJson($url, ['poi_id' => $id, 'grouping' => ['group_id' => $groupId, 'sub_group_id' => null]])->assertOk();
        $this->assertDatabaseHas('poi_points', ['id' => $id, 'category_leaf_id' => $groupId]);
    }

    public function test_list_and_details_use_first_content_creation_in_utc_without_changing_existing_fields(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'Asia/Tehran'");
        $id = $this->poi([
            'address_in_shrine' => 'صحن آزمون',
            'grouping' => ['group_id' => 42, 'sub_group_label' => 'گروه آزمایشی'],
            'cultural' => ['showUserFeedbacks' => false, 'showMediaGallery' => false, 'placeType' => 'farhangi'],
            'unrelated' => ['keep' => true],
        ]);
        $media = [['type' => 'image', 'url' => '/storage/cultural-test.jpg']];
        $this->content($id, 'fa', '2026-10-18 15:00:00+03:30', ['media' => json_encode($media)]);
        $this->content($id, 'en', '2026-10-15 09:20:30-04:00');
        // Timestamp ordering must not follow language preference or the last POI edit.
        DB::table('poi_points')->where('id', $id)->update(['updated_at' => '2026-10-18 20:00:00+00']);

        foreach ([$this->listed($id), $this->details($id)] as $item) {
            $this->assertCreationDate($item, '2026-10-15T13:20:30Z');
            $this->assertSame($this->marker.' نام مکان', $item['titles']['fa']);
            $this->assertSame('متن فرهنگی fa', $item['descriptions']['fa']);
            $this->assertSame('صحن آزمون', $item['addressInShrine']);
            $this->assertSame(['group_id' => 42, 'sub_group_label' => 'گروه آزمایشی'], $item['grouping']);
            $this->assertFalse($item['showUserFeedbacks']);
            $this->assertFalse($item['showMediaGallery']);
            $this->assertSame('farhangi', $item['placeType']);
            $this->assertSame(0, $item['location']['floor']);
            $this->assertSame(500005, (int) $item['location']['x']);
            $this->assertSame(4000005, (int) $item['location']['y']);
        }
        $this->assertEquals($media, $this->details($id)['media']['fa']);
        $this->assertEquals($media, $this->listed($id)['media']);
        $this->assertSame('/storage/cultural-test.jpg', $this->listed($id)['primaryImage']);
    }

    public function test_legacy_translation_makes_creation_unknown_even_when_another_translation_is_dated(): void
    {
        $id = $this->poi();
        $this->content($id, 'fa', '2026-10-15T13:20:30Z');
        $this->content($id, 'en', null);

        $this->assertCreationDate($this->listed($id), null);
        $this->assertCreationDate($this->details($id), null);
    }

    public function test_poi_without_content_stays_visible_with_explicit_unknown_creation(): void
    {
        $id = $this->poi();
        $this->assertCreationDate($this->listed($id), null);
        $this->assertCreationDate($this->details($id), null);
        $this->assertSame(0, DB::table('contents')->where('poi_id', $id)->count());
    }

    public function test_create_new_cultural_poi_records_real_database_creation_and_preserves_payload(): void
    {
        config(['app.timezone' => 'Asia/Tehran']);
        DB::statement("SET LOCAL TIME ZONE 'Asia/Tehran'");
        $groupId = DB::table('categories')->insertGetId([
            'code' => $this->marker, 'property_target' => 'poi_points', 'level' => 1,
        ]);
        $media = [['type' => 'image', 'url' => '/storage/new-cultural.jpg']];
        $time = ['all_hours' => true, 'gender' => ['male'], 'date_scope' => ['all']];
        $prayer = ['events' => ['fajr'], 'before_minutes' => 10, 'after_minutes' => 15];
        $item = $this->postJson('/api/v1/cultural-items', [
            'poi' => [
                'location' => ['lat' => 36.2853, 'lng' => 59.6134],
                'grouping' => ['group_id' => $groupId],
            ],
            'translations' => ['fa' => ['title' => $this->marker, 'body' => 'متن تازه', 'media' => $media]],
            'addressInShrine' => 'نشانی تازه',
            'settings' => ['showUserFeedbacks' => false, 'showMediaGallery' => false, 'placeType' => 'tarikhi'],
            'time_restrictions' => [$time], 'prayer_restrictions' => [$prayer],
        ])->assertOk()->json();
        $id = $item['poiId'];
        $row = DB::table('contents')->where('poi_id', $id)->where('lang', 'fa')->first();
        $this->assertNotNull($row->created_at);
        $this->assertSame(
            CarbonImmutable::parse(DB::selectOne('SELECT CURRENT_TIMESTAMP AS date')->date)->format('U.u'),
            CarbonImmutable::parse($row->created_at)->format('U.u')
        );
        $this->assertCreationDate($item, $row->created_at);
        $this->assertCreationDate($this->listed($id), $row->created_at);
        $this->assertSame($this->marker, $item['titles']['fa']);
        $this->assertSame('متن تازه', $item['descriptions']['fa']);
        $this->assertEquals($media, $item['media']['fa']);
        $this->assertSame('نشانی تازه', $item['addressInShrine']);
        $this->assertFalse($item['showUserFeedbacks']);
        $this->assertFalse($item['showMediaGallery']);
        $this->assertSame('tarikhi', $item['placeType']);
        $this->assertSame(0, $item['location']['floor']);
        $this->assertEqualsWithDelta(36.2853, $item['location']['lat'], 0.0000001);
        $this->assertEqualsWithDelta(59.6134, $item['location']['lng'], 0.0000001);
        $this->assertEquals([$time], $item['time_restrictions']);
        $this->assertEquals([$prayer], $item['prayer_restrictions']);
    }

    public static function culturalFloors(): array
    {
        return ['basement' => [-1], 'additional configured floor' => [2]];
    }

    #[DataProvider('culturalFloors')]
    public function test_new_cultural_poi_persists_floor_before_media_upload(int $floor): void
    {
        DB::table('routing_floors')->insert(['floor' => 2, 'label' => 'Second floor', 'sort_order' => 2]);
        $groupId = DB::table('categories')->insertGetId([
            'code' => $this->marker, 'property_target' => 'poi_points', 'level' => 1,
        ]);
        $item = $this->postJson('/api/v1/cultural-items', [
            'poi' => [
                'floor' => $floor,
                'location' => ['lat' => 36.2853, 'lng' => 59.6134],
                'grouping' => ['group_id' => $groupId],
            ],
            'translations' => ['fa' => ['title' => $this->marker, 'media' => []]],
        ])->assertOk()->assertJsonPath('location.floor', $floor)->json();

        $id = $item['poiId'];
        $this->assertDatabaseHas('poi_points', ['id' => $id, 'floor' => $floor]);
        $this->assertSame($floor, $this->details($id)['location']['floor']);
        $this->assertSame($floor, $this->listed($id)['location']['floor']);
    }

    public function test_edit_preserves_creation_media_and_unrelated_poi_and_restriction_data(): void
    {
        $id = $this->poi(['unrelated' => ['keep' => true]]);
        $media = [['url' => '/storage/unchanged.jpg']];
        $contentId = $this->content($id, 'fa', '2026-09-10T10:00:00Z', ['media' => json_encode($media)]);
        DB::table('access_time_restrictions')->insert([
            'entity_table' => 'poi_points', 'entity_id' => $id, 'all_hours' => true,
            'attrs' => json_encode(['all_hours' => true]),
        ]);
        DB::table('access_prayer_restrictions')->insert([
            'entity_table' => 'poi_points', 'entity_id' => $id, 'prayer_event' => 'fajr',
            'attrs' => json_encode(['events' => ['fajr']]),
        ]);
        $before = $this->details($id);
        $storedDate = DB::table('contents')->where('id', $contentId)->value('created_at');

        $item = $this->putJson('/api/v1/cultural-items/'.$id, [
            'poi_id' => $id,
            'translations' => ['fa' => ['title' => $this->marker.' ویرایش', 'body' => 'متن ویرایش شده']],
            // Clients cannot replace a server-owned creation date.
            'createdAt' => '2000-01-01T00:00:00Z',
        ])->assertOk()->json();

        $this->assertCreationDate($item, '2026-09-10T10:00:00Z');
        $this->assertCreationDate($this->listed($id), '2026-09-10T10:00:00Z');
        $this->assertSame($storedDate, DB::table('contents')->where('id', $contentId)->value('created_at'));
        $this->assertSame(1, DB::table('contents')->where('poi_id', $id)->count());
        $this->assertSame($this->marker.' ویرایش', $item['titles']['fa']);
        $this->assertSame('متن ویرایش شده', $item['descriptions']['fa']);
        $this->assertSame($media, $item['media']['fa']);
        foreach (['location', 'addressInShrine', 'grouping', 'showUserFeedbacks', 'showMediaGallery',
            'placeType', 'time_restrictions', 'prayer_restrictions'] as $key) {
            $this->assertSame($before[$key], $item[$key], $key.' must survive a translation edit.');
        }
        $attrs = json_decode(DB::table('poi_points')->where('id', $id)->value('attrs'), true);
        $this->assertTrue($attrs['unrelated']['keep']);
    }

    public function test_new_translation_keeps_known_and_unknown_item_history_and_legacy_edit_does_not_backfill(): void
    {
        $knownDate = DB::selectOne("SELECT CURRENT_TIMESTAMP - INTERVAL '1 year' AS date")->date;
        foreach ([$knownDate, null] as $createdAt) {
            $id = $this->poi();
            $legacyId = $this->content($id, 'fa', $createdAt);
            $item = $this->putJson('/api/v1/cultural-items/'.$id, [
                'poi_id' => $id,
                'translations' => ['en' => ['title' => 'New language', 'body' => 'New translation']],
            ])->assertOk()->json();
            $this->assertCreationDate($item, $createdAt);
            $this->assertCreationDate($this->listed($id), $createdAt);
            $this->assertNotNull(DB::table('contents')->where('poi_id', $id)->where('lang', 'en')->value('created_at'));
            $this->assertSame(2, DB::table('contents')->where('poi_id', $id)->count());

            $edited = $this->putJson('/api/v1/cultural-items/'.$id, [
                'poi_id' => $id,
                'translations' => ['fa' => ['title' => $this->marker.' ویرایش زبان اصلی', 'body' => 'ویرایش']],
            ])->assertOk()->json();
            $this->assertCreationDate($edited, $createdAt);
            if ($createdAt === null) {
                $this->assertNull(DB::table('contents')->where('id', $legacyId)->value('created_at'));
            }
        }
    }

    public function test_adding_first_content_to_existing_poi_records_creation(): void
    {
        $id = $this->poi();
        $this->assertCreationDate($this->details($id), null);
        $item = $this->postJson('/api/v1/cultural-items', [
            'poi_id' => $id,
            'translations' => ['fa' => ['title' => $this->marker, 'body' => 'اولین محتوا']],
        ])->assertOk()->json();

        $date = DB::table('contents')->where('poi_id', $id)->value('created_at');
        $this->assertNotNull($date);
        $this->assertCreationDate($item, $date);
        $this->assertCreationDate($this->listed($id), $date);
    }

    public function test_migration_keeps_old_dates_unknown_defaults_new_rows_and_rolls_back_without_losing_content(): void
    {
        $this->migration()->down();
        $id = $this->poi();
        $contentId = DB::table('contents')->insertGetId([
            'poi_id' => $id, 'lang' => 'fa', 'title' => $this->marker, 'body' => 'محتوای قدیمی',
            'media' => json_encode([['url' => '/old.jpg']]),
        ]);
        $before = (array) DB::table('contents')->where('id', $contentId)->first();
        $this->migration()->up();
        $this->migration()->up(); // A deployment retry must not invent legacy dates.
        $this->assertNull(DB::table('contents')->where('id', $contentId)->value('created_at'));
        $column = DB::selectOne("SELECT data_type, is_nullable, column_default
            FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'contents' AND column_name = 'created_at'");
        $this->assertSame('timestamp with time zone', $column->data_type);
        $this->assertSame('YES', $column->is_nullable);
        $this->assertNotNull($column->column_default);

        DB::statement("SET LOCAL TIME ZONE 'Asia/Tehran'");
        $newId = DB::table('contents')->insertGetId([
            'poi_id' => $id, 'lang' => 'en', 'title' => 'Direct database insert', 'media' => '[]',
        ]);
        $date = DB::table('contents')->where('id', $newId)->value('created_at');
        $this->assertNotNull($date);
        $this->assertSame(
            CarbonImmutable::parse(DB::selectOne('SELECT CURRENT_TIMESTAMP AS date')->date)->format('U.u'),
            CarbonImmutable::parse($date)->format('U.u')
        );
        $this->assertCreationDate($this->listed($id), null);

        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('contents', 'created_at'));
        $this->assertSame($before, (array) DB::table('contents')->where('id', $contentId)->first());
        $this->assertSame(2, DB::table('contents')->where('poi_id', $id)->count());
        $this->migration()->up();
        $this->assertSame(2, DB::table('contents')->where('poi_id', $id)->whereNull('created_at')->count());
        $this->assertCreationDate($this->details($id), null);
    }
}
