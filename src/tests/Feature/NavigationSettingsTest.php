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
            $t->id(); $t->boolean('is_admin')->default(true); $t->boolean('is_active')->default(true);
            $t->string('status')->default('active'); $t->timestamp('locked_until')->nullable();
        });
        Schema::create('admin_roles', function (Blueprint $t) { $t->id(); $t->string('title'); });
        Schema::create('admin_user_roles', function (Blueprint $t) { $t->integer('user_id'); $t->integer('role_id'); });
        DB::table('users')->insert([['id' => 1], ['id' => 2]]);
        DB::table('admin_roles')->insert([['id' => 1, 'title' => AdminAccess::SUPER_TITLE], ['id' => 2, 'title' => 'مدیریت نقشه']]);
        DB::table('admin_user_roles')->insert([['user_id' => 1, 'role_id' => 1], ['user_id' => 2, 'role_id' => 2]]);
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
        $this->putJson('/api/v1/admin/navigation-settings', $this->payload(), $this->auth(2))->assertForbidden();
        $this->getJson('/api/v1/admin/navigation-settings', $this->auth(2))->assertForbidden();
        $this->getJson('/api/v1/admin/navigation-settings', $this->auth())->assertOk();
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
