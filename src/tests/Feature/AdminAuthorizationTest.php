<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\AdminCan;
use App\Http\Middleware\AdminSectionAccess;
use App\Models\User;
use App\Services\Admin\AdminAccess;
use App\Services\JwtService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
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
        Schema::create('admin_permissions', function (Blueprint $t) { $t->id(); $t->string('code'); });
        Schema::create('admin_role_permissions', function (Blueprint $t) { $t->integer('role_id'); $t->integer('permission_id'); });
        Schema::create('admin_auth', function (Blueprint $t) { $t->integer('user_id'); $t->string('password_hash'); $t->boolean('mfa_enabled')->default(false); $t->string('mfa_secret')->nullable(); $t->timestamp('last_password_change_at')->nullable(); $t->timestamps(); });
        DB::table('admin_roles')->insert([
            ['id'=>1, 'code'=>'manage_admins', 'title'=>'مدیریت ادمین ها'],
            ['id'=>2, 'code'=>'manage_map', 'title'=>'مدیریت نقشه'],
            ['id'=>3, 'code'=>'super_dashboard_admin', 'title'=>'ادمین کل داشبورد'],
            ['id'=>4, 'code'=>'manage_cultural_items', 'title'=>'مدیریت اطلاعات فرهنگی'],
            ['id'=>5, 'code'=>'manage_categories', 'title'=>'مدیریت دسته بندی'],
            ['id'=>6, 'code'=>'manage_users', 'title'=>'مدیریت کاربران'],
            ['id'=>7, 'code'=>'manage_registered_users', 'title'=>'مدیریت کاربران ثبت نام کرده'],
            ['id'=>8, 'code'=>'manage_reports', 'title'=>'مدیریت گزارشات'],
            ['id'=>9, 'code'=>'manage_pages', 'title'=>'مدیریت صفحات'],
            ['id'=>10, 'code'=>'manage_support_feedbacks', 'title'=>'مدیریت بازخورد ها'],
            ['id'=>11, 'code'=>'manage_comments', 'title'=>'مدیریت دیدگاه ها'],
            ['id'=>12, 'code'=>'manage_routing_logs', 'title'=>'مدیریت لاگ های مسیریابی'],
        ]);
        foreach (DB::table('admin_roles')->get() as $role) {
            DB::table('admin_permissions')->insert(['id'=>$role->id, 'code'=>$role->code]);
            DB::table('admin_role_permissions')->insert(['role_id'=>$role->id, 'permission_id'=>$role->id]);
            if ($role->id !== 3) {
                // Reproduce legacy seeds: dashboard implicitly owns every permission.
                DB::table('admin_role_permissions')->insert(['role_id'=>3, 'permission_id'=>$role->id]);
            }
        }
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

    public function test_admin_routes_require_their_explicit_section_even_for_dashboard_admin(): void
    {
        $this->getJson('/api/v1/admin/admins', $this->token(2))->assertForbidden();
        $this->getJson('/api/v1/admin/admins', $this->token(1))->assertOk();
        $this->getJson('/api/v1/admin/admins', $this->token(3))->assertForbidden();
        User::findOrFail(3)->adminRoles()->attach(1);
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
        User::findOrFail(3)->adminRoles()->attach([1, 2]);
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

    public function test_unchecked_sections_are_revoked_with_the_same_token_while_dashboard_remains_selected(): void
    {
        $admin = User::findOrFail(3);
        $admin->adminRoles()->sync(range(1, 12));
        $headers = $this->token(3);
        $originalPermissions = $this->getJson('/api/v1/admin/auth/me', $headers)->assertOk()->json('permissions');
        $this->assertContains('manage_map', $originalPermissions);
        $this->assertContains('manage_categories', $originalPermissions);

        $selectedTitles = DB::table('admin_roles')->whereNotIn('id', [2, 5, 6])->pluck('title')->all();
        $this->patchJson('/api/v1/admin/admins/3', ['roles'=>$selectedTitles], $headers)->assertOk();

        $profile = $this->getJson('/api/v1/admin/auth/me', $headers)->assertOk()->json();
        $this->assertContains('super_dashboard_admin', $profile['roles']);
        foreach (['manage_map', 'manage_categories', 'manage_users'] as $removed) {
            $this->assertNotContains($removed, $profile['roles']);
            $this->assertNotContains($removed, $profile['permissions']);
        }
        $this->assertContains('manage_registered_users', $profile['permissions']);

        $this->getJson('/api/v1/admin/guidance-points', $headers)->assertForbidden();
        $this->postJson('/api/v1/admin/guidance-points', [], $headers)->assertForbidden();
        $this->putJson('/api/v1/admin/guidance-points/1', [], $headers)->assertForbidden();
        $this->deleteJson('/api/v1/admin/guidance-points/1', [], $headers)->assertForbidden();
        $this->postJson('/api/v1/admin/categories', ['title'=>'Forbidden'], $headers)->assertForbidden();
        $this->putJson('/api/v1/admin/categories/1', ['title'=>'Forbidden'], $headers)->assertForbidden();
        $this->deleteJson('/api/v1/admin/categories/1', [], $headers)->assertForbidden();
        $this->postJson('/api/v1/files', ['file'=>UploadedFile::fake()->create('a.txt', 1), 'entity_table'=>'categories', 'entity_id'=>1], $headers)->assertForbidden();

        // The screenshot still selects the independent registered-user role.
        $this->getJson('/api/v1/admin/users', $headers)->assertOk();
        $this->getJson('/api/v1/admin/admins', $headers)->assertOk();
        $this->assertTrue(AdminAccess::hasAny($admin, AdminSectionAccess::titlesFor('api/v1/admin/dashboard/summary')));

        $this->patchJson('/api/v1/admin/admins/3', ['roles'=>array_values(array_diff($selectedTitles, ['مدیریت کاربران ثبت نام کرده']))], $headers)->assertOk();
        $this->getJson('/api/v1/admin/users', $headers)->assertForbidden();
        $this->patchJson('/api/v1/admin/users/4/status', ['status'=>'inactive'], $headers)->assertForbidden();
        $this->assertDatabaseHas('users', ['id'=>4, 'status'=>'active']);
    }

    public function test_dashboard_permission_grants_cannot_bypass_explicit_roles(): void
    {
        $admin = User::findOrFail(3);
        $request = Request::create('/permission-probe');
        $request->setUserResolver(fn () => $admin);
        $gate = new AdminCan();
        $next = fn () => response()->json(['allowed'=>true]);

        $this->assertSame(['super_dashboard_admin'], $admin->adminPermissions()->pluck('code')->all());
        $this->assertSame(403, $gate->handle($request, $next, 'manage_map')->getStatusCode());
        $this->assertSame(403, $gate->handle($request, $next, 'manage_categories')->getStatusCode());
        $this->assertSame(200, $gate->handle($request, $next, 'super_dashboard_admin')->getStatusCode());

        $admin->adminRoles()->attach(2);
        $this->assertSame(200, $gate->handle($request, $next, 'manage_map')->getStatusCode());
        $this->assertContains('manage_map', $admin->adminPermissions()->pluck('code')->all());
        $admin->adminRoles()->detach(2);
        $this->assertSame(403, $gate->handle($request, $next, 'manage_map')->getStatusCode());
        $this->assertNotContains('manage_map', $admin->adminPermissions()->pluck('code')->all());
    }

    public function test_dashboard_admin_cannot_restore_revoked_roles_or_grant_them_to_another_account(): void
    {
        User::findOrFail(3)->adminRoles()->attach(1);
        $headers = $this->token(3);
        $this->patchJson('/api/v1/admin/admins/3', ['roles'=>['ادمین کل داشبورد', 'مدیریت ادمین ها', 'مدیریت نقشه']], $headers)->assertForbidden();
        $this->postJson('/api/v1/admin/admins', [
            'firstName'=>'New', 'lastName'=>'Admin', 'username'=>'outside_scope',
            'email'=>'outside@example.test', 'password'=>'valid-pass-123', 'roles'=>['مدیریت نقشه'],
        ], $headers)->assertForbidden();
        $this->assertDatabaseMissing('admin_user_roles', ['user_id'=>3, 'role_id'=>2]);
        $this->assertDatabaseMissing('users', ['username'=>'outside_scope']);
    }

    public function test_whitespace_in_role_titles_cannot_bypass_grant_authority(): void
    {
        User::findOrFail(3)->adminRoles()->attach(1);
        $headers = $this->token(3);
        $this->patchJson('/api/v1/admin/admins/3', ['roles'=>['ادمین کل داشبورد', 'مدیریت ادمین ها', ' مدیریت نقشه ']], $headers)->assertForbidden();
        $this->postJson('/api/v1/admin/admins', [
            'firstName'=>'New', 'lastName'=>'Admin', 'username'=>'padded_scope',
            'roles'=>["\tمدیریت نقشه\n"],
        ], $headers)->assertForbidden();
        $this->assertDatabaseMissing('admin_user_roles', ['user_id'=>3, 'role_id'=>2]);
        $this->assertDatabaseMissing('users', ['username'=>'padded_scope']);
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
