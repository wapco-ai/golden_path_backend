<?php

namespace Tests\Feature;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

// Every DB operation is mocked. No migrations, database server or test DB needed.
class GuidanceImageSourceTest extends TestCase
{
    private const LAT = 36.287841848029;
    private const LNG = 59.614226482676;

    protected function setUp(): void
    {
        parent::setUp();
        config(['guidance.local_north_offset_deg' => 30.0]);
    }

    private function points(array $rows): void
    {
        $query = Mockery::mock(Builder::class);
        foreach (['where', 'whereNull', 'whereNotNull', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'limit'] as $method) {
            $query->shouldReceive($method)->andReturnSelf();
        }
        $query->shouldReceive('get')->once()->andReturn(collect($rows));
        DB::shouldReceive('table')->with('guidance_points as gp')->once()->andReturn($query);
    }

    private function images(array $rows): void
    {
        $query = Mockery::mock(Builder::class);
        foreach (['whereIn', 'orderBy'] as $method) {
            $query->shouldReceive($method)->andReturnSelf();
        }
        $query->shouldReceive('get')->once()->andReturn(collect($rows));
        DB::shouldReceive('table')->with('guidance_point_images as gpi')->once()->andReturn($query);
    }

    private function url(array $extra = []): string
    {
        return '/api/v1/landmark-view-image?' . http_build_query(array_replace([
            'geo' => ['lat' => self::LAT, 'lng' => self::LNG],
            'heading' => 0,
            'floor' => 0,
            'fov' => 60,
            'max_distance' => 250,
            'source' => 'guidance_points',
        ], $extra));
    }

    private function point(int $id, float $lat, float $lng, float $distance, int $sort = 0): object
    {
        return (object) [
            'guidance_point_id' => $id,
            'floor' => 0,
            'area_id' => null,
            'title' => "Guidance {$id}",
            'description' => null,
            'point_azimuth_deg' => null,
            'coverage_radius_m' => 100,
            'point_sort_order' => $sort,
            'distance_m' => $distance,
            'longitude' => $lng,
            'latitude' => $lat,
        ];
    }

    private function image(
        int $id,
        int $pointId,
        string $orientation,
        float $azimuth,
        int $sortOrder = 1,
        float $fov = 60
    ): object {
        return (object) [
            'image_id' => $id,
            'point_id' => $pointId,
            'image_url' => "/storage/{$id}.jpg",
            'image_key' => "uploads/guidance_points/{$pointId}/images/{$id}.jpg",
            'image_sort_order' => $sortOrder,
            'view_orientation' => $orientation,
            'image_azimuth_deg' => $azimuth,
            'fov_deg' => $fov,
            'caption' => null,
            'attrs' => '{}',
        ];
    }

