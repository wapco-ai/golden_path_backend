<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Services\CategoryAdminService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Real PostgreSQL/HTTP coverage for dates, duplicate scopes and atomic writes. */
class CategoryAdminWriteTest extends TestCase
{
    private bool $transactionStarted = false;
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL/PostGIS database.');
        }
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->migration()->up();
        $this->withoutMiddleware(AdminAuth::class);
        Storage::fake('public');
        $this->marker = 'category-admin-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) DB::rollBack();
        parent::tearDown();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_18_200000_add_category_created_at.php');
    }

    private function create(string $title, array $extra = []): array
    {
        return $this->postJson('/api/v1/admin/categories', ['title' => $title] + $extra)->assertCreated()->json();
    }

    private function child(int $parentId, string $title, array $extra = []): array
    {
        return $this->postJson('/api/v1/admin/categories/'.$parentId.'/subcategories', ['title' => $title] + $extra)
            ->assertCreated()->json();
    }

    private function listed(int $id, bool $withChildren = true): array
    {
        $items = $this->getJson('/api/v1/admin/categories?'.http_build_query([
            'search' => $this->marker, 'pageSize' => 100, 'includeSubcategories' => $withChildren ? '1' : '0',
        ]))->assertOk()->json('items');
        $item = collect($items)->firstWhere('id', $id);
        $this->assertNotNull($item);
        return $item;
    }

    private function photo(): UploadedFile
    {
        return new UploadedFile(__DIR__.'/../Fixtures/cultural-media/tiny.jpg', 'photo.jpg', 'image/jpeg', null, true);
    }

    private function raw(string $title, ?int $parentId = null, bool $active = true, bool $includeDate = true): int
    {
        $code = 'category_fixture_'.bin2hex(random_bytes(8));
        $values = [
            'code' => $code, 'label_key' => $code, 'property_target' => 'group',
            'parent_id' => $parentId, 'level' => $parentId === null ? 1 : 2,
            'sort_order' => 0, 'is_active' => $active,
        ];
        if ($includeDate) $values['created_at'] = null;
        $id = (int) DB::table('categories')->insertGetId($values);
        DB::table('i18n_texts')->insert([
            'entity_table' => 'categories', 'entity_id' => $id, 'field' => 'name', 'lang' => 'fa', 'txt' => $title,
        ]);
        return $id;
    }

    private function assertDatabaseDate(int $id, ?string $date): void
    {
        $stored = DB::table('categories')->where('id', $id)->value('created_at');
        if ($stored === null) {
            $this->assertNull($date);
        } else {
            $this->assertSame(CarbonImmutable::parse($stored)->utc()->toIso8601String(), $date);
        }
    }

    public function test_migration_keeps_existing_dates_unknown_and_future_inserts_use_database_clock(): void
    {
        $this->migration()->down();
        $legacy = $this->raw($this->marker.' قدیمی', null, true, false);
        $this->migration()->up();
        $this->assertNull($this->listed($legacy)['createdAt']);
        $this->assertNull(DB::table('categories')->where('id', $legacy)->value('created_at'));
        DB::statement("SET LOCAL TIME ZONE 'Asia/Tehran'");
        $future = $this->raw($this->marker.' آینده', null, true, false);
        $now = DB::selectOne('SELECT CURRENT_TIMESTAMP AS date')->date;
        $this->assertSame(CarbonImmutable::parse($now)->getTimestamp(), CarbonImmutable::parse(DB::table('categories')->where('id', $future)->value('created_at'))->getTimestamp());
        $this->assertDatabaseDate($future, $this->listed($future)['createdAt']);
        $this->assertStringEndsWith('+00:00', $this->listed($future)['createdAt']);
        $this->migration()->up();
        $this->assertNull($this->listed($legacy)['createdAt']);
    }

    public function test_create_batch_child_and_dedicated_child_dates_are_returned_in_every_response_path_and_stay_stable(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'Asia/Tehran'");
        $parent = $this->create($this->marker.' اصلی', [
            'description' => 'شرح', 'icon' => 'Pictogram34.png', 'languageTitles' => ['english' => 'English'],
            'subcategories' => [['title' => 'فرزند اول']], 'created_at' => '1999-01-01T00:00:00Z',
        ]);
        $child = $parent['subcategories'][0];
        $this->assertNotNull($parent['createdAt']);
        $this->assertDatabaseDate($parent['id'], $parent['createdAt']);
        $this->assertDatabaseDate($child['id'], $child['createdAt']);
        $this->assertSame('شرح', $parent['description']);
        $this->assertSame('English', $parent['languageTitles']['english']);
        $this->assertSame('Pictogram34.png', $parent['meta']['iconRaw']);
        $second = $this->child($parent['id'], 'فرزند دوم');
        $this->assertDatabaseDate($second['id'], $second['createdAt']);
        $listed = $this->listed($parent['id']);
        $this->assertSame($parent['createdAt'], $listed['createdAt']);
        $this->assertSame($child['createdAt'], $listed['subcategories'][0]['createdAt']);
        $this->assertSame($second['createdAt'], $listed['subcategories'][1]['createdAt']);
        $this->assertSame($parent['createdAt'], $this->listed($parent['id'], false)['createdAt']);
        $children = $this->getJson('/api/v1/admin/categories/'.$parent['id'].'/subcategories')->assertOk()->json('items');
        $this->assertSame([$child['createdAt'], $second['createdAt']], array_column($children, 'createdAt'));

        $updated = $this->putJson('/api/v1/admin/categories/'.$parent['id'], [
            'title' => $this->marker.' ویرایش', 'status' => 'inactive', 'description' => 'شرح جدید',
            'created_at' => '1999-01-01', 'createdAt' => '1999-01-01',
        ])->assertOk()->json();
        $this->assertSame($parent['createdAt'], $updated['createdAt']);
        $updatedChild = $this->putJson('/api/v1/admin/subcategories/'.$child['id'], [
            'title' => 'فرزند اصلاح شده', 'status' => 'inactive', 'createdAt' => '1999-01-01',
        ])->assertOk()->json();
        $this->assertSame($child['createdAt'], $updatedChild['createdAt']);
    }

    public function test_legacy_unknown_dates_remain_null_when_category_or_subcategory_is_edited(): void
    {
        $id = $this->raw($this->marker.' قدیمی');
        $childId = $this->raw('قدیمی فرزند', $id);
        $this->putJson('/api/v1/admin/categories/'.$id, ['title' => $this->marker.' قدیمی ویرایش', 'status' => 'inactive'])
            ->assertOk()->assertJsonPath('createdAt', null)->assertJsonPath('subcategories.0.createdAt', null);
        $this->putJson('/api/v1/admin/subcategories/'.$childId, ['title' => 'قدیمی فرزند ویرایش', 'status' => 'inactive'])
            ->assertOk()->assertJsonPath('createdAt', null);
        $this->assertNull($this->listed($id)['createdAt']);
        $this->assertNull(DB::table('categories')->where('id', $childId)->value('created_at'));
    }

    public function test_top_level_duplicate_name_ignores_code_property_target_and_inactive_status(): void
    {
        $this->create($this->marker.' یک گروه', ['code' => $this->marker.'-a', 'property_target' => 'group', 'status' => 'inactive']);
        $count = DB::table('categories')->count();
        $response = $this->postJson('/api/v1/admin/categories', [
            'title' => $this->marker.' یک گروه', 'code' => $this->marker.'-b', 'property_target' => 'areas',
        ])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertSame('دسته‌بندی دیگری با این عنوان وجود دارد.', $response->json('errors.title.0'));
        $this->assertSame($count, DB::table('categories')->count());
    }

    public function test_duplicate_comparison_folds_only_approved_whitespace_persian_letters_and_case(): void
    {
        $this->create($this->marker.' کی ABC');
        foreach ([
            '  '.$this->marker.'   كي abc  ',
            $this->marker."\u{00A0}\u{2002}كي\tabc",
        ] as $title) {
            $this->postJson('/api/v1/admin/categories', ['title' => $title])
                ->assertUnprocessable()->assertJsonValidationErrors('title');
        }
        foreach ([$this->marker.' ک‌ی abc', $this->marker.' کِی abc', $this->marker.' کی abc۱', $this->marker.' کی abc1'] as $title) {
            $this->create($title);
        }
    }

    public function test_unicode_whitespace_only_titles_cannot_be_created(): void
    {
        $this->postJson('/api/v1/admin/categories', ['title' => "\u{00A0}\u{2002}"])
            ->assertUnprocessable()->assertJsonValidationErrors('title');
    }

    public function test_subcategory_duplicates_are_scoped_to_parent_and_include_inactive_siblings(): void
    {
        $first = $this->create($this->marker.' اول');
        $second = $this->create($this->marker.' دوم');
        $child = $this->child($first['id'], 'کی ABC', ['status' => 'inactive']);
        $response = $this->postJson('/api/v1/admin/categories/'.$first['id'].'/subcategories', ['title' => '  كي abc '])
            ->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertSame('زیرگروهی با این عنوان در این دسته‌بندی وجود دارد.', $response->json('errors.title.0'));
        $this->child($second['id'], 'کی ABC');
        $this->child($first['id'], $first['title']); // Parent and child names have separate scopes.
        $this->putJson('/api/v1/admin/subcategories/'.$child['id'], ['title' => 'کی ABC', 'status' => 'active'])
            ->assertOk()->assertJsonPath('status', 'active');
    }

    public function test_rename_to_an_existing_category_or_sibling_is_rejected_without_changing_data(): void
    {
        $one = $this->create($this->marker.' اول');
        $two = $this->create($this->marker.' دوم');
        $this->post('/api/v1/admin/categories/'.$two['id'], [
            '_method' => 'PUT', 'payload' => json_encode(['title' => $one['title'], 'status' => 'inactive']),
            'icon' => $this->photo(),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertSame($two['title'], $this->listed($two['id'])['title']);
        $this->assertSame('active', $this->listed($two['id'])['status']);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $childOne = $this->child($one['id'], 'اول');
        $childTwo = $this->child($one['id'], 'دوم');
        $this->putJson('/api/v1/admin/subcategories/'.$childTwo['id'], ['title' => $childOne['title'], 'status' => 'inactive'])
            ->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertTrue(DB::table('categories')->where('id', $childTwo['id'])->value('is_active'));
    }

    public function test_unchanged_legacy_duplicate_names_remain_editable_without_merging_rows(): void
    {
        $one = $this->raw($this->marker.' كي abc');
        $two = $this->raw($this->marker.' کی ABC');
        $childOne = $this->raw('كي abc', $one);
        $childTwo = $this->raw('کی ABC', $one);
        $count = DB::table('categories')->count();
        $this->putJson('/api/v1/admin/categories/'.$two, ['title' => '  '.$this->marker.' کی abc ', 'status' => 'inactive'])
            ->assertOk()->assertJsonPath('createdAt', null);
        $this->putJson('/api/v1/admin/categories/'.$one, ['description' => 'تغییر شرح'])
            ->assertOk()->assertJsonPath('description', 'تغییر شرح');
        $this->putJson('/api/v1/admin/subcategories/'.$childTwo, ['title' => ' كي ABC ', 'status' => 'inactive'])
            ->assertOk()->assertJsonPath('createdAt', null);
        $this->assertSame($count, DB::table('categories')->count());
        $this->assertTrue(DB::table('categories')->where('id', $childOne)->exists());
    }

    public function test_normalized_batch_duplicates_abort_before_any_category_translation_or_file_is_written(): void
    {
        $categories = DB::table('categories')->count();
        $texts = DB::table('i18n_texts')->count();
        $this->post('/api/v1/admin/categories', [
            'payload' => json_encode(['title' => $this->marker, 'subcategories' => [
                ['title' => 'کی ABC'], ['title' => '  كي abc '],
            ]]), 'icon' => $this->photo(),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('subcategories.1.title');
        $this->assertSame($categories, DB::table('categories')->count());
        $this->assertSame($texts, DB::table('i18n_texts')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_duplicate_category_creation_is_rejected_before_file_storage_in_multipart_and_json(): void
    {
        $created = $this->create($this->marker);
        $this->post('/api/v1/admin/categories', [
            'payload' => json_encode(['title' => $this->marker]), 'image' => $this->photo(),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame($created['id'], $this->listed($created['id'])['id']);
    }

    public function test_valid_multipart_create_and_method_override_update_save_image_and_preserve_creation(): void
    {
        $created = $this->post('/api/v1/admin/categories', [
            'payload' => json_encode(['title' => $this->marker, 'subcategories' => [['title' => 'فرزند']]]),
            'icon' => $this->photo(),
        ], ['Accept' => 'application/json'])->assertCreated()->json();
        Storage::disk('public')->assertExists($created['meta']['iconRaw']);
        $this->assertNotNull($created['image']);
        $updated = $this->post('/api/v1/admin/categories/'.$created['id'], [
            '_method' => 'PUT', 'payload' => json_encode(['title' => $this->marker.' ویرایش', 'status' => 'inactive']),
            'icon' => $this->photo(),
        ], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame($created['createdAt'], $updated['createdAt']);
        $this->assertNotSame($created['meta']['iconRaw'], $updated['meta']['iconRaw']);
        Storage::disk('public')->assertExists($updated['meta']['iconRaw']);
        $this->assertCount(1, $updated['subcategories']);
    }

    public function test_rollback_and_reapply_change_dates_only_and_preserve_hierarchy_and_translations(): void
    {
        $parent = $this->create($this->marker, ['subcategories' => [['title' => 'فرزند']]]);
        $ids = [$parent['id'], $parent['subcategories'][0]['id']];
        $before = DB::table('categories')->whereIn('id', $ids)->orderBy('id')
            ->get(['id', 'parent_id', 'code', 'property_target', 'icon', 'level', 'sort_order', 'is_active'])->toArray();
        $texts = DB::table('i18n_texts')->where('entity_table', 'categories')->whereIn('entity_id', $ids)->get()->toArray();
        $this->migration()->down();
        $this->migration()->up();
        $this->assertEquals($before, DB::table('categories')->whereIn('id', $ids)->orderBy('id')
            ->get(['id', 'parent_id', 'code', 'property_target', 'icon', 'level', 'sort_order', 'is_active'])->toArray());
        $this->assertEquals($texts, DB::table('i18n_texts')->where('entity_table', 'categories')->whereIn('entity_id', $ids)->get()->toArray());
        $this->assertNull($this->listed($parent['id'])['createdAt']);
        $this->assertNull($this->listed($parent['id'])['subcategories'][0]['createdAt']);
    }
}
