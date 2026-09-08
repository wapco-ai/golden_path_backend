<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\GuidancePointController;
use App\Services\GuidancePointImageService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Every database/storage operation is mocked. No database server or migrations run.
class GuidanceCoverageTest extends TestCase
{
    private function request(array $data): Request
    {
        $request = Request::create('/api/v1/admin/guidance-points', 'POST', $data);
        $request->setUserResolver(fn () => new class {
            public int $id = 1;
            public bool $is_admin = true;
            public function adminRoles() { return collect([(object) ['code' => 'admin']]); }
        });
        return $request;
    }

    private function controller(): GuidancePointController
    {
        $images = Mockery::mock(GuidancePointImageService::class);
        $images->shouldReceive('storeMany')->with([], 77, Mockery::type('array'))->andReturn([]);
        $images->shouldReceive('deleteKeys')->with([])->andReturnNull();
        $controller = Mockery::mock(GuidancePointController::class, [$images])->makePartial();
        $controller->shouldReceive('show')->with(77)->andReturn(response()->json(['success' => true, 'data' => ['id' => 77]]));
        return $controller;
    }

    private function mockLog(): void
    {
        $log = Mockery::mock(Builder::class);
        $log->shouldReceive('insert')->with(Mockery::type('array'))->once()->andReturn(true);
        DB::shouldReceive('table')->with('admin_activity_logs')->once()->andReturn($log);
    }

    public static function createRadii(): array
    {
        return [
            'omitted defaults to 100' => [[], 100.0],
            'explicit old default stays 10' => [['coverage_radius_m' => '10'], 10.0],
            'explicit decimal' => [['coverage_radius_m' => '33.53'], 33.53],
            'maximum accepted' => [['coverage_radius_m' => '100.00'], 100.0],
        ];
    }

    #[DataProvider('createRadii')]
    public function test_create_binds_default_or_explicit_radius(array $extra, float $expected): void
    {
        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('commit')->once();
        DB::shouldReceive('selectOne')->once()->withArgs(function ($sql, $bindings) use ($expected) {
            $this->assertStringContainsString('COALESCE(?, 100.00)', $sql);
            $this->assertSame($expected, (float) $bindings[8]);
            return true;
        })->andReturn((object) ['id' => 77]);
        $this->mockLog();
        $response = $this->controller()->store($this->request(array_merge([
            'floor' => 0, 'x' => 59.612, 'y' => 36.286,
        ], $extra)));
        $this->assertSame(201, $response->getStatusCode());
    }

    public function test_invalid_radii_fail_actual_controller_validation(): void
    {
        $method = new \ReflectionMethod(GuidancePointController::class, 'validator');
        $controller = $this->controller();
        foreach ([null, '', 'bad', 0, -1, 101, '100.01', '0.001', '5.555'] as $value) {
            $validator = $method->invoke($controller, $this->request(['coverage_radius_m' => $value]), true);
            $this->assertTrue($validator->fails(), 'Invalid radius must fail: ' . json_encode($value));
            $this->assertArrayHasKey('coverage_radius_m', $validator->errors()->toArray());
        }
    }

    public static function updateRadii(): array
    {
        return [
            'omitted preserves saved radius' => [[], null],
            'radius-only update' => [['coverage_radius_m' => '40.50'], '40.50'],
        ];
    }

    #[DataProvider('updateRadii')]
    public function test_update_changes_radius_only_when_explicit(array $data, ?string $expected): void
    {
        $point = Mockery::mock(Builder::class);
        foreach (['where', 'whereNull', 'selectRaw'] as $name) $point->shouldReceive($name)->andReturnSelf();
        $point->shouldReceive('first')->once()->andReturn((object) ['id' => 77]);
        DB::shouldReceive('table')->with('guidance_points as gp')->once()->andReturn($point);
        $images = Mockery::mock(Builder::class);
        foreach (['where', 'orderBy'] as $name) $images->shouldReceive($name)->andReturnSelf();
        $images->shouldReceive('get')->once()->andReturn(collect([]));
        DB::shouldReceive('table')->with('guidance_point_images')->once()->andReturn($images);
        DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
        DB::shouldReceive('update')->once()->withArgs(function ($sql, $bindings) use ($expected) {
            $this->assertStringNotContainsString('geom =', $sql);
            if ($expected === null) {
                $this->assertStringNotContainsString('coverage_radius_m =', $sql);
                $this->assertSame([1, 77], $bindings);
            } else {
                $this->assertStringContainsString('coverage_radius_m = ?', $sql);
                $this->assertSame([1, $expected, 77], $bindings);
            }
            return true;
        })->andReturn(1);
        $this->mockLog();
        $this->assertSame(200, $this->controller()->update($this->request($data), 77)->getStatusCode());
    }

    public function test_selector_preserves_per_point_radius_and_uses_100_only_for_null(): void
    {
        $query = Mockery::mock(Builder::class);
        foreach (['join', 'where', 'whereNull', 'whereNotNull', 'select', 'selectRaw', 'orderBy', 'limit'] as $name) $query->shouldReceive($name)->andReturnSelf();
        $query->shouldReceive('whereRaw')->once()->withArgs(function ($sql, $bindings) {
            $this->assertStringContainsString('LEAST(?::double precision, COALESCE(gp.coverage_radius_m, 100)::double precision)', $sql);
            $this->assertSame(250.0, $bindings[2]);
            return true;
        })->andReturnSelf();
        $query->shouldReceive('get')->once()->andReturn(collect([]));
        DB::shouldReceive('table')->with('guidance_points as gp')->once()->andReturn($query);
        DB::shouldReceive('selectOne')->never();
        $this->getJson('/api/v1/landmark-view-image?' . http_build_query([
            'geo' => ['lat' => 36.286, 'lng' => 59.612], 'heading' => 90,
            'floor' => 0, 'max_distance' => 250, 'source' => 'guidance_points',
        ]))->assertOk()->assertJsonPath('status', 'NO_MATCH');
    }

    public function test_migration_only_changes_the_default_and_is_reversible(): void
    {
        $migration = require database_path('migrations/2026_09_08_180000_set_guidance_coverage_default_to_100.php');
        DB::shouldReceive('statement')->with('ALTER TABLE public.guidance_points ALTER COLUMN coverage_radius_m SET DEFAULT 100.00')->once()->andReturn(true);
        DB::shouldReceive('statement')->with('ALTER TABLE public.guidance_points ALTER COLUMN coverage_radius_m SET DEFAULT 10.00')->once()->andReturn(true);
        $migration->up();
        $migration->down();
    }
}
