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
    protected function setUp(): void
    {
        parent::setUp();

        config(['guidance.local_north_offset_deg' => 0.0]);
    }

    private function candidates(array $rows): void
    {
        $query = Mockery::mock(Builder::class);
        foreach (['join', 'whereNull', 'whereNotNull', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'limit'] as $method) {
            $query->shouldReceive($method)->andReturnSelf();
        }
        $query->shouldReceive('where')->with('gp.is_active', true)->once()->andReturnSelf();
        $query->shouldReceive('where')->with('gp.floor', 0)->once()->andReturnSelf();
        $query->shouldReceive('get')->once()->andReturn(collect($rows));
        DB::shouldReceive('table')->with('guidance_points as gp')->once()->andReturn($query);
    }

    private function url(array $extra = []): string
    {
        return '/api/v1/landmark-view-image?' . http_build_query(array_replace([
            'geo' => ['lat' => 36.287841848029, 'lng' => 59.614226482676],
            'heading' => 270, 'floor' => 0, 'fov' => 45, 'source' => 'guidance_points',
        ], $extra));
    }

    private function westImage(): object
    {
        return (object) [
            'guidance_point_id' => 13, 'floor' => 0, 'area_id' => null,
            'title' => 'Guidance point', 'description' => null, 'point_azimuth_deg' => null,
            'coverage_radius_m' => 10, 'point_sort_order' => 0, 'image_id' => 15,
            'image_url' => '/storage/test.jpg', 'image_key' => 'uploads/guidance_points/13/images/test.jpg',
            'image_sort_order' => 1, 'view_orientation' => 'west', 'image_azimuth_deg' => 270,
            'fov_deg' => 60, 'caption' => null, 'attrs' => '{}', 'distance_m' => 0,
            'longitude' => 59.614226482676, 'latitude' => 36.287841848029,
        ];
    }

    public function test_guidance_only_returns_matching_image(): void
    {
        $this->candidates([$this->westImage()]);
        DB::shouldReceive('selectOne')->never();
        $disk = Mockery::mock();
        $disk->shouldReceive('url')->once()->andReturn('/storage/test.jpg');
        Storage::shouldReceive('disk')->with('public')->once()->andReturn($disk);
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('source', 'guidance_points')
            ->assertJsonPath('guidance_point_id', 13)
            ->assertJsonPath('image.id', 15)
            ->assertJsonPath('image.azimuth_deg', 270)
            ->assertJsonPath('heading', 270)
            ->assertJsonPath('poi_id', null);
    }

    public function test_local_north_offset_can_be_enabled_without_rewriting_image_azimuth(): void
    {
        config(['guidance.local_north_offset_deg' => 30.0]);
        $this->candidates([$this->westImage()]);
        DB::shouldReceive('selectOne')->never();
        $disk = Mockery::mock();
        $disk->shouldReceive('url')->once()->andReturn('/storage/test.jpg');
        Storage::shouldReceive('disk')->with('public')->once()->andReturn($disk);

        $this->getJson($this->url(['heading' => 300]))->assertOk()
            ->assertJsonPath('guidance_point_id', 13)
            ->assertJsonPath('image.azimuth_deg', 270)
            ->assertJsonPath('heading', 300);
    }

    public function test_guidance_only_never_falls_back_to_poi(): void
    {
        $this->candidates([]);
        DB::shouldReceive('selectOne')->never();
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('status', 'NO_MATCH')
            ->assertJsonPath('source', 'guidance_points')
            ->assertJsonPath('image', null);
    }

    public function test_wrong_heading_is_no_match_not_a_poi(): void
    {
        $this->candidates([$this->westImage()]);
        DB::shouldReceive('selectOne')->never();
        $this->getJson($this->url(['heading' => 90]))->assertOk()->assertJsonPath('image', null);
    }

    public function test_auto_preserves_legacy_poi_fallback(): void
    {
        $this->candidates([]);
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
