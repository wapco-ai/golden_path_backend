<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CulturalItemsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class CulturalCoordinateValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('categories', fn ($table) => $table->integer('id')->primary());
        Schema::create('poi_points', fn ($table) => $table->integer('id')->primary());
        DB::table('categories')->insert(['id' => 1]);
        DB::table('poi_points')->insert(['id' => 7]);
        Route::post('/_test/cultural-coordinate-validation/{mode}', function (Request $request, string $mode) {
            $method = new ReflectionMethod(CulturalItemsController::class, 'validateUpsert');
            return $method->invoke(app(CulturalItemsController::class), $request, $mode === 'update');
        });
    }

    private function payload(string $mode, array $point): array
    {
        return $mode === 'update'
            ? ['poi_id' => 7, 'location' => $point]
            : ['translations' => ['fa' => ['title' => 'Coordinate test']], 'poi' => [
                'location' => $point, 'grouping' => ['group_id' => 1],
            ]];
    }

    public static function invalidCoordinates(): array
    {
        $cases = [];
        foreach (['create', 'update'] as $mode) {
            foreach ([
                ['lat', 90.00001], ['lat', -90.00001], ['lat', 'bad'], ['lat', ''],
                ['lng', 180.00001], ['lng', -180.00001], ['lng', null],
            ] as [$axis, $value]) {
                $cases[] = [$mode, $axis, $value];
            }
        }
        return $cases;
    }

    #[DataProvider('invalidCoordinates')]
    public function test_invalid_coordinates_are_rejected(string $mode, string $axis, mixed $value): void
    {
        $point = array_replace(['lat' => 36.29, 'lng' => 59.61], [$axis => $value]);
        $this->postJson('/_test/cultural-coordinate-validation/'.$mode, $this->payload($mode, $point))
            ->assertUnprocessable()->assertJsonValidationErrors(($mode === 'create' ? 'poi.location.' : 'location.').$axis);
    }

    public function test_valid_coordinates_keep_precision_and_accept_zero_and_boundaries(): void
    {
        foreach (['create', 'update'] as $mode) {
            foreach ([['lat' => 36.291234567, 'lng' => 59.617123456], ['lat' => 0, 'lng' => 0],
                ['lat' => -90, 'lng' => 180], ['lat' => 90, 'lng' => -180]] as $point) {
                $response = $this->postJson('/_test/cultural-coordinate-validation/'.$mode, $this->payload($mode, $point))->assertOk();
                $this->assertEquals($point, $response->json($mode === 'create' ? 'poi.location' : 'location'));
            }
        }
    }
}
