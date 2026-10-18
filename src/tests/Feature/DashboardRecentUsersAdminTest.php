<?php

namespace Tests\Feature;

use App\Services\JwtService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Real PostgreSQL profile data and admin JWT coverage; every fixture is rolled back. */
class DashboardRecentUsersAdminTest extends TestCase
{
    private const ENDPOINT = '/api/v1/admin/dashboard/recent-users';

    private bool $transactionStarted = false;
    private string $marker;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL/PostGIS database.');
        }
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->marker = 'recent-users-'.bin2hex(random_bytes(6));
        config(['app.jwt_secret' => 'dashboard-recent-user-isolated-test-secret']);
        $this->admin = $this->user(['is_admin' => true, 'name' => 'admin-auth-'.bin2hex(random_bytes(6))]);
        $this->authenticate($this->admin);
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function user(array $values = []): int
    {
        return (int) DB::table('users')->insertGetId($values + [
            'name' => $this->marker,
            'username' => $this->marker.'-'.bin2hex(random_bytes(4)),
            'mobile' => '09'.random_int(100000000, 999999999),
            'is_admin' => false, 'status' => 'active', 'is_active' => true,
            'created_at' => '2026-10-18 10:00:00', 'updated_at' => '2026-10-18 10:00:00',
        ]);
    }

    private function authenticate(int $id): void
    {
        $token = app(JwtService::class)->createAccessToken(['sub' => $id, 'role' => 'ADMIN']);
        $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function listing(array $query = []): array
    {
        return $this->getJson(self::ENDPOINT.'?'.http_build_query($query + ['search' => $this->marker, 'pageSize' => 100]))
            ->assertOk()->json();
    }

    private function route(array $meta, bool $ok = true): void
    {
        DB::table('route_logs')->insert([
            'mode' => 'walk', 'gender' => 'male', 'ok' => $ok,
            'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
        ]);
    }

    private function storedUsers(array $ids): array
    {
        return DB::table('users')->whereIn('id', $ids)->orderBy('id')->get()->toArray();
    }

    public function test_each_user_has_their_real_profile_without_fake_metrics_or_private_fields(): void
    {
        $first = $this->user([
            'birth_date' => '1996-08-10', 'email' => $this->marker.'-first@example.test', 'gender' => 'female',
            'address' => json_encode(['province' => 'تهران', 'city' => 'تهران', 'line1' => 'private street', 'postalCode' => '1234567890']),
            'last_login_at' => '2026-10-17T12:34:56.123456Z',
            'national_id' => 'private-national-id', 'otp_hash' => 'private-otp-hash', 'password' => 'private-password',
        ]);
        $second = $this->user([
            'birth_date' => '2001-03-21', 'email' => $this->marker.'-second@example.test', 'gender' => 'male',
            'address' => json_encode(['province' => 'فارس', 'city' => 'شیراز']),
            'created_at' => '2026-10-17 09:20:00',
        ]);
        $before = $this->storedUsers([$first, $second]);

        $users = array_column($this->listing()['data'], null, 'id');
        $this->assertSame('1996-08-10', $users[$first]['birthDate']);
        $this->assertSame('2001-03-21', $users[$second]['birthDate']);
        $this->assertSame($this->marker.'-first@example.test', $users[$first]['email']);
        $this->assertSame($this->marker.'-second@example.test', $users[$second]['email']);
        $this->assertSame('female', $users[$first]['gender']);
        $this->assertSame('male', $users[$second]['gender']);
        $this->assertSame('تهران', $users[$first]['province']);
        $this->assertSame('تهران', $users[$first]['city']);
        $this->assertSame('فارس', $users[$second]['province']);
        $this->assertSame('شیراز', $users[$second]['city']);
        $this->assertSame('2026-10-18T10:00:00.000000Z', $users[$first]['joinDate']);
        $this->assertSame('2026-10-17T09:20:00.000000Z', $users[$second]['joinDate']);
        $this->assertSame('2026-10-17T12:34:56.123456Z', $users[$first]['lastLoginAt']);
        foreach ([$first, $second] as $id) {
            $this->assertSame($users[$id]['mobile'], $users[$id]['phone']);
            $this->assertNull($users[$id]['savedRoutesCount']);
            $this->assertNull($users[$id]['placeVisitsCount']);
            foreach (['password', 'otp_hash', 'national_id', 'address', 'line1', 'postalCode', 'last_login_ip', 'preferences'] as $private) {
                $this->assertArrayNotHasKey($private, $users[$id]);
            }
        }
        $this->assertEquals($before, $this->storedUsers([$first, $second]));
    }

    public function test_missing_dates_and_malformed_legacy_address_remain_unknown(): void
    {
        $ids = [];
        foreach ([null, '[]', '"legacy scalar"', '{"province":["invalid"],"city":{"name":"invalid"}}'] as $address) {
            $ids[] = $this->user([
                'created_at' => null, 'birth_date' => null, 'last_login_at' => null,
                'address' => $address, 'gender' => 'legacy-unknown',
            ]);
        }
        $before = $this->storedUsers($ids);
        $users = array_column($this->listing()['data'], null, 'id');
        foreach ($ids as $id) {
            foreach (['birthDate', 'joinDate', 'lastLoginAt', 'gender', 'province', 'city', 'savedRoutesCount', 'placeVisitsCount'] as $field) {
                $this->assertNull($users[$id][$field], $field.' must not invent a value.');
            }
        }
        $this->assertEquals($before, $this->storedUsers($ids));
    }

    public function test_utc_instants_and_date_only_birthdays_are_independent_of_session_and_php_timezones(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'Asia/Tehran'");
        $id = $this->user([
            'created_at' => '2026-10-17 20:45:00', 'birth_date' => '2000-03-20',
            'last_login_at' => '2026-10-18T00:15:00.123456+03:30',
        ]);
        $before = $this->storedUsers([$id]);
        $previousTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set('America/Los_Angeles');
            $user = array_column($this->listing()['data'], null, 'id')[$id];
        } finally {
            date_default_timezone_set($previousTimezone);
        }
        $this->assertSame('2000-03-20', $user['birthDate']);
        $this->assertSame('2026-10-17T20:45:00.000000Z', $user['joinDate']);
        $this->assertSame('2026-10-17T20:45:00.123456Z', $user['lastLoginAt']);
        $this->assertEquals($before, $this->storedUsers([$id]));
    }

    public function test_pagination_search_and_success_counts_preserve_existing_user_composition_and_source_rows(): void
    {
        $ids = [];
        foreach (['10:00:00', '11:00:00', '12:00:00', '13:00:00'] as $index => $time) {
            $ids[] = $this->user(['created_at' => '2026-10-18 '.$time, 'is_admin' => $index === 2]);
        }
        $outside = $this->user(['name' => 'outside-search-'.bin2hex(random_bytes(6))]);
        $this->route(['user_id' => $ids[3]]);
        $this->route(['userId' => (string) $ids[3]]);
        $this->route(['user_id' => $ids[3]], false);
        $this->route(['user_id' => $outside, 'userId' => $ids[3]]);
        $this->route(['user_id' => $ids[2]]);
        $this->route(['user_id' => $outside]);
        $beforeUsers = $this->storedUsers([...$ids, $outside]);
        $beforeRoutes = DB::table('route_logs')->orderBy('id')->get()->toArray();

        $first = $this->listing(['pageSize' => 2]);
        $second = $this->listing(['page' => 2, 'pageSize' => 2]);
        $this->assertSame([$ids[3], $ids[2]], array_column($first['data'], 'id'));
        $this->assertSame([$ids[1], $ids[0]], array_column($second['data'], 'id'));
        $this->assertSame(['page' => 1, 'pageSize' => 2, 'total' => 4, 'pages' => 2], $first['pagination']);
        $this->assertSame(['page' => 2, 'pageSize' => 2, 'total' => 4, 'pages' => 2], $second['pagination']);
        $this->assertSame(2, $first['data'][0]['successCount']);
        $this->assertSame(1, $first['data'][1]['successCount']);
        $this->assertSame([0, 0], array_column($second['data'], 'successCount'));
        $phone = DB::table('users')->where('id', $ids[0])->value('mobile');
        $this->assertSame([$ids[0]], array_column($this->listing(['search' => $phone])['data'], 'id'));
        $this->assertSame([], $this->listing(['page' => 3, 'pageSize' => 2])['data']);
        $this->assertEquals($beforeUsers, $this->storedUsers([...$ids, $outside]));
        $this->assertEquals($beforeRoutes, DB::table('route_logs')->orderBy('id')->get()->toArray());
    }

    public function test_successful_public_otp_login_is_read_from_its_persisted_timestamp_without_dashboard_writes(): void
    {
        $id = $this->user([
            'birth_date' => '2000-03-20', 'last_login_at' => null,
            'otp_hash' => Hash::make('483927'), 'otp_expires_at' => '2099-01-01T00:00:00Z',
        ]);
        $this->assertNull(array_column($this->listing()['data'], null, 'id')[$id]['lastLoginAt']);
        $phone = DB::table('users')->where('id', $id)->value('mobile');
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'otp' => '483927'])->assertOk();
        $stored = DB::table('users')->where('id', $id)->value('last_login_at');
        $this->assertNotNull($stored);
        $before = $this->storedUsers([$id]);
        $user = array_column($this->listing()['data'], null, 'id')[$id];
        $this->assertSame(Carbon::parse($stored)->utc()->toISOString(), $user['lastLoginAt']);
        $this->assertSame('2000-03-20', $user['birthDate']);
        $this->assertEquals($before, $this->storedUsers([$id]));
    }

    public function test_malformed_route_metadata_cannot_break_list_or_assign_another_users_counts(): void
    {
        $id = $this->user(['id' => 8_000_000_000 + random_int(1, 999_999)]);
        $other = $this->user(['is_admin' => true]);
        foreach ([
            ['user_id' => (string) $id],
            ['user_id' => '000'.$id],
            ['user_id' => null, 'userId' => (string) $id],
            ['userId' => $id],
        ] as $meta) {
            $this->route($meta);
        }
        foreach ([
            ['user_id' => 'not-a-user'], ['user_id' => true], ['user_id' => ['id' => $id]],
            ['user_id' => '9223372036854775808'], ['user_id' => str_repeat('9', 100)],
            ['user_id' => $id.'.0'], ['user_id' => -$id], ['user_id' => 'invalid', 'userId' => $id],
            ['user_id' => $other, 'userId' => $id], [],
        ] as $meta) {
            $this->route($meta);
        }
        $this->route(['user_id' => $id], false);
        $users = array_column($this->listing()['data'], null, 'id');
        $this->assertSame(4, $users[$id]['successCount']);
        $this->assertSame(1, $users[$other]['successCount']);
        $this->assertNull($users[$id]['savedRoutesCount']);
        $this->assertNull($users[$id]['placeVisitsCount']);
    }

    public function test_profile_details_still_require_an_active_admin_and_validate_existing_list_parameters(): void
    {
        $this->user(['birth_date' => '2001-03-21']);
        foreach ([null, $this->user(), $this->user(['is_admin' => true, 'status' => 'disabled'])] as $actor) {
            if ($actor === null) {
                $this->withHeader('Authorization', '');
            } else {
                $this->authenticate($actor);
            }
            $status = $actor !== null && DB::table('users')->where('id', $actor)->value('status') === 'disabled' ? 423 : 401;
            $this->getJson(self::ENDPOINT)->assertStatus($status)->assertJsonMissingPath('data');
        }
        $this->authenticate($this->admin);
        $this->getJson(self::ENDPOINT.'?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson(self::ENDPOINT.'?pageSize=101')->assertUnprocessable()->assertJsonValidationErrors('pageSize');
        $this->getJson(self::ENDPOINT.'?search[]=invalid')->assertUnprocessable()->assertJsonValidationErrors('search');
    }
}
