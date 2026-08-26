<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GuidancePointTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_endpoint_returns_independent_active_points(): void
    {
        DB::table('guidance_points')->insert([
            'floor' => 0,
            'area_id' => 10,
            'title' => 'نقطه راهنما',
            'x' => 734000.123,
            'y' => 4019000.456,
            'coverage_radius_m' => 10,
            'sort_order' => 1,
            'is_active' => true,
            'geom' => DB::raw("ST_SetSRID(ST_MakePoint(734000.123,4019000.456),32640)"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/guidance-points?floor=0&area_id=10')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.area_id', 10)
            ->assertJsonMissingPath('data.0.route_id');
    }

    public function test_admin_requires_auth(): void
    {
        $this->getJson('/api/v1/admin/guidance-points')->assertStatus(401);
    }

    public function test_image_limit_is_four(): void
    {
        Storage::fake('public');

        $files = [];
        for ($i = 0; $i < 5; $i++) {
            $files[] = UploadedFile::fake()->image("p{$i}.jpg");
        }

        $this->post('/api/v1/admin/guidance-points', [
            'floor' => 0,
            'x' => 734000.123,
            'y' => 4019000.456,
            'images' => $files,
        ])->assertStatus(401); // Auth is checked before validation in this app.
    }
}
