<?php

namespace Tests\Feature;

use App\Http\Requests\StoreLocationShareRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

// No database access: validation, authorization and routing-source contracts only.
class LocationShareContractTest extends TestCase
{
    public function test_request_requires_valid_coordinate_and_supported_floor(): void
    {
        $rules = (new StoreLocationShareRequest())->rules();

        $valid = Validator::make([
            'recipientPhone' => '09121234567',
            'lat' => 36.2853,
            'lng' => 59.6134,
            'floor' => 0,
            'accuracyM' => 12.5,
        ], $rules);

        $this->assertFalse($valid->fails(), json_encode($valid->errors()->toArray(), JSON_UNESCAPED_UNICODE));

        $invalidFloor = Validator::make([
            'recipientPhone' => '09121234567',
            'lat' => 36.2853,
            'lng' => 59.6134,
            'floor' => 2,
        ], $rules);

        $this->assertTrue($invalidFloor->fails());
    }

    public function test_routes_are_user_authenticated_and_not_public(): void
    {
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $routes = file_get_contents(base_path('routes/location_shares.php'));

        $this->assertStringContainsString('UserAuth::class', $provider);
        $this->assertStringContainsString("Route::post('location-shares'", $routes);
        $this->assertStringContainsString("Route::get('location-shares/incoming'", $routes);
        $this->assertStringContainsString("Route::get('location-shares/outgoing'", $routes);
        $this->assertStringContainsString("Route::delete('location-shares/{id}'", $routes);
    }

    public function test_controller_scopes_visibility_and_revocation_to_the_authenticated_users(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/LocationShareController.php'));

        $this->assertStringContainsString("->where('recipient_user_id', \$user->id)", $source);
        $this->assertStringContainsString("->where('sender_user_id', \$user->id)", $source);
        $this->assertStringContainsString("->whereNull('revoked_at')", $source);
        $this->assertStringContainsString("->where('expires_at', '>', now())", $source);
        $this->assertStringContainsString('SHARE_TTL_MINUTES = 30', $source);
    }

    public function test_shared_geometry_is_transformed_to_project_srid_without_touching_routing_geometry_sources(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/LocationShareController.php'));

        $this->assertStringContainsString('ST_Transform(ST_SetSRID(ST_MakePoint', $source);
        $this->assertStringContainsString('4326), 32640)', $source);
        $this->assertStringNotContainsString('doors.geom', $source);
        $this->assertStringNotContainsString('routing_edges_static', $source);
        $this->assertStringNotContainsString('routing_nodes', $source);
    }
}
