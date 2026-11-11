<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\AdminSectionAccess;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminTableSortingTest extends TestCase
{
    private bool $transactionStarted = false;
    private string $search;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires an isolated PostgreSQL/PostGIS baseline.');
        }
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->withoutMiddleware([AdminAuth::class, AdminSectionAccess::class]);
        $this->search = 'admin-sort-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) DB::rollBack();
        parent::tearDown();
    }

    public function test_cultural_pages_use_displayed_persian_title_and_creation_date(): void
    {
        $first = $this->culturalPoint('پ', '2026-02-01');
        $second = $this->culturalPoint('آ', '2026-01-01', 'ت');
        $third = $this->culturalPoint('آ', '2026-01-01', 'ت');
        DB::table('contents')->insert(['poi_id' => $third, 'lang' => 'en', 'created_at' => null]);
        $this->culturalPoint('unrelated', '2026-01-01', matchSearch: false);

        $url = '/api/v1/cultural-items?'.http_build_query(['search' => $this->search, 'pageSize' => 2]);
        $this->assertPages($url, 'items', 'poiId', [$third, $second, $first]);
        $this->assertPages($url.'&sort=invalid:asc', 'items', 'poiId', [$third, $second, $first]);
        $this->assertPages($url.'&sort=title:asc', 'items', 'poiId', [$first, $third, $second]);
        $this->assertPages($url.'&sort=title:desc', 'items', 'poiId', [$third, $second, $first]);
        $this->assertPages($url.'&sort=created_at:asc', 'items', 'poiId', [$second, $first, $third]);
        $this->assertPages($url.'&sort=created_at:desc', 'items', 'poiId', [$first, $second, $third]);
    }

    public function test_guidance_pages_sort_all_matches_with_a_stable_id_tie_breaker(): void
    {
        $first = $this->guidancePoint('پ', 5);
        $second = $this->guidancePoint('ت', 0);
        $third = $this->guidancePoint('ت', 0);
        $this->guidancePoint('آ', 0, deleted: true);

        $url = '/api/v1/admin/guidance-points?'.http_build_query(['search' => $this->search, 'limit' => 2]);
        $this->assertPages($url, 'data', 'id', [$third, $second, $first]);
        $this->assertPages($url.'&sort=id:asc', 'data', 'id', [$first, $second, $third]);
        $this->assertPages($url.'&sort=title:asc', 'data', 'id', [$first, $third, $second]);
        $this->assertPages($url.'&sort=sort_order:asc', 'data', 'id', [$third, $second, $first]);
        $this->assertPages($url.'&sort=sort_order:desc', 'data', 'id', [$first, $third, $second]);
    }

    private function assertPages(string $url, string $rows, string $id, array $expected): void
    {
        foreach (array_chunk($expected, 2) as $index => $ids) {
            $response = $this->getJson($url.'&page='.($index + 1))->assertOk();
            $this->assertSame($ids, array_column($response->json($rows), $id), $url);
        }
    }

    private function culturalPoint(string $title, string $date, ?string $displayName = null, bool $matchSearch = true): int
    {
        $id = (int) DB::table('poi_points')->insertGetId([
            'poi_type' => 'other', 'floor' => 0,
            'geom' => DB::raw('ST_SetSRID(ST_MakePoint(500005,4000005),32640)'),
        ]);
        DB::table('contents')->insert([
            'poi_id' => $id, 'lang' => 'fa', 'title' => $title,
            'body' => $matchSearch ? $this->search : 'unrelated', 'created_at' => $date,
        ]);
        if ($displayName !== null) {
            DB::table('i18n_texts')->insert([
                'entity_table' => 'poi_points', 'entity_id' => $id, 'field' => 'name',
                'lang' => 'fa', 'txt' => "\t{$displayName} ",
            ]);
        }
        return $id;
    }

    private function guidancePoint(string $title, int $order, bool $deleted = false): int
    {
        return (int) DB::table('guidance_points')->insertGetId([
            'floor' => 0, 'title' => $title, 'description' => $this->search,
            'sort_order' => $order, 'x' => 500005, 'y' => 4000005,
            'geom' => DB::raw('ST_SetSRID(ST_MakePoint(500005,4000005),32640)'),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }
}
