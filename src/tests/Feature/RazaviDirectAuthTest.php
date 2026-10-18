<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PublicJwtService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real controllers and local JWTs; isolated SQLite schema and no outbound traffic. */
class RazaviDirectAuthTest extends TestCase
{
    private const BASE = 'https://haram-api-dev.razavi.ir/auth';
    private const PHONE = '09123456789';
    private const MOBILE = '+989123456789';
    private const SUBJECT = 'fbfd4578-244b-4ee2-a31b-1a33661343a4';
    private const UPSTREAM_ACCESS = 'upstream-access-token-never-for-browser';
    private const UPSTREAM_REFRESH = 'upstream-refresh-token-never-for-browser';

    protected function setUp(): void
    {
        parent::setUp();

        // Deliberately avoid RefreshDatabase: repository migrations include PostGIS SQL.
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'app.jwt_secret' => 'razavi-direct-auth-isolated-test-secret',
            'public_auth.provider' => 'razavi',
            'public_auth.razavi.base_url' => self::BASE,
            'public_auth.razavi.project' => 'noorolhoda',
            'public_auth.razavi.device' => 'web',
            'public_auth.otp.length' => 4,
            'public_auth.otp.ttl_seconds' => 120,
            'public_auth.otp.cooldown_seconds' => 30,
        ]);
        DB::purge('sqlite');
        Cache::flush();
        Http::preventStrayRequests();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            foreach (['name', 'first_name', 'last_name', 'username', 'email', 'mobile', 'password', 'national_id', 'gender', 'birth_date', 'address', 'preferences', 'avatar_url', 'referral_code', 'otp_hash', 'last_login_ip'] as $column) {
                $table->text($column)->nullable();
            }
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('status')->default('active');
            $table->integer('failed_login_attempts')->default(0);
            foreach (['locked_until', 'last_login_at', 'otp_expires_at', 'otp_last_sent_at', 'last_manual_activation_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->timestamps();
        });
        Schema::create('user_refresh_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token')->unique();
            $table->text('user_agent')->nullable();
            $table->string('ip')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
        });
        $this->identityMigration()->up();
    }

    private function identityMigration(): \Illuminate\Database\Migrations\Migration
    {
        return require database_path('migrations/2026_10_19_000000_create_user_auth_identities.php');
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'name' => 'نام محفوظ کاربر',
            'mobile' => self::PHONE,
            'is_admin' => false,
            'is_active' => true,
            'status' => 'active',
            'email' => 'existing@example.test',
            'preferences' => ['language' => 'fa'],
        ]);
    }

    private function tokens(): array
    {
        return ['status' => 'success', 'data' => [
            'access_token' => self::UPSTREAM_ACCESS,
            'refresh_token' => self::UPSTREAM_REFRESH,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]];
    }

    private function profile(array $attributes = []): array
    {
        return ['status' => 'success', 'data' => $attributes + [
            'uuid' => self::SUBJECT,
            'mobile' => self::MOBILE,
            'firstname' => 'نام کارفرما',
            'lastname' => 'نام خانوادگی',
        ]];
    }

    private function fakeSuccess(?array $profile = null, ?array $tokens = null): void
    {
        Http::fake([
            self::BASE.'/app/v1/login*' => Http::response(['status' => 'success', 'data' => (object) []]),
            self::BASE.'/app/v1/verify*' => Http::response($tokens ?? $this->tokens()),
            self::BASE.'/app/v1/profile*' => Http::response($profile ?? $this->profile()),
        ]);
    }

    private function requestOtp(string $phone = self::PHONE)
    {
        return $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone]);
    }

    private function verifyOtp(string $phone = self::PHONE, string $otp = '1234')
    {
        return $this->postJson('/api/v1/auth/otp/verify', compact('phone', 'otp'));
    }

    private function assertNoSession(): void
    {
        $this->assertDatabaseCount('user_refresh_tokens', 0);
    }

    public function test_request_sends_documented_query_and_does_not_create_a_local_user_or_return_a_code(): void
    {
        $this->fakeSuccess();

        $response = $this->requestOtp('۰۹۱۲۳۴۵۶۷۸۹')->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('message', 'OTP_SENT')
            ->assertJsonPath('expiresIn', 120)->assertJsonPath('retryAfter', 30)
            ->assertJsonPath('otpLength', 4)->assertJsonPath('devOtp', null);

        $this->assertDatabaseCount('users', 0);
        $this->assertNoSession();
        $this->assertStringNotContainsString('1234', $response->getContent());
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return $request->method() === 'POST'
                && parse_url($request->url(), PHP_URL_PATH) === '/auth/app/v1/login'
                && $query == ['mobile' => self::MOBILE, 'project' => 'noorolhoda', 'device' => 'web'];
        });
    }

    public function test_verified_profile_creates_one_public_identity_and_issues_only_local_tokens(): void
    {
        $this->fakeSuccess($this->profile(['is_admin' => true, 'roles' => ['ADMIN'], 'scopes' => ['admin:*']]));
        $this->requestOtp()->assertOk();
        $response = $this->verifyOtp()->assertOk()
            ->assertJsonStructure(['accessToken', 'refreshToken', 'expiresIn', 'user' => ['id', 'phone', 'roles']]);

        $user = User::sole();
        $this->assertFalse($user->is_admin);
        $this->assertSame([], $response->json('user.roles'));
        $this->assertSame($user->id, $response->json('user.id'));
        $claims = app(PublicJwtService::class)->decode($response->json('accessToken'));
        $this->assertSame($user->id, $claims['sub']);
        $this->assertSame('USER', $claims['role']);
        $this->assertDatabaseHas('user_auth_identities', [
            'provider' => 'razavi', 'issuer' => self::BASE, 'subject' => self::SUBJECT, 'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('user_refresh_tokens', ['user_id' => $user->id, 'token' => $response->json('refreshToken')]);
        $this->assertDatabaseMissing('user_refresh_tokens', ['token' => self::UPSTREAM_REFRESH]);
        $this->assertStringNotContainsString(self::UPSTREAM_ACCESS, $response->getContent());
        $this->assertStringNotContainsString(self::UPSTREAM_REFRESH, $response->getContent());
        Http::assertSentCount(3);
        Http::assertSent(function (Request $request): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return $request->method() === 'POST'
                && parse_url($request->url(), PHP_URL_PATH) === '/auth/app/v1/verify'
                && $query == ['mobile' => self::MOBILE, 'code' => '1234', 'project' => 'noorolhoda', 'device' => 'web'];
        });
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && parse_url($request->url(), PHP_URL_PATH) === '/auth/app/v1/profile'
            && $request->hasHeader('Authorization', 'Bearer '.self::UPSTREAM_ACCESS));
    }

    public static function phoneAliases(): array
    {
        return [
            'national' => ['09123456789'],
            'international' => ['+989123456789'],
            'country prefix' => ['989123456789'],
            'Persian digits' => ['۰۹۱۲۳۴۵۶۷۸۹'],
            'Arabic digits' => ['٠٩١٢٣٤٥٦٧٨٩'],
            'historical spaces' => ['0912 345 6789'],
            'historical international format' => ['+98 (912) 345-6789'],
            'historical Persian format' => ['۰۹۱۲ ۳۴۵ ۶۷۸۹'],
        ];
    }

    #[DataProvider('phoneAliases')]
    public function test_unverified_signup_cannot_poison_a_phone_before_its_owner_verifies_otp(string $phone): void
    {
        $this->postJson('/api/v1/users', [
            'phone' => $phone,
            'fullName' => 'نام ثبت شده بدون احراز هویت',
            'email' => 'unverified@example.test',
        ])->assertForbidden()->assertJsonPath('code', 'REGISTRATION_DISABLED');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_auth_identities', 0);
        $this->assertNoSession();
        Http::assertNothingSent();

        // Rejecting the legacy endpoint must not reserve an alias or block its real owner.
        $this->fakeSuccess();
        $this->requestOtp()->assertOk();
        $login = $this->verifyOtp()->assertOk();
        $user = User::sole();
        $this->assertSame($user->id, $login->json('user.id'));
        $this->assertSame(self::PHONE, $user->mobile);
        $this->assertNotSame('unverified@example.test', $user->email);
        $this->assertDatabaseCount('user_auth_identities', 1);
        $this->assertDatabaseHas('user_auth_identities', ['subject' => self::SUBJECT, 'user_id' => $user->id]);
        $this->assertDatabaseCount('user_refresh_tokens', 1);
        Http::assertSentCount(3);
    }

    public function test_provider_registration_is_disabled_before_legacy_payload_validation(): void
    {
        $this->postJson('/api/v1/users', [])
            ->assertForbidden()->assertJsonPath('code', 'REGISTRATION_DISABLED');
        $this->postJson('/api/v1/users', ['phone' => [], 'email' => 'invalid'])
            ->assertForbidden()->assertJsonPath('code', 'REGISTRATION_DISABLED');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_auth_identities', 0);
        $this->assertNoSession();
        Http::assertNothingSent();
    }

    public function test_unknown_provider_cannot_enable_legacy_registration(): void
    {
        config(['public_auth.provider' => 'typo']);

        $this->postJson('/api/v1/users', [
            'phone' => self::PHONE,
            'fullName' => 'نام ثبت شده بدون احراز هویت',
        ])->assertForbidden()->assertJsonPath('code', 'REGISTRATION_DISABLED');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_auth_identities', 0);
        $this->assertNoSession();
        Http::assertNothingSent();
    }

    public function test_explicit_local_provider_preserves_legacy_registration(): void
    {
        config(['public_auth.provider' => 'local']);

        $response = $this->postJson('/api/v1/users', [
            'phone' => self::PHONE,
            'fullName' => 'نام تست محلی',
            'email' => 'legacy@example.test',
            'nationalId' => '1234567890',
        ])->assertCreated()->assertJsonPath('phone', self::PHONE)
            ->assertJsonPath('fullName', 'نام تست محلی');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', [
            'id' => $response->json('id'), 'mobile' => self::PHONE,
            'name' => 'نام تست محلی', 'email' => 'legacy@example.test', 'is_admin' => false,
        ]);
        $this->assertDatabaseCount('user_auth_identities', 0);
        $this->assertNoSession();
        Http::assertNothingSent();
    }

    public function test_signup_aliases_cannot_duplicate_a_verified_account_and_authenticated_profile_update_still_works(): void
    {
        $user = $this->user();
        $this->identity($user);
        $before = $user->fresh()->getAttributes();

        foreach (self::phoneAliases() as [$phone]) {
            $this->postJson('/api/v1/users', [
                'phone' => $phone,
                'fullName' => 'نام ثبت شده بدون احراز هویت',
                'email' => 'unverified@example.test',
            ])->assertForbidden()->assertJsonPath('code', 'REGISTRATION_DISABLED');
        }

        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_auth_identities', 1);
        $this->assertNoSession();
        Http::assertNothingSent();

        $this->fakeSuccess();
        $this->requestOtp()->assertOk();
        $login = $this->verifyOtp()->assertOk()->assertJsonPath('user.id', $user->id);
        $this->withHeader('Authorization', 'Bearer '.$login->json('accessToken'))
            ->patchJson('/api/v1/users/me', [
                'firstName' => 'محمد رضا',
                'lastName' => 'حسینی',
                'birthDate' => '2000-01-01',
                'gender' => 'male',
                'preferences' => ['language' => 'fa'],
            ])->assertOk()->assertJsonPath('id', $user->id)
            ->assertJsonPath('fullName', 'محمد رضا حسینی')
            ->assertJsonPath('phone', self::PHONE);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_auth_identities', 1);
        $this->assertDatabaseCount('user_refresh_tokens', 1);
        $this->assertDatabaseHas('users', [
            'id' => $user->id, 'mobile' => self::PHONE,
            'first_name' => 'محمد رضا', 'last_name' => 'حسینی',
            'email' => $before['email'],
        ]);
        $this->assertDatabaseHas('user_auth_identities', ['subject' => self::SUBJECT, 'user_id' => $user->id]);
        Http::assertSentCount(3);
    }

    #[DataProvider('phoneAliases')]
    public function test_existing_phone_alias_preserves_local_identity_and_profile(string $storedPhone): void
    {
        $user = $this->user(['mobile' => $storedPhone]);
        $before = $user->only(['name', 'email', 'preferences', 'mobile']);
        $this->fakeSuccess();
        $this->requestOtp()->assertOk();
        $this->verifyOtp()->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $user->fresh()->only(array_keys($before)));
        $this->assertDatabaseHas('user_auth_identities', ['subject' => self::SUBJECT, 'user_id' => $user->id]);
    }

    public function test_successful_challenge_cannot_be_replayed(): void
    {
        $this->fakeSuccess();
        $this->requestOtp()->assertOk();
        $this->verifyOtp()->assertOk();
        $this->verifyOtp()->assertStatus(400)->assertJsonPath('code', 'INVALID_OTP');
        Http::assertSentCount(3);
        $this->assertDatabaseCount('user_refresh_tokens', 1);
    }

    public function test_normalized_phone_aliases_share_request_cooldown(): void
    {
        $this->fakeSuccess();
        $this->requestOtp()->assertOk();
        $this->requestOtp(self::MOBILE)->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
        Http::assertSentCount(1);
        $this->travel(31)->seconds();
        $this->requestOtp()->assertOk();
        Http::assertSentCount(2);
    }

    public function test_missing_and_expired_challenges_never_call_verify(): void
    {
        $this->fakeSuccess();
        $this->verifyOtp()->assertStatus(400)->assertJsonPath('code', 'INVALID_OTP');
        Http::assertNothingSent();
        $this->requestOtp()->assertOk();
        $this->travel(121)->seconds();
        $this->verifyOtp()->assertStatus(400)->assertJsonPath('code', 'INVALID_OTP');
        Http::assertSentCount(1);
        $this->assertNoSession();
    }

    public function test_profile_phone_mismatch_cannot_create_or_authenticate_any_account(): void
    {
        $this->fakeSuccess($this->profile(['mobile' => '+989111111111']));
        $this->requestOtp()->assertOk();
        $this->verifyOtp()->assertStatus(502)->assertJsonPath('code', 'IDENTITY_MISMATCH');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_auth_identities', 0);
        $this->assertNoSession();
    }

    public static function malformedProfiles(): array
    {
        return [
            'failure envelope' => [['status' => 'error', 'data' => ['uuid' => self::SUBJECT, 'mobile' => self::MOBILE]]],
            'missing data' => [['status' => 'success']],
            'missing subject' => [['status' => 'success', 'data' => ['mobile' => self::MOBILE]]],
            'bad subject' => [['status' => 'success', 'data' => ['uuid' => 'not-a-uuid', 'mobile' => self::MOBILE]]],
            'missing mobile' => [['status' => 'success', 'data' => ['uuid' => self::SUBJECT]]],
            'invalid mobile' => [['status' => 'success', 'data' => ['uuid' => self::SUBJECT, 'mobile' => '1234']]],
        ];
    }

    #[DataProvider('malformedProfiles')]
    public function test_malformed_profile_fails_closed(array $profile): void
    {
        $this->fakeSuccess($profile);
        $this->requestOtp()->assertOk();
        $response = $this->verifyOtp();
        $this->assertGreaterThanOrEqual(400, $response->status());
        $this->assertLessThan(600, $response->status());
        $response->assertJsonMissingPath('accessToken');
        $this->assertDatabaseCount('users', 0);
        $this->assertNoSession();
    }

    public static function malformedTokenResponses(): array
    {
        return [
            'no status' => [['data' => ['access_token' => self::UPSTREAM_ACCESS]]],
            'error status' => [['status' => 'error', 'data' => ['access_token' => self::UPSTREAM_ACCESS]]],
            'no token' => [['status' => 'success', 'data' => []]],
            'empty token' => [['status' => 'success', 'data' => ['access_token' => '']]],
            'wrong token type' => [['status' => 'success', 'data' => ['access_token' => ['token']]]],
            'invalid bearer type' => [['status' => 'success', 'data' => ['access_token' => self::UPSTREAM_ACCESS, 'token_type' => ['Bearer']]]],
            'non-bearer token' => [['status' => 'success', 'data' => ['access_token' => self::UPSTREAM_ACCESS, 'token_type' => 'Basic']]],
            'header injection token' => [['status' => 'success', 'data' => ['access_token' => "value\r\nX-Injected: private"]]],
            'NUL token' => [['status' => 'success', 'data' => ['access_token' => "value\0suffix"]]],
            'C0 token' => [['status' => 'success', 'data' => ['access_token' => "value\x01suffix"]]],
            'DEL token' => [['status' => 'success', 'data' => ['access_token' => "value\x7fsuffix"]]],
            'space in token' => [['status' => 'success', 'data' => ['access_token' => 'value suffix']]],
            'tab in token' => [['status' => 'success', 'data' => ['access_token' => "value\tsuffix"]]],
            'wrong data type' => [['status' => 'success', 'data' => 'invalid']],
        ];
    }

    #[DataProvider('malformedTokenResponses')]
    public function test_malformed_verify_response_cannot_issue_a_local_session(array $tokens): void
    {
        $this->fakeSuccess(null, $tokens);
        $this->requestOtp()->assertOk();
        $this->verifyOtp()->assertStatus(502)->assertJsonPath('code', 'AUTH_PROVIDER_INVALID_RESPONSE');
        Http::assertSentCount(2);
        $this->assertDatabaseCount('users', 0);
        $this->assertNoSession();
    }

    public static function blockedAccounts(): array
    {
        return [
            'admin' => [['is_admin' => true], 403, 'UNAUTHORIZED'],
            'disabled status' => [['status' => 'disabled'], 423, 'ACCOUNT_LOCKED'],
            'inactive flag' => [['is_active' => false], 423, 'ACCOUNT_LOCKED'],
        ];
    }

    #[DataProvider('blockedAccounts')]
    public function test_provider_cannot_authenticate_local_blocked_accounts(array $attributes, int $status, string $code): void
    {
        $user = $this->user($attributes);
        $this->fakeSuccess();
        // Blocking before sending an SMS is also valid; if sent, verification must still block.
        $response = $this->requestOtp();
        if ($response->isSuccessful()) {
            $response = $this->verifyOtp();
        }
        $response->assertStatus($status)->assertJsonPath('code', $code);
        $this->assertNoSession();
        $this->assertDatabaseCount('users', 1);
        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_temporarily_locked_local_user_cannot_login(): void
    {
        $this->user(['locked_until' => now()->addMinutes(10)]);
        $this->fakeSuccess();
        $response = $this->requestOtp();
        if ($response->isSuccessful()) {
            $response = $this->verifyOtp();
        }
        $response->assertStatus(423)->assertJsonPath('code', 'ACCOUNT_LOCKED');
        $this->assertNoSession();
    }

    public function test_ambiguous_legacy_phone_aliases_require_resolution_without_merging(): void
    {
        $this->user();
        $this->user(['mobile' => self::MOBILE, 'name' => 'حساب دوم']);
        $this->fakeSuccess();
        $response = $this->requestOtp();
        if ($response->isSuccessful()) {
            $response = $this->verifyOtp();
        }
        $response->assertStatus(409)->assertJsonPath('code', 'IDENTITY_CONFLICT');
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('user_auth_identities', 0);
        $this->assertNoSession();
    }

    public static function formattedMobiles(): array
    {
        return [
            'national spacing' => ['0912 345 6789'],
            'international formatting' => ['+98 (912) 345-6789'],
            'Persian formatting' => ['۰۹۱۲ ۳۴۵ ۶۷۸۹'],
        ];
    }

    #[DataProvider('formattedMobiles')]
    public function test_disabled_historical_formatted_mobile_is_blocked_before_any_provider_request(string $storedPhone): void
    {
        $user = $this->user(['mobile' => $storedPhone, 'status' => 'disabled', 'is_active' => false]);
        Http::fake();

        $this->requestOtp()->assertStatus(423)->assertJsonPath('code', 'ACCOUNT_LOCKED');
        $this->verifyOtp()->assertStatus(423)->assertJsonPath('code', 'ACCOUNT_LOCKED');

        Http::assertNothingSent();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_auth_identities', 0);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'mobile' => $storedPhone, 'status' => 'disabled', 'is_active' => false]);
        $this->assertNoSession();
    }

    public function test_exact_and_formatted_legacy_mobile_collision_blocks_without_provider_requests(): void
    {
        $this->user();
        $this->user(['mobile' => '+98 (912) 345-6789', 'name' => 'حساب قدیمی دیگر']);
        Http::fake();

        $this->requestOtp()->assertStatus(409)->assertJsonPath('code', 'IDENTITY_CONFLICT');
        $this->verifyOtp()->assertStatus(409)->assertJsonPath('code', 'IDENTITY_CONFLICT');

        Http::assertNothingSent();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('user_auth_identities', 0);
        $this->assertNoSession();
    }

    private function identity(User $user, string $subject = self::SUBJECT): void
    {
        DB::table('user_auth_identities')->insert([
            'provider' => 'razavi', 'issuer' => self::BASE, 'subject' => $subject, 'user_id' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_actual_identity_migration_rolls_back_and_reapplies_without_changing_users(): void
    {
        $user = $this->user();
        $before = $user->fresh()->getAttributes();
        $this->identity($user);

        $this->identityMigration()->down();
        $this->assertFalse(Schema::hasTable('user_auth_identities'));
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->identityMigration()->up();
        $this->assertTrue(Schema::hasTable('user_auth_identities'));
        $this->assertDatabaseCount('user_auth_identities', 0);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->identity($user);
        $this->assertDatabaseHas('user_auth_identities', ['subject' => self::SUBJECT, 'user_id' => $user->id]);
    }

    public function test_actual_identity_foreign_key_cascades_without_affecting_other_accounts(): void
    {
        $user = $this->user();
        $other = $this->user(['mobile' => '09111111111']);
        $otherSubject = 'a28304a5-811f-4fd9-a46b-a85e0ad2302c';
        $this->identity($user);
        $this->identity($other, $otherSubject);

        $user->delete();

        $this->assertDatabaseMissing('user_auth_identities', ['user_id' => $user->id]);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
        $this->assertDatabaseHas('user_auth_identities', ['subject' => $otherSubject, 'user_id' => $other->id]);
        $this->assertDatabaseCount('user_auth_identities', 1);
    }

    public static function duplicateIdentityBindings(): array
    {
        return [
            'subject cannot bind to second user' => [true],
            'user cannot bind to second subject in same issuer' => [false],
        ];
    }

    #[DataProvider('duplicateIdentityBindings')]
    public function test_actual_identity_unique_constraints_prevent_rebinding(bool $duplicateSubject): void
    {
        $user = $this->user();
        $other = $this->user(['mobile' => '09111111111']);
        $this->identity($user);

        try {
            $this->identity(
                $duplicateSubject ? $other : $user,
                $duplicateSubject ? self::SUBJECT : 'a28304a5-811f-4fd9-a46b-a85e0ad2302c',
            );
            $this->fail('Database accepted conflicting provider identity binding.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('unique', strtolower($exception->getMessage()));
        }
        $this->assertDatabaseCount('user_auth_identities', 1);
        $this->assertDatabaseHas('user_auth_identities', ['subject' => self::SUBJECT, 'user_id' => $user->id]);
    }

    public function test_actual_identity_constraints_keep_environment_namespaces_separate(): void
    {
        $user = $this->user();
        $other = $this->user(['mobile' => '09111111111']);
        $this->identity($user);
        DB::table('user_auth_identities')->insert([
            'provider' => 'razavi', 'issuer' => 'https://haram-api.razavi.ir/auth',
            'subject' => self::SUBJECT, 'user_id' => $other->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertDatabaseCount('user_auth_identities', 2);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_account_disabled_during_provider_roundtrip_stays_disabled_and_receives_no_session(): void
    {
        $user = $this->user();
        Http::fake(function (Request $request) use ($user) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/auth/app/v1/login' => Http::response(['status' => 'success', 'data' => (object) []]),
                '/auth/app/v1/verify' => Http::response($this->tokens()),
                '/auth/app/v1/profile' => (function () use ($user) {
                    User::whereKey($user->id)->update(['is_active' => false, 'status' => 'disabled']);

                    return Http::response($this->profile());
                })(),
                default => throw new \LogicException('Unexpected provider request.'),
            };
        });

        $this->requestOtp()->assertOk();
        $this->verifyOtp()->assertStatus(423)->assertJsonPath('code', 'ACCOUNT_LOCKED');

        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame('disabled', $user->fresh()->status);
        $this->assertNull($user->fresh()->last_login_at);
        $this->assertDatabaseCount('users', 1);
        $this->assertNoSession();
        Http::assertSentCount(3);
    }

    public function test_subject_bound_to_another_local_account_cannot_be_rebound_by_phone(): void
    {
        $owner = $this->user(['mobile' => '09111111111']);
        $target = $this->user();
        $this->identity($owner);
        $this->fakeSuccess();
        $this->requestOtp()->assertOk();
        $this->verifyOtp()->assertStatus(409)->assertJsonPath('code', 'IDENTITY_CONFLICT');
        $this->assertDatabaseHas('user_auth_identities', ['subject' => self::SUBJECT, 'user_id' => $owner->id]);
        $this->assertDatabaseMissing('user_auth_identities', ['user_id' => $target->id]);
        $this->assertNoSession();
    }

    public function test_local_account_bound_to_different_subject_cannot_accept_replacement(): void
    {
        $user = $this->user();
        $oldSubject = 'a28304a5-811f-4fd9-a46b-a85e0ad2302c';
        $this->identity($user, $oldSubject);
        $this->fakeSuccess();
        $this->requestOtp()->assertOk();
        $this->verifyOtp()->assertStatus(409)->assertJsonPath('code', 'IDENTITY_CONFLICT');
        $this->assertDatabaseHas('user_auth_identities', ['subject' => $oldSubject, 'user_id' => $user->id]);
        $this->assertDatabaseCount('user_auth_identities', 1);
        $this->assertNoSession();
    }

    public function test_previously_bound_identity_logs_in_again_without_duplicate_rows(): void
    {
        $user = $this->user();
        $this->identity($user);
        $this->fakeSuccess();
        $this->requestOtp()->assertOk();
        $this->verifyOtp()->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_auth_identities', 1);
    }

    public static function providerErrors(): array
    {
        return [
            'invalid code' => [422, 400, 'INVALID_OTP'],
            'missing challenge' => [404, 400, 'INVALID_OTP'],
            'rate limited' => [429, 429, 'TOO_MANY_REQUESTS'],
            'provider failure' => [500, 503, 'AUTH_PROVIDER_UNAVAILABLE'],
            'gateway failure' => [502, 503, 'AUTH_PROVIDER_UNAVAILABLE'],
        ];
    }

    #[DataProvider('providerErrors')]
    public function test_provider_verify_errors_are_sanitized_and_never_retry_or_fallback(int $upstreamStatus, int $expectedStatus, string $expectedCode): void
    {
        Http::fake([
            self::BASE.'/app/v1/login*' => Http::response(['status' => 'success', 'data' => (object) []]),
            self::BASE.'/app/v1/verify*' => Http::response([
                'status' => 'error', 'message' => 'PRIVATE PROVIDER STACK TRACE',
                'code' => '1234', 'token' => self::UPSTREAM_ACCESS,
            ], $upstreamStatus, ['Retry-After' => '47']),
        ]);
        $this->requestOtp()->assertOk();
        $response = $this->verifyOtp()->assertStatus($expectedStatus)->assertJsonPath('code', $expectedCode);
        $this->assertStringNotContainsString('PRIVATE PROVIDER STACK TRACE', $response->getContent());
        $this->assertStringNotContainsString(self::UPSTREAM_ACCESS, $response->getContent());
        Http::assertSentCount(2);
        $this->assertNoSession();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_connection_failure_returns_sanitized_service_unavailable_without_creating_challenge(): void
    {
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            ++$calls;
            throw new ConnectionException('secret internal proxy failure');
        });
        $response = $this->requestOtp()->assertStatus(503)->assertJsonPath('code', 'AUTH_PROVIDER_UNAVAILABLE');
        $this->assertStringNotContainsString('secret internal proxy', $response->getContent());
        $this->assertSame(1, $calls);
        $this->verifyOtp()->assertStatus(400)->assertJsonPath('code', 'INVALID_OTP');
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('users', 0);
        $this->assertNoSession();
    }

    public function test_failed_send_does_not_enter_cooldown_and_can_be_retried(): void
    {
        Http::fakeSequence()->push(['status' => 'error'], 503)->push(['status' => 'success', 'data' => (object) []]);
        $this->requestOtp()->assertStatus(503);
        $this->requestOtp()->assertOk();
        Http::assertSentCount(2);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_provider_request_rejection_is_sanitized_and_does_not_create_a_challenge(): void
    {
        Http::fake([
            self::BASE.'/app/v1/login*' => Http::response([
                'status' => 'error', 'message' => 'PRIVATE STACK', 'mobile' => self::MOBILE,
            ], 422),
        ]);
        $response = $this->requestOtp()->assertStatus(422)->assertJsonPath('code', 'AUTH_PROVIDER_REJECTED');
        $this->assertStringNotContainsString('PRIVATE STACK', $response->getContent());
        $this->verifyOtp()->assertStatus(400)->assertJsonPath('code', 'INVALID_OTP');
        Http::assertSentCount(1);
        $this->assertNoSession();
    }

    public function test_provider_rate_limit_returns_bounded_retry_hint_without_leaking_provider_response(): void
    {
        Http::fake([
            self::BASE.'/app/v1/login*' => Http::response(['message' => 'private limiter details'], 429, ['Retry-After' => '47']),
        ]);
        $response = $this->requestOtp()->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS')
            ->assertJsonPath('retryAfter', 47)->assertHeader('Retry-After', '47');
        $this->assertStringNotContainsString('private limiter', $response->getContent());
        Http::assertSentCount(1);
        $this->assertNoSession();
    }

    public function test_invalid_code_attempt_budget_stops_upstream_requests_until_a_new_challenge(): void
    {
        config(['public_auth.otp.max_attempts' => 5]);
        Http::fake([
            self::BASE.'/app/v1/login*' => Http::response(['status' => 'success', 'data' => (object) []]),
            self::BASE.'/app/v1/verify*' => Http::response(['status' => 'error'], 422),
        ]);
        $this->requestOtp()->assertOk();
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $this->verifyOtp()->assertStatus(400)->assertJsonPath('code', 'INVALID_OTP');
        }
        Http::assertSentCount(6); // one send plus five provider verifications
        $this->assertNoSession();
    }

    public function test_invalid_code_does_not_consume_an_otherwise_valid_challenge(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'success', 'data' => (object) []])
            ->push(['status' => 'error'], 422)
            ->push($this->tokens())
            ->push($this->profile());
        $this->requestOtp()->assertOk();
        $this->verifyOtp(self::PHONE, '0000')->assertStatus(400)->assertJsonPath('code', 'INVALID_OTP');
        $this->verifyOtp()->assertOk();
        Http::assertSentCount(4);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_refresh_tokens', 1);
    }

    public function test_provider_mode_rejects_old_development_bypass_and_accepts_persian_otp_digits(): void
    {
        config(['public_auth.local.dev_bypass' => true, 'public_auth.local.return_in_response' => true]);
        $this->fakeSuccess();
        $this->requestOtp()->assertOk()->assertJsonPath('devOtp', null);
        $this->verifyOtp(self::PHONE, '1')->assertStatus(422)->assertJsonValidationErrors('otp');
        Http::assertSentCount(1);
        $this->verifyOtp(self::PHONE, '۱۲۳۴')->assertOk();
        Http::assertSentCount(3);
    }

    public function test_profile_lookup_failure_never_issues_tokens_from_unverified_token_claims(): void
    {
        Http::fake([
            self::BASE.'/app/v1/login*' => Http::response(['status' => 'success', 'data' => (object) []]),
            self::BASE.'/app/v1/verify*' => Http::response($this->tokens()),
            self::BASE.'/app/v1/profile*' => Http::response(['message' => 'private profile failure'], 503),
        ]);
        $this->requestOtp()->assertOk();
        $response = $this->verifyOtp()->assertStatus(503)->assertJsonPath('code', 'AUTH_PROVIDER_UNAVAILABLE');
        $this->assertStringNotContainsString('private profile', $response->getContent());
        $this->assertDatabaseCount('users', 0);
        $this->assertNoSession();
        Http::assertSentCount(3);
    }

    public function test_unknown_provider_does_not_silently_fall_back_to_local_development_otp(): void
    {
        config(['public_auth.provider' => 'typo']);
        Http::fake();
        $this->requestOtp()->assertStatus(503)->assertJsonPath('code', 'AUTH_PROVIDER_UNAVAILABLE');
        Http::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_local_refresh_me_and_logout_remain_compatible_after_provider_login(): void
    {
        $this->fakeSuccess();
        $this->requestOtp()->assertOk();
        $login = $this->verifyOtp()->assertOk()->json();
        $this->withHeader('Authorization', 'Bearer '.$login['accessToken'])
            ->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('id', $login['user']['id']);

        $rotated = $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $login['refreshToken']])
            ->assertOk()->assertJsonPath('user.id', $login['user']['id'])->json();
        $this->assertNotSame($login['refreshToken'], $rotated['refreshToken']);
        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $login['refreshToken']])->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$rotated['accessToken'])
            ->postJson('/api/v1/auth/logout', ['refreshToken' => $rotated['refreshToken']])->assertNoContent();
        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $rotated['refreshToken']])->assertUnauthorized();
        $this->assertDatabaseMissing('user_refresh_tokens', ['revoked_at' => null]);
        Http::assertSentCount(3);
    }
}
