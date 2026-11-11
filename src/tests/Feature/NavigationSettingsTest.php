<?php

namespace Tests\Feature;

use App\Services\Admin\AdminAccess;
use App\Services\JwtService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NavigationSettingsTest extends TestCase
{
    private array $policy;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'sqlite') $this->markTestSkipped('Isolated SQLite fixture only');
        config(['app.jwt_secret' => 'navigation-policy-test-secret']);
        $sql = file_get_contents(database_path('sql/20261111_120000_navigation_policy.up.sql'));
        preg_match("/DEFAULT '(.*?)'::jsonb/s", $sql, $match);
        $this->policy = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        Schema::create('routing_timing_settings', function (Blueprint $t) {
            $t->boolean('singleton')->primary(); $t->double('walk_speed_mps');
            $t->json('navigation_policy'); $t->integer('navigation_version'); $t->timestamp('navigation_updated_at');
        });
        DB::table('routing_timing_settings')->insert(['singleton' => true, 'walk_speed_mps' => 1.2,
            'navigation_policy' => json_encode($this->policy), 'navigation_version' => 1, 'navigation_updated_at' => now()]);
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('email')->unique(); $t->boolean('is_admin')->default(true); $t->boolean('is_active')->default(true);
            $t->string('status')->default('active'); $t->timestamp('locked_until')->nullable();
        });
        Schema::create('admin_roles', function (Blueprint $t) { $t->id(); $t->string('title'); $t->string('code'); });
        Schema::create('admin_user_roles', function (Blueprint $t) { $t->integer('user_id'); $t->integer('role_id'); });
        Schema::create('admin_permissions', function (Blueprint $t) { $t->id(); $t->string('code'); });
        Schema::create('admin_role_permissions', function (Blueprint $t) { $t->integer('role_id'); $t->integer('permission_id'); });
        DB::table('users')->insert([
            ['id' => 1, 'email' => 'oskooei@gmail.com'],
            ['id' => 2, 'email' => 'map@example.test'],
            ['id' => 3, 'email' => 'other-dashboard@example.test'],
        ]);
        DB::table('admin_roles')->insert([
            ['id' => 1, 'title' => AdminAccess::SUPER_TITLE, 'code' => 'super_dashboard_admin'],
            ['id' => 2, 'title' => 'مدیریت نقشه', 'code' => 'manage_map'],
        ]);
        DB::table('admin_user_roles')->insert([
            ['user_id' => 1, 'role_id' => 1], ['user_id' => 2, 'role_id' => 2], ['user_id' => 3, 'role_id' => 1],
        ]);
    }

    private function auth(int $id = 1): array
    {
        return ['Authorization' => 'Bearer '.app(JwtService::class)->createAccessToken(['sub' => $id, 'role' => 'ADMIN'])];
    }

    private function payload(array $changes = []): array
    {
        return ['version' => 1, 'walk_speed_mps' => 1.2, 'policy' => array_replace($this->policy, $changes)];
    }

    public function test_public_policy_defaults_to_no_gps_and_twenty_meter_warning(): void
    {
        $this->getJson('/api/v1/navigation-settings')->assertOk()->assertJsonPath('policy.gps_enabled', false)
            ->assertJsonPath('policy.warning_uncertainty_m', 20)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_only_authorized_administrators_can_read_admin_settings_and_write(): void
    {
        $this->putJson('/api/v1/admin/navigation-settings', $this->payload())->assertUnauthorized();
        foreach ([2, 3] as $deniedId) {
            $this->putJson('/api/v1/admin/navigation-settings', $this->payload(), $this->auth($deniedId))->assertForbidden();
            $this->getJson('/api/v1/admin/navigation-settings', $this->auth($deniedId))->assertForbidden();
            $this->getJson('/api/v1/admin/auth/me', $this->auth($deniedId))->assertOk()
                ->assertJsonPath('canManageNavigationSettings', false);
        }
        $this->getJson('/api/v1/admin/navigation-settings', $this->auth())->assertOk();
        $this->getJson('/api/v1/admin/auth/me', $this->auth())->assertOk()
            ->assertJsonPath('canManageNavigationSettings', true);

        DB::table('admin_user_roles')->where('user_id', 1)->update(['role_id' => 2]);
        $this->putJson('/api/v1/admin/navigation-settings', $this->payload(), $this->auth())->assertForbidden();
        $this->getJson('/api/v1/admin/auth/me', $this->auth())->assertOk()
            ->assertJsonPath('canManageNavigationSettings', false);
        $this->assertSame(1, DB::table('routing_timing_settings')->value('navigation_version'));
    }

    public function test_save_is_persistent_and_stale_editor_cannot_overwrite_it(): void
    {
        $payload = $this->payload(['warning_uncertainty_m' => 25]);
        $payload['walk_speed_mps'] = 0.9;
        $this->putJson('/api/v1/admin/navigation-settings', $payload, $this->auth())->assertOk()->assertJsonPath('version', 2);
        $this->getJson('/api/v1/navigation-settings')->assertJsonPath('policy.warning_uncertainty_m', 25)
            ->assertJsonPath('walk_speed_mps', 0.9);
        $this->putJson('/api/v1/admin/navigation-settings', $this->payload(), $this->auth())->assertConflict();
        $this->getJson('/api/v1/navigation-settings')->assertJsonPath('policy.warning_uncertainty_m', 25);
    }

    public function test_invalid_and_inconsistent_thresholds_are_rejected_without_partial_update(): void
    {
        foreach ([['warning_uncertainty_m' => -1], ['arrival_radius_m' => 20], ['step_length_m' => 4],
            ['arrival_samples' => 1], ['gps_enabled' => 'yes'], ['drift_per_meter' => 'NaN'],
            ['snap_distance_m' => 25], ['gps_arrival_accuracy_m' => 5, 'gps_progress_accuracy_m' => 3],
            ['unknown_key' => 123]] as $changes) {
            $this->putJson('/api/v1/admin/navigation-settings', $this->payload($changes), $this->auth())->assertUnprocessable();
        }
        $this->assertSame(1, DB::table('routing_timing_settings')->value('navigation_version'));
        $this->assertEquals($this->policy, json_decode(DB::table('routing_timing_settings')->value('navigation_policy'), true));
    }
}
