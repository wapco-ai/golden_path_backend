<?php

namespace Tests\Feature;

use Tests\TestCase;

// No database access: verifies auth, capture, source and endpoint contracts.
class RouteHistoryContractTest extends TestCase
{
    public function test_routing_resolves_optional_user_without_making_public_routing_require_auth(): void
    {
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $middleware = file_get_contents(app_path('Http/Middleware/CaptureRouteHistory.php'));

        $this->assertStringContainsString('CaptureRouteHistory::class', $provider);
        $this->assertStringContainsString('api/v1/routing/route', $middleware);
        $this->assertStringContainsString('resolveOptionalUser', $middleware);
        $this->assertStringContainsString('return $next($request);', $middleware);
    }

    public function test_successful_snapshot_is_written_before_the_same_route_log_insert(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/RoutingController.php'));

        $this->assertStringContainsString("\$log['meta']['route_snapshot'] = \$result", $source);
        $this->assertStringContainsString("\$log['meta']['history']", $source);
        $this->assertStringContainsString("'capture_version' => 1", $source);
        $this->assertStringContainsString("'origin_floor'", $source);
        $this->assertStringContainsString("'destination_floor'", $source);
        $this->assertStringContainsString("'maxAlternatives'", $source);
    }

    public function test_history_endpoint_is_user_authenticated_and_uses_postgres_safe_json_predicates(): void
    {
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $routes = file_get_contents(base_path('routes/route_history.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/RouteHistoryController.php'));

        $this->assertStringContainsString('mapRouteHistoryRoutes', $provider);
        $this->assertStringContainsString('UserAuth::class', $provider);
        $this->assertStringContainsString("Route::get('users/me/route-history'", $routes);
        $this->assertStringContainsString("meta->>'user_id'", $controller);
        $this->assertStringContainsString("jsonb_exists(meta, 'route_snapshot')", $controller);
        $this->assertStringContainsString("jsonb_exists(meta, 'history')", $controller);
        $this->assertStringNotContainsString("meta ? 'route_snapshot'", $controller);
    }

    public function test_history_lookup_has_a_partial_user_and_time_index(): void
    {
        $sql = file_get_contents(base_path('../db/changes/20260915_204000_route_history_index.up.sql'));

        $this->assertStringContainsString('route_logs_user_history_ts_idx', $sql);
        $this->assertStringContainsString("(meta->>'user_id')", $sql);
        $this->assertStringContainsString('ts DESC, id DESC', $sql);
        $this->assertStringContainsString("jsonb_exists(meta, 'route_snapshot')", $sql);
    }

    public function test_history_feature_never_builds_route_geometry_from_doors_geom(): void
    {
        $routing = file_get_contents(app_path('Http/Controllers/Api/RoutingController.php'));
        $middleware = file_get_contents(app_path('Http/Middleware/CaptureRouteHistory.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/RouteHistoryController.php'));
        $combined = $routing . "\n" . $middleware . "\n" . $controller;

        $this->assertStringNotContainsString('doors.geom', $combined);
        $this->assertStringNotContainsString('ST_MakeLine', $middleware . "\n" . $controller);
        $this->assertStringNotContainsString('ST_StartPoint', $middleware . "\n" . $controller);
        $this->assertStringNotContainsString('ST_EndPoint', $middleware . "\n" . $controller);
    }
}