    private function storageUrl(string $url = '/storage/test.jpg'): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('url')->once()->andReturn($url);
        Storage::shouldReceive('disk')->with('public')->once()->andReturn($disk);
    }

    public function test_point_fov_is_applied_before_local_north_image_selection(): void
    {
        $northPoint = $this->point(13, self::LAT + 0.001, self::LNG, 100);
        $this->points([$northPoint]);
        $this->images([
            $this->image(15, 13, 'east', 90),
            $this->image(16, 13, 'south', 180, 2, 20),
        ]);
        DB::shouldReceive('selectOne')->never();
        $this->storageUrl('/storage/16.jpg');

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('source', 'guidance_points')
            ->assertJsonPath('guidance_point_id', 13)
            ->assertJsonPath('selected_orientation', 'south')
            ->assertJsonPath('image.id', 16)
            ->assertJsonPath('image.azimuth_deg', 180)
            ->assertJsonPath('local_north_offset_deg', 30)
            ->assertJsonPath('imageMatched', false)
            ->assertJsonPath('poi_id', null);
    }

    public function test_nearest_visible_point_without_images_switches_to_next_point(): void
    {
        $near = $this->point(13, self::LAT + 0.0003, self::LNG, 30);
        $far = $this->point(14, self::LAT + 0.0010, self::LNG, 100);
        $this->points([$near, $far]);
        $this->images([
            $this->image(21, 14, 'south', 180),
        ]);
        DB::shouldReceive('selectOne')->never();
        $this->storageUrl('/storage/21.jpg');

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('guidance_point_id', 14)
            ->assertJsonPath('image.id', 21);
    }

    public function test_nearer_point_outside_request_fov_is_skipped_for_farther_visible_point(): void
    {
        $east = $this->point(13, self::LAT, self::LNG + 0.0002, 20);
        $north = $this->point(14, self::LAT + 0.0010, self::LNG, 100);
        $this->points([$east, $north]);
        $this->images([
            $this->image(31, 14, 'south', 180),
        ]);
        DB::shouldReceive('selectOne')->never();
        $this->storageUrl('/storage/31.jpg');

        $this->getJson($this->url(['heading' => 0, 'fov' => 60]))->assertOk()
            ->assertJsonPath('guidance_point_id', 14)
            ->assertJsonPath('image.id', 31);
    }

    public function test_image_fov_does_not_remove_best_available_image(): void
    {
        $northPoint = $this->point(13, self::LAT + 0.001, self::LNG, 100);
        $this->points([$northPoint]);
        $this->images([
            $this->image(41, 13, 'south', 180, 1, 10),
        ]);
        DB::shouldReceive('selectOne')->never();
        $this->storageUrl('/storage/41.jpg');

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('image.id', 41)
            ->assertJsonPath('imageMatched', false);
    }

    public function test_point_with_only_unusable_image_metadata_switches_to_next_point(): void
    {
        $near = $this->point(13, self::LAT + 0.0003, self::LNG, 30);
        $far = $this->point(14, self::LAT + 0.0010, self::LNG, 100);
        $this->points([$near, $far]);
        $bad = $this->image(51, 13, 'unknown', 0);
        $bad->image_azimuth_deg = null;
        $good = $this->image(52, 14, 'south', 180);
        $this->images([$bad, $good]);
        DB::shouldReceive('selectOne')->never();
        $this->storageUrl('/storage/52.jpg');

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('guidance_point_id', 14)
            ->assertJsonPath('image.id', 52);
    }

    public function test_guidance_only_never_falls_back_to_poi(): void
    {
        $this->points([]);
        DB::shouldReceive('table')->with('guidance_point_images as gpi')->never();
        DB::shouldReceive('selectOne')->never();

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('status', 'NO_MATCH')
            ->assertJsonPath('source', 'guidance_points')
            ->assertJsonPath('image', null);
    }

    public function test_wrong_heading_excludes_point_before_loading_images(): void
    {
        $northPoint = $this->point(13, self::LAT + 0.001, self::LNG, 100);
        $this->points([$northPoint]);
        DB::shouldReceive('table')->with('guidance_point_images as gpi')->never();
        DB::shouldReceive('selectOne')->never();

        $this->getJson($this->url(['heading' => 180]))->assertOk()
            ->assertJsonPath('status', 'NO_MATCH')
            ->assertJsonPath('image', null);
    }

    public function test_auto_preserves_legacy_poi_fallback(): void
    {
        $this->points([]);
        DB::shouldReceive('table')->with('guidance_point_images as gpi')->never();
        DB::shouldReceive('selectOne')->once()->andReturn((object) [
            'data' => json_encode(['status' => 'OK', 'poi_id' => 22, 'image' => ['url' => '/poi.jpg']]),
        ]);

        $this->getJson($this->url(['source' => 'auto']))->assertOk()->assertJsonPath('poi_id', 22);
    }

    public function test_strict_source_requires_floor(): void
    {
        DB::shouldReceive('table')->never();
        $this->getJson($this->url(['floor' => null]))->assertUnprocessable()->assertJsonValidationErrors('floor');
    }

    public function test_invalid_source_and_coordinates_are_rejected(): void
    {
        DB::shouldReceive('table')->never();
        $this->getJson($this->url(['source' => 'unknown']))->assertUnprocessable()->assertJsonValidationErrors('source');
        $this->getJson($this->url(['geo' => ['lat' => 91, 'lng' => 59]]))
            ->assertUnprocessable()->assertJsonValidationErrors('geo.lat');
    }
}
