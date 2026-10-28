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
            $t->timestamp('locked_until')->nullable(); $t->timestamps();
        });
        Schema::create('admin_roles', function (Blueprint $t) { $t->id(); $t->string('code'); $t->string('title'); });
        Schema::create('admin_user_roles', function (Blueprint $t) { $t->integer('user_id'); $t->integer('role_id'); });
        Schema::create('admin_auth', function (Blueprint $t) { $t->integer('user_id'); $t->string('password_hash'); $t->boolean('mfa_enabled')->default(false); $t->string('mfa_secret')->nullable(); $t->timestamp('last_password_change_at')->nullable(); $t->timestamps(); });
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
        $payload = ['firstName'=>'Test', 'lastName'=>'Person', 'username'=>'newadmin', 'roles'=>['ادمین کل داشبورد']];
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
