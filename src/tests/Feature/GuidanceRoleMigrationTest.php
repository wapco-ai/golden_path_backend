<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Services\JwtService;
use Tests\TestCase;

/** Exercise the same data migration on isolated SQLite and PostgreSQL. */
class GuidanceRoleMigrationTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') === 'sqlite') {
            Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); });
            foreach (['admin_roles', 'admin_permissions'] as $table) {
                Schema::create($table, function (Blueprint $t) { $t->id(); $t->string('code')->unique(); $t->string('title'); });
            }
            Schema::create('admin_user_roles', function (Blueprint $t) {
                $t->foreignId('user_id')->constrained('users');
                $t->foreignId('role_id')->constrained('admin_roles');
                $t->primary(['user_id', 'role_id']);
            });
            Schema::create('admin_role_permissions', function (Blueprint $t) {
                $t->foreignId('role_id')->constrained('admin_roles');
                $t->foreignId('permission_id')->constrained('admin_permissions');
                $t->primary(['role_id', 'permission_id']);
            });
        }
        DB::beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) DB::rollBack();
        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_01_120000_add_guidance_points_admin_role.php');
    }

    public function test_upgrade_preserves_only_existing_map_access_and_is_repeatable_and_reversible(): void
    {
        $map = DB::table('admin_roles')->insertGetId(['code'=>'manage_map', 'title'=>'مدیریت نقشه']);
        $dashboard = DB::table('admin_roles')->insertGetId(['code'=>'super_dashboard_admin', 'title'=>'ادمین کل داشبورد']);
        $mapUser = DB::table('users')->insertGetId(['name'=>'Map migration fixture']);
        $dashboardUser = DB::table('users')->insertGetId(['name'=>'Dashboard migration fixture']);
        DB::table('admin_user_roles')->insert([
            ['user_id'=>$mapUser, 'role_id'=>$map], ['user_id'=>$dashboardUser, 'role_id'=>$dashboard],
        ]);
        $migration = $this->migration();
        $migration->up();
        $migration->up();
        $role = DB::table('admin_roles')->where('code', 'manage_guidance_points')->sole();
        $permission = DB::table('admin_permissions')->where('code', 'manage_guidance_points')->sole();
        $this->assertSame('مدیریت نقاط راهنما', $role->title);
        $this->assertDatabaseCount('admin_user_roles', 3);
        $this->assertDatabaseHas('admin_user_roles', ['user_id'=>$mapUser, 'role_id'=>$role->id]);
        $this->assertDatabaseMissing('admin_user_roles', ['user_id'=>$dashboardUser, 'role_id'=>$role->id]);
        $this->assertDatabaseHas('admin_role_permissions', ['role_id'=>$role->id, 'permission_id'=>$permission->id]);
        $migration->down();
        $this->assertDatabaseMissing('admin_roles', ['code'=>'manage_guidance_points']);
        $this->assertDatabaseMissing('admin_permissions', ['code'=>'manage_guidance_points']);
        $this->assertDatabaseCount('admin_role_permissions', 0);
        $this->assertDatabaseCount('admin_user_roles', 2);
        $this->assertDatabaseHas('admin_user_roles', ['user_id'=>$mapUser, 'role_id'=>$map]);
        $migration->up();
        $this->assertDatabaseCount('admin_user_roles', 3);
    }

    public function test_single_guidance_role_allows_the_whole_form_without_map_access_on_postgres(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Full spatial HTTP flow requires isolated PostgreSQL/PostGIS.');
        }
        config(['app.jwt_secret'=>'guidance-form-isolated-test-secret']);
        Storage::fake('public');
        $this->migration()->up();
        $user = DB::table('users')->insertGetId(['name'=>'Guidance fixture', 'is_admin'=>true]);
        $role = DB::table('admin_roles')->where('code', 'manage_guidance_points')->value('id');
        DB::table('admin_user_roles')->insert(['user_id'=>$user, 'role_id'=>$role]);
        $headers = ['Authorization'=>'Bearer '.app(JwtService::class)->createAccessToken(['sub'=>$user, 'role'=>'ADMIN'])];
        $endpoint = '/api/v1/admin/guidance-points';
        $created = $this->postJson($endpoint, ['floor'=>0, 'x'=>59.612, 'y'=>36.286, 'title'=>'Guidance fixture'], $headers)
            ->assertCreated()->assertJsonPath('data.title', 'Guidance fixture');
        $id = $created->json('id');
        $this->getJson($endpoint.'/'.$id, $headers)->assertOk();
        $this->getJson($endpoint, $headers)->assertOk();
        $this->patchJson($endpoint.'/'.$id, ['title'=>'Updated guidance'], $headers)->assertOk()->assertJsonPath('data.title', 'Updated guidance');
        $this->postJson('/api/v1/doors', [], $headers)->assertForbidden();
        $this->deleteJson($endpoint.'/'.$id, [], $headers)->assertOk();
        $this->getJson($endpoint.'/'.$id, $headers)->assertNotFound();
        DB::table('admin_user_roles')->where('user_id', $user)->delete();
        $this->getJson($endpoint, $headers)->assertForbidden();
        $this->postJson($endpoint, [], $headers)->assertForbidden();
    }
}
