<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\AdminSectionAccess;
use App\Services\JwtService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => 'isolated-admin-access-test-secret']);
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('username')->nullable();
            $t->string('email')->nullable(); $t->boolean('is_admin')->default(true);
            $t->boolean('is_active')->default(true); $t->string('status')->default('active');
            $t->string('password')->nullable();
            $t->integer('failed_login_attempts')->default(0);
            $t->timestamp('last_login_at')->nullable(); $t->string('last_login_ip')->nullable();
            $t->timestamp('locked_until')->nullable(); $t->timestamps();
        });
        Schema::create('admin_roles', function (Blueprint $t) { $t->id(); $t->string('code'); $t->string('title'); });
        Schema::create('admin_user_roles', function (Blueprint $t) { $t->integer('user_id'); $t->integer('role_id'); });
        Schema::create('admin_auth', function (Blueprint $t) { $t->integer('user_id'); $t->string('password_hash'); $t->boolean('mfa_enabled')->default(false); $t->string('mfa_secret')->nullable(); $t->timestamp('last_password_change_at')->nullable(); $t->timestamps(); });
        Schema::create('admin_login_logs', function (Blueprint $t) {
            $t->id(); $t->integer('user_id')->nullable(); $t->string('identifier');
            $t->string('ip')->nullable(); $t->text('user_agent')->nullable();
            $t->boolean('success'); $t->string('failure_reason')->nullable();
        });
        Schema::create('admin_refresh_tokens', function (Blueprint $t) {
            $t->id(); $t->integer('user_id'); $t->string('token');
            $t->string('ip')->nullable(); $t->text('user_agent')->nullable();
            $t->timestamp('expires_at'); $t->timestamp('revoked_at')->nullable();
        });
        Schema::create('admin_permissions', function (Blueprint $t) { $t->id(); $t->string('code'); });
        Schema::create('admin_role_permissions', function (Blueprint $t) { $t->integer('role_id'); $t->integer('permission_id'); });
        DB::table('admin_roles')->insert([
            ['id'=>1, 'code'=>'manager', 'title'=>'مدیریت ادمین ها'],
            ['id'=>2, 'code'=>'map', 'title'=>'مدیریت نقشه'],
            ['id'=>3, 'code'=>'super_dashboard_admin', 'title'=>'ادمین کل داشبورد'],
            ['id'=>4, 'code'=>'cultural', 'title'=>'مدیریت اطلاعات فرهنگی'],
        ]);
        foreach ([1, 2, 3, 4] as $id) {
            DB::table('users')->insert(['id'=>$id, 'name'=>'Test Admin', 'username'=>'test'.$id, 'created_at'=>now(), 'updated_at'=>now()]);
            DB::table('admin_user_roles')->insert(['user_id'=>$id, 'role_id'=>$id]);
        }
        Storage::fake('public');
    }

    private function token(int $id): array
    {
        return ['Authorization' => 'Bearer '.app(JwtService::class)->createAccessToken(['sub'=>$id, 'role'=>'ADMIN'])];
    }

    public function test_admin_routes_require_their_section_and_super_admin_can_enter(): void
    {
        $this->getJson('/api/v1/admin/admins', $this->token(2))->assertForbidden();
        $this->getJson('/api/v1/admin/admins', $this->token(1))->assertOk();
        $this->getJson('/api/v1/admin/admins', $this->token(3))->assertOk();
        $this->getJson('/api/v1/admin/admins')->assertUnauthorized();
    }

    public function test_manager_cannot_grant_or_edit_outside_their_authority(): void
    {
        $payload = ['firstName'=>'Test', 'lastName'=>'Person', 'username'=>'newadmin', 'email'=>'new@example.test', 'password'=>'valid-pass-123', 'roles'=>['ادمین کل داشبورد']];
        $this->postJson('/api/v1/admin/admins', $payload, $this->token(1))->assertForbidden();
        $this->patchJson('/api/v1/admin/admins/3', ['roles'=>['مدیریت ادمین ها']], $this->token(1))->assertForbidden();
        $this->deleteJson('/api/v1/admin/admins/3', [], $this->token(1))->assertForbidden();
        $this->assertDatabaseHas('admin_user_roles', ['user_id'=>3, 'role_id'=>3]);
    }

    public function test_super_admin_can_create_account_with_usable_credentials_and_role(): void
    {
        $payload = [
            'firstName'=>'New', 'lastName'=>'Admin', 'username'=>'newadmin',
            'email'=>'newadmin@example.test', 'password'=>'valid-pass-123',
            'roles'=>['مدیریت نقشه'],
        ];
        $response = $this->postJson('/api/v1/admin/admins', $payload, $this->token(3))->assertCreated();
        $id = $response->json('id');
        $this->assertDatabaseHas('users', ['id'=>$id, 'username'=>'newadmin', 'email'=>'newadmin@example.test']);
        $this->assertDatabaseHas('admin_user_roles', ['user_id'=>$id, 'role_id'=>2]);
        $hash = DB::table('admin_auth')->where('user_id', $id)->value('password_hash');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('valid-pass-123', $hash));
        $response->assertJsonPath('email', $payload['email'])->assertJsonPath('requiresPasswordReset', false);
        foreach ([$payload['username'], $payload['email']] as $identifier) {
            $this->postJson('/api/v1/admin/auth/login', [
                'usernameOrEmail'=>$identifier, 'password'=>$payload['password'],
            ])->assertOk()->assertJsonPath('user.email', $payload['email'])->assertJsonStructure(['accessToken', 'refreshToken']);
        }
        $this->getJson('/api/v1/admin/admins/'.$id, $this->token(3))->assertOk()->assertJsonPath('email', $payload['email']);
    }

    public function test_creation_rejects_missing_or_empty_credentials(): void
    {
        $payload = ['firstName'=>'New', 'lastName'=>'Admin', 'username'=>'newadmin', 'roles'=>['مدیریت نقشه']];
        foreach ([[], ['email'=>null, 'password'=>null], ['email'=>'', 'password'=>'']] as $credentials) {
            $this->postJson('/api/v1/admin/admins', $payload + $credentials, $this->token(3))
                ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        }
        $this->assertDatabaseMissing('users', ['username'=>'newadmin']);
    }

    public function test_suspension_and_reactivation_keep_credentials_and_roles(): void
    {
        $id = $this->createAccount();
        $login = $this->postJson('/api/v1/admin/auth/login', ['usernameOrEmail'=>'newadmin', 'password'=>'valid-pass-123'])->assertOk();
        $this->patchJson('/api/v1/admin/admins/'.$id, ['status'=>'inactive'], $this->token(3))
            ->assertOk()->assertJsonPath('status', 'inactive');
        $this->assertDatabaseHas('admin_auth', ['user_id'=>$id]);
        $this->assertDatabaseHas('admin_user_roles', ['user_id'=>$id, 'role_id'=>2]);
        $this->getJson('/api/v1/admin/auth/me', ['Authorization'=>'Bearer '.$login->json('accessToken')])->assertStatus(423);
        $this->postJson('/api/v1/admin/auth/login', ['usernameOrEmail'=>'newadmin', 'password'=>'valid-pass-123'])->assertStatus(423);
        $this->postJson('/api/v1/admin/auth/refresh', ['refreshToken'=>$login->json('refreshToken')])->assertUnauthorized();
        $this->patchJson('/api/v1/admin/admins/'.$id, ['status'=>'active'], $this->token(3))
            ->assertOk()->assertJsonPath('status', 'active');
        $this->postJson('/api/v1/admin/auth/login', ['usernameOrEmail'=>'newadmin', 'password'=>'valid-pass-123'])->assertOk();
    }

    public function test_legacy_deleted_admin_requires_password_and_roles_before_reactivation(): void
    {
        $id = $this->createAccount();
        $this->deleteJson('/api/v1/admin/admins/'.$id, [], $this->token(3))->assertOk();
        $this->getJson('/api/v1/admin/admins/'.$id, $this->token(3))
            ->assertOk()->assertJsonPath('status', 'inactive')->assertJsonPath('requiresPasswordReset', true);
        $this->patchJson('/api/v1/admin/admins/'.$id, ['status'=>'active'], $this->token(3))
            ->assertUnprocessable()->assertJsonValidationErrors(['password', 'roles']);
        $this->assertDatabaseHas('users', ['id'=>$id, 'status'=>'disabled', 'is_active'=>false]);
        $this->patchJson('/api/v1/admin/admins/'.$id, [
            'status'=>'active', 'roles'=>['مدیریت نقشه'], 'password'=>'recovered-pass-123',
        ], $this->token(3))->assertOk()->assertJsonPath('requiresPasswordReset', false);
        $this->postJson('/api/v1/admin/auth/login', ['usernameOrEmail'=>'newadmin', 'password'=>'recovered-pass-123'])->assertOk();
    }

    public function test_status_updates_are_validated_and_cannot_bypass_section_or_role_limits(): void
    {
        $this->patchJson('/api/v1/admin/admins/2', ['status'=>'inactive'], $this->token(2))->assertForbidden();
        $this->patchJson('/api/v1/admin/admins/3', ['status'=>'inactive'], $this->token(1))->assertForbidden();
        $this->patchJson('/api/v1/admin/admins/2', ['status'=>'unknown'], $this->token(3))->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id'=>2, 'status'=>'active']);
    }

    public function test_false_active_flag_blocks_login_even_if_status_is_active(): void
    {
        $id = $this->createAccount();
        DB::table('users')->where('id', $id)->update(['is_active'=>false]);
        $this->postJson('/api/v1/admin/auth/login', ['usernameOrEmail'=>'newadmin', 'password'=>'valid-pass-123'])->assertStatus(423);
    }

    private function createAccount(): int
    {
        return $this->postJson('/api/v1/admin/admins', [
            'firstName'=>'New', 'lastName'=>'Admin', 'username'=>'newadmin',
            'email'=>'newadmin@example.test', 'password'=>'valid-pass-123', 'roles'=>['مدیریت نقشه'],
        ], $this->token(3))->assertCreated()->json('id');
    }

    public function test_generic_file_mutations_cannot_bypass_section_access(): void
    {
        $this->deleteJson('/api/v1/files?path=uploads/contents/1/images/a.png', [], $this->token(2))->assertForbidden();
        $this->postJson('/api/v1/files', ['file'=>UploadedFile::fake()->create('a.txt', 1), 'entity_table'=>'contents', 'entity_id'=>1], $this->token(2))->assertForbidden();
        $this->postJson('/api/v1/files', ['file'=>UploadedFile::fake()->create('a.txt', 1), 'entity_table'=>'contents', 'entity_id'=>1], $this->token(4))->assertCreated();
    }

    public function test_cultural_role_can_read_categories_but_cannot_write_them(): void
    {
        $this->assertContains('مدیریت اطلاعات فرهنگی', AdminSectionAccess::titlesFor('api/v1/admin/categories', 'GET'));
        $this->assertNotContains('مدیریت اطلاعات فرهنگی', AdminSectionAccess::titlesFor('api/v1/admin/categories', 'POST'));
        $this->postJson('/api/v1/admin/categories', ['title'=>'Test'], $this->token(4))->assertForbidden();
    }

    public function test_every_admin_authenticated_route_has_section_enforcement(): void
    {
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (!in_array(AdminAuth::class, $middleware, true)) continue;
            $uri = $route->uri();
            if (str_contains($uri, 'admin/auth/') || $uri === 'api/v1/files') continue;
            $this->assertContains(AdminSectionAccess::class, $middleware, $uri);
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') continue;
                $this->assertNotNull(AdminSectionAccess::titlesFor($uri, $method), $method.' '.$uri);
            }
        }
    }
}
