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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
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
            $t->string('avatar_url')->nullable();
            $t->integer('failed_login_attempts')->default(0);
            $t->timestamp('last_login_at')->nullable(); $t->string('last_login_ip')->nullable();
            $t->timestamp('locked_until')->nullable(); $t->timestamps();
        });
        Schema::create('admin_roles', function (Blueprint $t) { $t->id(); $t->string('code'); $t->string('title'); });
        Schema::create('admin_user_roles', function (Blueprint $t) { $t->integer('user_id'); $t->integer('role_id'); });
        Schema::create('admin_permissions', function (Blueprint $t) { $t->id(); $t->string('code'); $t->string('title')->nullable(); });
        Schema::create('admin_role_permissions', function (Blueprint $t) { $t->integer('role_id'); $t->integer('permission_id'); });
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
            ['id'=>13, 'code'=>'manage_guidance_points', 'title'=>'مدیریت نقاط راهنما'],
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

    private function passwordFixture(bool $withAdminAuth = true): array
    {
        $old = 'fixture-old-password';
        $hash = \Illuminate\Support\Facades\Hash::make($old);
        DB::table('users')->where('id', 1)->update(['password' => $hash]);
        if ($withAdminAuth) {
            DB::table('admin_auth')->insert(['user_id' => 1, 'password_hash' => $hash,
                'mfa_enabled' => true, 'mfa_secret' => 'fixture-mfa', 'created_at' => now(), 'updated_at' => now()]);
        }
        return $this->postJson('/api/v1/admin/auth/login', ['usernameOrEmail' => 'test1', 'password' => $old])->assertOk()->json();
    }

    public function test_activity_log_report_enforces_role_and_pages_across_daily_files(): void
    {
        $base = sys_get_temp_dir().'/activity-report-'.Str::uuid();
        config(['logging.channels.activity.path' => $base.'.log']);
        Log::channel('activity')->getLogger()->setHandlers([new TestHandler]);
        $this->beforeApplicationDestroyed(function () use ($base): void {
            foreach (glob($base.'-*.log') as $file) {
                unlink($file);
            }
        });

        $makeRow = static fn ($time, $method, $status, $entity) => json_encode([
            'message' => 'api.request',
            'datetime' => $time,
            'context' => [
                'request_id' => (string) Str::uuid(),
                'actor_id' => 4,
                'actor_type' => 'admin',
                'method' => $method,
                'route' => 'api/v1/cultural-items/{id}',
                'entity_id' => $entity,
                'status' => $status,
                'deleted_rows' => $method === 'DELETE' ? 1 : null,
                'client_ip' => '192.0.2.1',
            ],
        ], JSON_THROW_ON_ERROR)."\n";

        file_put_contents($base.'-2026-11-10.log', $makeRow('2026-11-10T12:44:59.900000+00:00', 'DELETE', 200, '890'));
        $rows = '';
        for ($i = 1; $i <= 51; $i++) {
            $rows .= $makeRow('2026-11-11T11:00:00+00:00', 'GET', 200, (string) $i);
        }
        file_put_contents($base.'-2026-11-11.log', $rows.'partial-invalid-line');

        $path = '/api/v1/admin/activity-logs';
        $auth = fn ($id) => ['Authorization' => 'Bearer '.app(JwtService::class)->createAccessToken([
            'sub' => $id, 'role' => 'ADMIN',
        ])];
        $this->getJson($path)->assertUnauthorized();
        $this->getJson($path, $auth(3))->assertForbidden();
        DB::table('admin_user_roles')->insert(['user_id' => 2, 'role_id' => 8]);
        $headers = $auth(2);

        $first = $this->getJson($path, $headers)->assertOk()->assertJsonCount(50, 'items')
            ->assertJsonPath('items.0.entityId', '51')->assertJsonPath('items.49.entityId', '2');
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('192.0.2.1', $first->getContent());
        $cursor = $first->json('nextCursor');
        $this->getJson($path.'?'.http_build_query(['cursor' => $cursor]), $headers)
            ->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('items.0.entityId', '1')
            ->assertJsonPath('items.1.entityId', '890')->assertJsonPath('nextCursor', null);
        $this->getJson($path.'?'.http_build_query([
            'actorId' => '4', 'entityId' => '890', 'operation' => 'delete', 'result' => 'success',
            'from' => '2026-11-10T16:14:00+03:30', 'to' => '2026-11-10T16:14:59+03:30',
        ]), $headers)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.actorName', 'Test Admin')
            ->assertJsonPath('items.0.deletedRows', 1);
        $this->getJson($path.'?cursor=../../.env', $headers)->assertUnprocessable();
    }

    public function test_activity_log_identifies_authentication_and_cultural_deletion(): void
    {
        $handler = new TestHandler;
        Log::channel('activity')->getLogger()->setHandlers([$handler]);

        $login = $this->passwordFixture();
        $this->postJson('/api/v1/admin/auth/login', [
            'usernameOrEmail' => 'test1',
            'password' => 'incorrect-password',
        ])->assertUnauthorized();
        $session = $this->postJson('/api/v1/admin/auth/refresh', [
            'refreshToken' => $login['refreshToken'],
        ])->assertOk()->json();
        $this->postJson('/api/v1/admin/auth/logout', [
            'refreshToken' => $session['refreshToken'],
        ], ['Authorization' => 'Bearer '.$session['accessToken']])->assertOk();

        Schema::create('poi_points', fn (Blueprint $table) => $table->id());
        Schema::create('contents', fn (Blueprint $table) => $table->integer('poi_id'));
        foreach (['i18n_texts', 'access_time_restrictions', 'access_prayer_restrictions'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->string('entity_table');
                $table->integer('entity_id');
            });
        }
        DB::table('poi_points')->insert(['id' => 890]);
        DB::table('contents')->insert(['poi_id' => 890]);
        $this->deleteJson('/api/v1/cultural-items/890', [], [
            'Authorization' => 'Bearer '.$session['accessToken'],
        ])->assertForbidden();
        $token = app(JwtService::class)->createAccessToken(['sub' => 4, 'role' => 'ADMIN']);
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->deleteJson('/api/v1/cultural-items/890?secret=omit-this-query', [], $headers)->assertOk();
        $this->deleteJson('/api/v1/cultural-items/890', [], $headers)->assertOk();

        [$loginEvent, $failedLogin, $refresh, $logout, $denied, $deleted, $retry] = $handler->getRecords();
        $this->assertSame('auth.login', $loginEvent->message);
        $this->assertSame(1, $loginEvent->context['actor_id']);
        $this->assertNull($failedLogin->context['actor_id']);
        $this->assertSame(401, $failedLogin->context['status']);
        $this->assertSame('auth.refresh', $refresh->message);
        $this->assertSame(1, $refresh->context['actor_id']);
        $this->assertSame('auth.logout', $logout->message);
        $this->assertTrue($logout->context['logout_token_matched']);
        $this->assertSame(1, $denied->context['actor_id']);
        $this->assertSame(403, $denied->context['status']);
        $this->assertSame('890', $denied->context['entity_id']);
        $this->assertNull($denied->context['deleted_rows']);
        $this->assertSame(4, $deleted->context['actor_id']);
        $this->assertSame('admin', $deleted->context['actor_type']);
        $this->assertSame('DELETE', $deleted->context['method']);
        $this->assertSame('api/v1/cultural-items/{id}', $deleted->context['route']);
        $this->assertSame('890', $deleted->context['entity_id']);
        $this->assertSame(1, $deleted->context['deleted_rows']);
        $this->assertSame(0, $retry->context['deleted_rows']);
        $this->assertDatabaseMissing('contents', ['poi_id' => 890]);

        $logged = json_encode($handler->getRecords(), JSON_THROW_ON_ERROR);
        foreach ([$token, $login['refreshToken'], 'incorrect-password', 'omit-this-query'] as $secret) {
            $this->assertStringNotContainsString($secret, $logged);
        }
    }

    public function test_password_change_success_revokes_refresh_and_keeps_existing_access_until_expiry(): void
    {
        $login = $this->passwordFixture();
        $headers = ['Authorization' => 'Bearer '.$login['accessToken']];
        $response = $this->postJson('/api/v1/admin/auth/change-password', [
            'current_password' => 'fixture-old-password', 'password' => 'fixture-new-password',
            'password_confirmation' => 'fixture-new-password',
        ], $headers)->assertOk()->assertExactJson(['message' => 'PASSWORD_CHANGED']);
        $auth = DB::table('admin_auth')->where('user_id', 1)->first();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('fixture-new-password', $auth->password_hash));
        $this->assertSame($auth->password_hash, DB::table('users')->where('id', 1)->value('password'));
        $this->assertNotNull($auth->last_password_change_at);
        $this->assertTrue((bool) $auth->mfa_enabled);
        $this->assertSame('fixture-mfa', $auth->mfa_secret);
        $this->assertDatabaseHas('admin_user_roles', ['user_id' => 1, 'role_id' => 1]);
        $this->assertDatabaseHas('users', ['id' => 1, 'status' => 'active', 'is_active' => true]);
        $this->postJson('/api/v1/admin/auth/refresh', ['refreshToken' => $login['refreshToken']])->assertUnauthorized();
        $this->getJson('/api/v1/admin/auth/me', $headers)->assertOk();
        $this->postJson('/api/v1/admin/auth/login', ['usernameOrEmail' => 'test1', 'password' => 'fixture-old-password'])->assertUnauthorized();
        $this->postJson('/api/v1/admin/auth/login', ['usernameOrEmail' => 'test1', 'password' => 'fixture-new-password'])->assertOk();
    }

    public function test_password_change_rejects_invalid_requests_and_other_account_target(): void
    {
        $login = $this->passwordFixture();
        $headers = ['Authorization' => 'Bearer '.$login['accessToken']];
        $hash = DB::table('admin_auth')->where('user_id', 1)->value('password_hash');
        $valid = ['current_password' => 'fixture-old-password', 'password' => 'fixture-new-password', 'password_confirmation' => 'fixture-new-password'];
        $this->postJson('/api/v1/admin/auth/change-password', $valid)->assertUnauthorized();
        $this->postJson('/api/v1/admin/auth/change-password', array_merge($valid, ['current_password' => 'wrong-password']), $headers)->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson('/api/v1/admin/auth/change-password', array_merge($valid, ['password_confirmation' => 'mismatch']), $headers)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/admin/auth/change-password', array_merge($valid, ['user_id' => 2]), $headers)->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertSame($hash, DB::table('admin_auth')->where('user_id', 1)->value('password_hash'));
        $this->assertSame($hash, DB::table('users')->where('id', 1)->value('password'));
        $this->assertNull(DB::table('users')->where('id', 2)->value('password'));
        $this->assertNull(DB::table('admin_refresh_tokens')->where('token', $login['refreshToken'])->value('revoked_at'));
    }

    public function test_password_change_uses_login_fallback_and_primary_takes_precedence(): void
    {
        $login = $this->passwordFixture(false);
        $headers = ['Authorization' => 'Bearer '.$login['accessToken']];
        $valid = ['current_password' => 'fixture-old-password', 'password' => 'fixture-new-password', 'password_confirmation' => 'fixture-new-password'];
        $this->postJson('/api/v1/admin/auth/change-password', $valid, $headers)->assertOk();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('fixture-new-password', DB::table('admin_auth')->where('user_id', 1)->value('password_hash')));
        DB::table('users')->where('id', 1)->update(['password' => \Illuminate\Support\Facades\Hash::make('fallback-only')]);
        $this->postJson('/api/v1/admin/auth/change-password', array_merge($valid, ['current_password' => 'fallback-only']), $headers)->assertUnprocessable();
    }

    public function test_password_change_rolls_back_all_writes_if_storage_fails(): void
    {
        $login = $this->passwordFixture();
        $headers = ['Authorization' => 'Bearer '.$login['accessToken']];
        $hash = DB::table('admin_auth')->where('user_id', 1)->value('password_hash');
        DB::statement("CREATE TRIGGER reject_password_update BEFORE UPDATE OF password ON users BEGIN SELECT RAISE(FAIL, 'fixture failure'); END");
        $response = $this->postJson('/api/v1/admin/auth/change-password', [
            'current_password' => 'fixture-old-password', 'password' => 'fixture-new-password', 'password_confirmation' => 'fixture-new-password',
        ], $headers)->assertStatus(500)->assertJsonPath('error', 'PASSWORD_CHANGE_FAILED');
        $this->assertStringNotContainsString('fixture-new-password', $response->getContent());
        $this->assertStringNotContainsString($hash, $response->getContent());
        $this->assertSame($hash, DB::table('admin_auth')->where('user_id', 1)->value('password_hash'));
        $this->assertSame($hash, DB::table('users')->where('id', 1)->value('password'));
        $this->assertNull(DB::table('admin_auth')->where('user_id', 1)->value('last_password_change_at'));
        $this->assertNull(DB::table('admin_refresh_tokens')->where('token', $login['refreshToken'])->value('revoked_at'));
    }

    public function test_password_verification_limit_is_shared_by_account_across_tokens_and_ips(): void
    {
        $login = $this->passwordFixture();
        $headers = ['Authorization' => 'Bearer '.$login['accessToken']];
        $otherToken = ['Authorization' => 'Bearer '.app(JwtService::class)->createAccessToken([
            'sub' => 1, 'role' => 'ADMIN', 'jti' => 'another-session',
        ])];
        $valid = ['current_password' => 'fixture-old-password', 'password' => 'fixture-new-password',
            'password_confirmation' => 'fixture-new-password'];
        $hash = DB::table('admin_auth')->where('user_id', 1)->value('password_hash');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.($attempt + 1)])
                ->postJson('/api/v1/admin/auth/change-password',
                    array_merge($valid, ['current_password' => 'incorrect-password']),
                    $attempt % 2 ? $headers : $otherToken)
                ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        }
        $blocked = $this->postJson('/api/v1/admin/auth/change-password', $valid, $headers)
            ->assertStatus(429)->assertJsonPath('error', 'PASSWORD_CHANGE_RATE_LIMITED');
        $this->assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));
        $this->assertSame($hash, DB::table('users')->where('id', 1)->value('password'));
        $this->assertSame($hash, DB::table('admin_auth')->where('user_id', 1)->value('password_hash'));
        $this->assertNull(DB::table('admin_refresh_tokens')->where('token', $login['refreshToken'])->value('revoked_at'));

        // Another account using the same IP has its own budget.
        DB::table('users')->where('id', 2)->update(['password' => $hash]);
        $this->postJson('/api/v1/admin/auth/change-password', $valid, $this->token(2))->assertOk();

        $this->travel(61)->seconds();
        $this->postJson('/api/v1/admin/auth/change-password', $valid, $otherToken)->assertOk();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('fixture-new-password',
            DB::table('admin_auth')->where('user_id', 1)->value('password_hash')));
    }

    private function token(int $id): array
    {
        return ['Authorization' => 'Bearer '.app(JwtService::class)->createAccessToken(['sub'=>$id, 'role'=>'ADMIN'])];
    }

    private function avatarFile(): UploadedFile
    {
        return new UploadedFile(__DIR__.'/../Fixtures/cultural-media/tiny.jpg', 'avatar.jpg', 'image/jpeg', null, true);
    }

    private function avatarPath(string $url): string
    {
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        return $query['path'];
    }

    public function test_admin_avatar_is_saved_served_replaced_and_deleted_for_own_account(): void
    {
        // Map-only administrators can manage their own avatar without account-management grants.
        $headers = $this->token(2) + ['Accept' => 'application/json'];
        $first = $this->post('/api/v1/admin/auth/avatar', ['file' => $this->avatarFile()], $headers)->assertOk()->json('url');
        $path = $this->avatarPath($first);
        $this->assertStringStartsWith('uploads/admin_avatars/2/', $path);
        $this->assertTrue(Storage::disk('public')->exists($path));
        $this->getJson('/api/v1/admin/auth/me', $headers)->assertOk()->assertJsonPath('avatarUrl', $first);
        $this->assertNull(DB::table('users')->where('id', 1)->value('avatar_url'));
        $image = $this->get($first)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringStartsWith('inline;', $image->headers->get('Content-Disposition'));
        $this->assertSame(file_get_contents(__DIR__.'/../Fixtures/cultural-media/tiny.jpg'), $image->streamedContent());
        $download = $this->get(str_replace('&inline=1', '', $first))->assertOk();
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
        $this->deleteJson('/api/v1/files?'.http_build_query(['path' => $path]), [], $this->token(1))->assertStatus(400);
        $this->assertTrue(Storage::disk('public')->exists($path));

        $second = $this->post('/api/v1/admin/auth/avatar', ['file' => $this->avatarFile()], $headers)->assertOk()->json('url');
        $this->assertNotSame($first, $second);
        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->assertTrue(Storage::disk('public')->exists($this->avatarPath($second)));
        $this->post('/api/v1/admin/auth/avatar', ['file' => $this->avatarFile(), 'user_id' => 1], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->deleteJson('/api/v1/admin/auth/avatar', ['id' => 1], $headers)->assertUnprocessable();
        $this->assertSame($second, DB::table('users')->where('id', 2)->value('avatar_url'));

        $this->deleteJson('/api/v1/admin/auth/avatar', [], $headers)->assertOk();
        $this->assertFalse(Storage::disk('public')->exists($this->avatarPath($second)));
        $this->getJson('/api/v1/admin/auth/me', $headers)->assertOk()->assertJsonPath('avatarUrl', null);
        $this->deleteJson('/api/v1/admin/auth/avatar', [], $headers)->assertOk();
    }

    public function test_admin_avatar_is_returned_after_a_new_login(): void
    {
        $url = $this->post('/api/v1/admin/auth/avatar', ['file' => $this->avatarFile()],
            $this->token(1) + ['Accept' => 'application/json'])->assertOk()->json('url');
        $login = $this->passwordFixture();
        $this->assertSame($url, $login['user']['avatarUrl']);
        $this->getJson('/api/v1/admin/auth/me', ['Authorization' => 'Bearer '.$login['accessToken']])
            ->assertOk()->assertJsonPath('avatarUrl', $url);
    }

    public function test_file_download_never_renders_active_uploads_inline(): void
    {
        foreach ([
            'page.html' => '<!doctype html><script>alert(document.domain)</script>',
            'drawing.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'unknown.txt' => 'plain text fixture',
        ] as $name => $contents) {
            $path = 'uploads/contents/1/files/'.$name;
            Storage::disk('public')->put($path, $contents);
            $response = $this->get('/api/v1/files?'.http_build_query(['path' => $path, 'inline' => 1]))
                ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
            $this->assertSame($contents, $response->streamedContent());
        }
    }

    public function test_admin_avatar_rejects_missing_auth_invalid_images_and_oversized_files(): void
    {
        $this->post('/api/v1/admin/auth/avatar', ['file' => $this->avatarFile()], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->deleteJson('/api/v1/admin/auth/avatar')->assertUnauthorized();
        DB::table('users')->where('id', 3)->update(['is_admin' => false]);
        $this->post('/api/v1/admin/auth/avatar', ['file' => $this->avatarFile()],
            $this->token(3) + ['Accept' => 'application/json'])->assertUnauthorized();
        foreach ([
            UploadedFile::fake()->create('fake.png', 1, 'text/plain'),
            UploadedFile::fake()->createWithContent('avatar.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            UploadedFile::fake()->create('large.jpg', 5121, 'image/jpeg'),
        ] as $file) {
            $this->post('/api/v1/admin/auth/avatar', ['file' => $file], $this->token(1) + ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->assertNull(DB::table('users')->where('id', 1)->value('avatar_url'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_failed_avatar_writes_keep_the_previous_image_and_remove_uncommitted_files(): void
    {
        $headers = $this->token(1) + ['Accept' => 'application/json'];
        $url = $this->post('/api/v1/admin/auth/avatar', ['file' => $this->avatarFile()], $headers)->assertOk()->json('url');
        DB::statement("CREATE TRIGGER reject_avatar_update BEFORE UPDATE OF avatar_url ON users BEGIN SELECT RAISE(FAIL, 'fixture failure'); END");
        $this->post('/api/v1/admin/auth/avatar', ['file' => $this->avatarFile()], $headers)
            ->assertStatus(500)->assertJsonPath('error', 'AVATAR_UPLOAD_FAILED');
        $this->deleteJson('/api/v1/admin/auth/avatar', [], $headers)
            ->assertStatus(500)->assertJsonPath('error', 'AVATAR_DELETE_FAILED');
        $this->assertSame($url, DB::table('users')->where('id', 1)->value('avatar_url'));
        $this->assertSame([$this->avatarPath($url)], Storage::disk('public')->allFiles());
    }

    public function test_avatar_cleanup_cannot_delete_another_accounts_file_or_follow_parent_paths(): void
    {
        $otherPath = 'uploads/admin_avatars/2/other.jpg';
        Storage::disk('public')->put($otherPath, 'other account fixture');
        foreach ([$otherPath, 'uploads/admin_avatars/1/../2/other.jpg'] as $oldPath) {
            DB::table('users')->where('id', 1)->update(['avatar_url' => '/api/v1/files?'.http_build_query(['path' => $oldPath])]);
            $this->post('/api/v1/admin/auth/avatar', ['file' => $this->avatarFile()],
                $this->token(1) + ['Accept' => 'application/json'])->assertOk();
            $this->assertTrue(Storage::disk('public')->exists($otherPath));
        }
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
        $payload = ['firstName'=>'Test', 'lastName'=>'Person', 'username'=>'newadmin', 'email'=>'new@example.test', 'password'=>'valid-pass-123', 'roles'=>['ادمین کل داشبورد']];
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
        User::findOrFail(3)->adminRoles()->attach(1);
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
        User::findOrFail(3)->adminRoles()->attach([1, 2]);
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
        User::findOrFail(3)->adminRoles()->syncWithoutDetaching([1, 2]);
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

    public function test_unchecked_sections_are_revoked_with_the_same_token_while_dashboard_remains_selected(): void
    {
        $admin = User::findOrFail(3);
        $admin->adminRoles()->sync(range(1, 13));
        $headers = $this->token(3);
        $originalPermissions = $this->getJson('/api/v1/admin/auth/me', $headers)->assertOk()->json('permissions');
        $this->assertContains('manage_map', $originalPermissions);
        $this->assertContains('manage_categories', $originalPermissions);

        $selectedTitles = DB::table('admin_roles')->whereNotIn('id', [2, 5, 6, 13])->pluck('title')->all();
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

    public function test_guidance_form_is_independent_from_map_and_has_one_grant_for_all_operations(): void
    {
        foreach ([2, 3] as $id) {
            $this->getJson('/api/v1/admin/guidance-points', $this->token($id))->assertForbidden();
            $this->postJson('/api/v1/admin/guidance-points', [], $this->token($id))->assertForbidden();
            $this->putJson('/api/v1/admin/guidance-points/1', [], $this->token($id))->assertForbidden();
            $this->deleteJson('/api/v1/admin/guidance-points/1', [], $this->token($id))->assertForbidden();
        }
        User::findOrFail(4)->adminRoles()->sync([13]);
        $headers = $this->token(4);
        $this->postJson('/api/v1/admin/guidance-points', [], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/doors', [], $headers)->assertForbidden();
        $this->getJson('/api/v1/admin/van/nodes', $headers)->assertForbidden();
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->assertSame([AdminAccess::GUIDANCE_TITLE], AdminSectionAccess::titlesFor('api/v1/admin/guidance-points/1', $method));
        }
        User::findOrFail(4)->adminRoles()->detach(13);
        $this->postJson('/api/v1/admin/guidance-points', [], $headers)->assertForbidden();
    }

    public function test_guidance_role_can_be_delegated_and_returned_without_map_access(): void
    {
        User::findOrFail(1)->adminRoles()->attach(13);
        $response = $this->postJson('/api/v1/admin/admins', [
            'firstName'=>'Guidance', 'lastName'=>'Admin', 'username'=>'guidance-only',
            'email'=>'guidance@example.test', 'password'=>'valid-pass-123', 'roles'=>[AdminAccess::GUIDANCE_TITLE],
        ], $this->token(1))->assertCreated();
        $id = $response->json('id');
        $response->assertJsonPath('roles', [AdminAccess::GUIDANCE_TITLE]);
        $profile = $this->getJson('/api/v1/admin/auth/me', $this->token($id))->assertOk();
        $profile->assertJsonPath('roles', ['manage_guidance_points']);
        $this->postJson('/api/v1/admin/guidance-points', [], $this->token($id))->assertUnprocessable();
        $this->postJson('/api/v1/doors', [], $this->token($id))->assertForbidden();
        $this->patchJson('/api/v1/admin/admins/'.$id, ['roles'=>['مدیریت نقشه']], $this->token(1))->assertForbidden();
    }

    public function test_guidance_images_require_the_guidance_role_in_current_and_legacy_file_namespaces(): void
    {
        Schema::create('guidance_points', function (Blueprint $t) {
            $t->id(); $t->timestamp('deleted_at')->nullable(); $t->string('primary_image_url')->nullable(); $t->timestamps();
        });
        Schema::create('guidance_point_images', function (Blueprint $t) {
            $t->id(); $t->integer('point_id'); $t->string('image_url'); $t->string('image_key'); $t->integer('sort_order'); $t->timestamps();
        });
        DB::table('guidance_points')->insert(['id'=>1]);
        User::findOrFail(4)->adminRoles()->sync([13]);
        foreach (['guidance_points', 'poi_points'] as $namespace) {
            $payload = fn () => [
                'entity_table'=>$namespace, 'entity_id'=>1, 'bucket'=>'images',
                'file'=>new UploadedFile(__DIR__.'/../Fixtures/cultural-media/tiny.jpg', 'point.jpg', 'image/jpeg', null, true),
            ];
            $this->postJson('/api/v1/files', $payload(), $this->token(2))->assertForbidden();
            $uploaded = $this->postJson('/api/v1/files', $payload(), $this->token(4))->assertCreated();
            $path = $uploaded->json('path');
            $this->assertTrue(Storage::disk('public')->exists($path));
            if ($namespace === 'poi_points') {
                $uploaded->assertJsonPath('attached', true);
                $this->assertDatabaseHas('guidance_point_images', ['point_id'=>1, 'image_key'=>$path]);
                $this->assertDatabaseHas('guidance_points', ['id'=>1, 'primary_image_url'=>$uploaded->json('url')]);
            }
            // Full storage URLs follow the same normalized-path authorization.
            $inputPath = $namespace === 'poi_points' ? 'http://localhost/storage/'.$path : $path;
            $deleteUrl = '/api/v1/files?'.http_build_query(['path'=>$inputPath]);
            $this->deleteJson($deleteUrl, [], $this->token(2))->assertForbidden();
            User::findOrFail(4)->adminRoles()->detach(13);
            $this->deleteJson($deleteUrl, [], $this->token(4))->assertForbidden();
            $this->postJson('/api/v1/files', $payload(), $this->token(4))->assertForbidden();
            $this->assertTrue(Storage::disk('public')->exists($path));
            User::findOrFail(4)->adminRoles()->attach(13);
            $this->deleteJson($deleteUrl, [], $this->token(4))->assertOk()->assertJsonPath('file_deleted', true);
            $this->assertFalse(Storage::disk('public')->exists($path));
            $this->assertDatabaseMissing('guidance_point_images', ['image_key'=>$path]);
            $this->assertDatabaseHas('guidance_points', ['id'=>1, 'primary_image_url'=>null]);
        }
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
            'email'=>'padded@example.test', 'password'=>'valid-pass-123',
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
