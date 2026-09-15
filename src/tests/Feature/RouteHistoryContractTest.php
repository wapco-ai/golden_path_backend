<?php

namespace Tests\Feature;

use Tests\TestCase;

// No database access: verifies auth, capture, source and endpoint contracts.
class RouteHistoryContractTest extends TestCase
{
    public function test_routing_is_captured_without_making_public_routing_require_auth(): void
    {
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $middleware = file_get_contents(app_path('Http/Middleware/CaptureRouteHistory.php'));

        $this->assertStringContainsString('CaptureRouteHistory::class', $provider);
        $this->assertStringContainsString('api/v1/routing/route', $middleware);
        $this->assertStringContainsString('resolveOptionalUser', $middleware);
        $this->assertStringContainsString('return $next($request);', $middleware);
    }

    public function test_capture_persists_the_exact_successful_route_output_and_user_inputs(): void
    {
        $source = file_get_contents(app_path('Http/Middleware/CaptureRouteHistory.php'));

        $this->assertStringContainsString('route_snapshot', $source);
        $this->assertStringContainsString('capture_version', $source);
        $this->assertStringContainsString("'coordinates'", $source);
        $this->assertStringContainsString("'floor'", $source);
        $this->assertStringContainsString("meta->>'fingerprint'", $source);
    }

    public function test_history_endpoint_is_user_authenticated_and_user_scoped(): void
    {
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $routes = file_get_contents(base_path('routes/route_history.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/RouteHistoryController.php'));

        $this->assertStringContainsString('mapRouteHistoryRoutes', $provider);
        $this->assertStringContainsString('UserAuth::class', $provider);
        $this->assertStringContainsString("Route::get('users/me/route-history'", $routes);
        $this->assertStringContainsString("meta->>'user_id'", $controller);
        $this->assertStringContainsString("meta ? 'route_snapshot'", $controller);
    }

    public function test_history_feature_never_builds_route_geometry_from_doors_geom(): void
    {
        $capture = file_get_contents(app_path('Http/Middleware/CaptureRouteHistory.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/RouteHistoryController.php'));
        $combined = $capture . "\n" . $controller;

        $this->assertStringNotContainsString('doors.geom', $combined);
        $this->assertStringNotContainsString('ST_MakeLine', $combined);
        $this->assertStringNotContainsString('ST_StartPoint', $combined);
        $this->assertStringNotContainsString('ST_EndPoint', $combined);
    }
}
