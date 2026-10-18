<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Services\OtpService;
use App\Services\PublicJwtService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/** Real PostgreSQL, admin HTTP and public login regression coverage. */
class SignedUserAdminTest extends TestCase
{
    private bool $transactionStarted = false;
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') $this->markTestSkipped('Requires isolated PostgreSQL/PostGIS database.');
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->migration()->up();
        $this->withoutMiddleware(AdminAuth::class);
        $this->marker = 'signed-user-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) DB::rollBack();
        parent::tearDown();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_18_220000_add_user_manual_activation_at.php');
    }

    private function user(array $values = []): int
    {
        return (int) DB::table('users')->insertGetId($values + [
            'name' => $this->marker, 'username' => $this->marker.'-'.bin2hex(random_bytes(3)),
            'mobile' => '09'.random_int(100000000, 999999999),
            'is_admin' => false, 'status' => 'active', 'is_active' => true,
            'created_at' => '2024-01-02 03:04:05', 'updated_at' => '2024-01-02 03:04:05',
        ]);
    }

    private function updateUserStatus(int $id, string $status, array $extra = []): array
    {
        return $this->patchJson('/api/v1/admin/users/'.$id.'/status', ['status' => $status] + $extra)->assertOk()->json();
    }

    private function listing(array $query = []): array
    {
        return $this->getJson('/api/v1/admin/users?'.http_build_query($query + ['search' => $this->marker, 'pageSize' => 100]))
            ->assertOk()->json();
    }

    private function storedInstant(int $id): ?string
    {
        $value = DB::table('users')->where('id', $id)->value('last_manual_activation_at');
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->toISOString();
    }

    public function test_migration_keeps_legacy_and_new_registration_unknown_and_reapplication_does_not_invent_history(): void
    {
        $this->migration()->down();
        $legacy = $this->user();
        $this->migration()->up();
        $new = $this->user();
        foreach ([$legacy, $new] as $id) {
            $this->getJson('/api/v1/admin/users/'.$id)->assertOk()->assertJsonPath('lastManualActivationAt', null);
            $this->assertNull($this->storedInstant($id));
        }
        $this->migration()->up();
        $this->assertNull($this->storedInstant($legacy));
        $this->assertNull($this->storedInstant($new));
    }

    public function test_only_actual_manual_activation_stamps_server_time_and_preserves_security_fields(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'Asia/Tehran'");
        $id = $this->user([
            'status' => 'disabled', 'is_active' => false, 'locked_until' => '2035-01-01T00:00:00Z',
            'last_login_at' => '2023-04-05T06:07:08Z', 'last_login_ip' => '192.0.2.1',
            'failed_login_attempts' => 3, 'otp_hash' => 'existing-hash',
            'otp_expires_at' => '2035-01-01T00:00:00Z', 'otp_last_sent_at' => '2023-04-05T06:07:00Z',
        ]);
        $fields = ['created_at', 'last_login_at', 'last_login_ip', 'locked_until', 'failed_login_attempts', 'otp_hash', 'otp_expires_at', 'otp_last_sent_at'];
        $before = DB::table('users')->where('id', $id)->first($fields);
        $lower = (float) DB::selectOne('SELECT extract(epoch FROM clock_timestamp()) AS date')->date;
        $activated = $this->updateUserStatus($id, 'active', ['last_manual_activation_at' => '1999-01-01', 'lastManualActivationAt' => '1999-01-01']);
        $upper = (float) DB::selectOne('SELECT extract(epoch FROM clock_timestamp()) AS date')->date;
        $date = CarbonImmutable::parse($activated['lastManualActivationAt']);
        $this->assertGreaterThanOrEqual($lower, (float) $date->format('U.u'));
        $this->assertLessThanOrEqual($upper, (float) $date->format('U.u'));
        $this->assertSame($this->storedInstant($id), $activated['lastManualActivationAt']);
        $this->assertSame('active', $activated['status']);
        $this->assertTrue($activated['isActive']);
        $this->assertEquals($before, DB::table('users')->where('id', $id)->first($fields));
        $repeated = $this->updateUserStatus($id, 'active');
        $this->assertSame($activated['lastManualActivationAt'], $repeated['lastManualActivationAt']);
        $disabled = $this->updateUserStatus($id, 'inactive');
        $this->assertSame('disabled', $disabled['status']);
        $this->assertFalse($disabled['isActive']);
        $this->assertSame($activated['lastManualActivationAt'], $disabled['lastManualActivationAt']);
        $reactivated = $this->updateUserStatus($id, 'active');
        $this->assertNotSame($activated['lastManualActivationAt'], $reactivated['lastManualActivationAt']);
        $this->assertEquals($before, DB::table('users')->where('id', $id)->first($fields));
    }

    public function test_unknown_active_rows_stay_unknown_on_repeated_active_and_deactivation(): void
    {
        $id = $this->user();
        $this->assertNull($this->updateUserStatus($id, 'active')['lastManualActivationAt']);
        $this->assertNull($this->updateUserStatus($id, 'inactive')['lastManualActivationAt']);
        $this->assertNotNull($this->updateUserStatus($id, 'active')['lastManualActivationAt']);
    }

    public function test_manual_activation_of_locked_or_inconsistent_inactive_state_counts_as_transition(): void
    {
        foreach ([['status' => 'locked'], ['status' => 'active', 'is_active' => false]] as $state) {
            $id = $this->user($state);
            $this->assertNotNull($this->updateUserStatus($id, 'active')['lastManualActivationAt']);
        }
    }

    public function test_list_details_and_status_return_the_same_manual_activation_instant(): void
    {
        $id = $this->user(['status' => 'disabled', 'is_active' => false]);
        $activated = $this->updateUserStatus($id, 'active');
        $this->getJson('/api/v1/admin/users/'.$id)->assertOk()
            ->assertJsonPath('lastManualActivationAt', $activated['lastManualActivationAt']);
        $listed = collect($this->listing()['data'])->firstWhere('id', $id);
        $this->assertSame($activated['lastManualActivationAt'], $listed['lastManualActivationAt']);
    }

    public function test_public_otp_login_does_not_create_or_refresh_manual_activation_history(): void
    {
        $jwt = Mockery::mock(PublicJwtService::class);
        $jwt->shouldReceive('createAccessToken')->twice()->andReturn('test-access-token');
        $this->instance(PublicJwtService::class, $jwt);
        $otp = Mockery::mock(OtpService::class);
        $otp->shouldReceive('verifyOtp')->twice()->andReturn(true);
        $this->instance(OtpService::class, $otp);
        foreach ([null, '2022-01-01T12:00:00.123456Z'] as $history) {
            $id = $this->user(['last_manual_activation_at' => $history]);
            $before = $this->storedInstant($id);
            $phone = DB::table('users')->where('id', $id)->value('mobile');
            $this->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'otp' => '123456'])->assertOk();
            $this->assertSame($before, $this->storedInstant($id));
            $this->assertNotNull(DB::table('users')->where('id', $id)->value('last_login_at'));
        }
    }

    public function test_registration_date_filter_uses_inclusive_tehran_start_and_exclusive_next_day_utc_boundary(): void
    {
        $before = $this->user(['created_at' => '2026-10-17 20:29:59']);
        $start = $this->user(['created_at' => '2026-10-17 20:30:00']);
        $middle = $this->user(['created_at' => '2026-10-18 10:00:00']);
        $last = $this->user(['created_at' => '2026-10-18 20:29:59']);
        $after = $this->user(['created_at' => '2026-10-18 20:30:00']);
        $this->user(['created_at' => '2026-10-18 10:00:00', 'is_admin' => true]);
        $filtered = $this->listing(['startDate' => '2026-10-18', 'endDate' => '2026-10-18']);
        $this->assertSame([$last, $middle, $start], array_column($filtered['data'], 'id'));
        $this->assertSame(3, $filtered['meta']['total']);
        $this->assertSame([$after, $last, $middle, $start], array_column($this->listing(['startDate' => '2026-10-18'])['data'], 'id'));
        $this->assertSame([$last, $middle, $start, $before], array_column($this->listing(['endDate' => '2026-10-18'])['data'], 'id'));
        $this->assertSame(5, $this->listing()['meta']['total']);
    }

    public function test_filters_combine_with_search_and_pagination_before_total_and_page_slicing(): void
    {
        $ids = [];
        foreach (['10:00:00', '11:00:00', '12:00:00'] as $time) $ids[] = $this->user(['created_at' => '2026-10-18 '.$time]);
        $this->user(['created_at' => '2026-10-18 13:00:00', 'name' => 'نام دیگر', 'username' => 'unrelated-'.bin2hex(random_bytes(8))]);
        $this->user(['created_at' => '2026-10-19 13:00:00']);
        $page = $this->listing(['startDate' => '2026-10-18', 'endDate' => '2026-10-18', 'pageSize' => 1, 'page' => 2]);
        $this->assertSame([$ids[1]], array_column($page['data'], 'id'));
        $this->assertSame(3, $page['meta']['total']);
        $this->assertSame(3, $page['meta']['last_page']);
        $this->assertSame(2, $page['meta']['current_page']);
    }

    public function test_historical_tehran_dst_midnight_gap_does_not_include_an_hour_of_the_following_day(): void
    {
        $first = $this->user(['created_at' => '2022-03-21 20:30:00']);
        $last = $this->user(['created_at' => '2022-03-22 19:29:59']);
        $this->user(['created_at' => '2022-03-22 19:30:00']); // Next Tehran day starts here.
        $this->user(['created_at' => '2022-03-21 20:29:59']);
        $result = $this->listing(['startDate' => '2022-03-22', 'endDate' => '2022-03-22']);
        $this->assertSame([$last, $first], array_column($result['data'], 'id'));
        $this->assertSame(2, $result['meta']['total']);
    }

    public function test_earliest_supported_calendar_date_has_valid_postgresql_utc_bounds(): void
    {
        $id = $this->user();
        $startOnly = $this->listing(['startDate' => '0001-01-02']);
        $this->assertSame([$id], array_column($startOnly['data'], 'id'));
        $this->assertSame(0, $this->listing(['endDate' => '0001-01-02'])['meta']['total']);
        $this->assertSame(0, $this->listing(['startDate' => '0001-01-02', 'endDate' => '0001-01-02'])['meta']['total']);
    }

    public function test_admin_accounts_are_excluded_from_details_and_status_updates(): void
    {
        $id = $this->user(['is_admin' => true, 'status' => 'disabled', 'is_active' => false]);
        $this->getJson('/api/v1/admin/users/'.$id)->assertNotFound();
        $this->patchJson('/api/v1/admin/users/'.$id.'/status', ['status' => 'active'])->assertNotFound();
        $this->assertNull($this->storedInstant($id));
        $this->assertSame(0, $this->listing()['meta']['total']);
    }

    public function test_invalid_filter_range_is_rejected_in_real_api_and_schema_rollback_preserves_user_state(): void
    {
        $id = $this->user(['status' => 'disabled', 'is_active' => false]);
        $this->updateUserStatus($id, 'active');
        $this->getJson('/api/v1/admin/users?startDate=2026-10-19&endDate=2026-10-18')
            ->assertUnprocessable()->assertJsonValidationErrors('endDate');
        $fields = ['id', 'created_at', 'status', 'is_active', 'locked_until', 'last_login_at'];
        $before = DB::table('users')->where('id', $id)->first($fields);
        $this->migration()->down();
        $this->migration()->up();
        $this->assertEquals($before, DB::table('users')->where('id', $id)->first($fields));
        $this->assertNull($this->storedInstant($id));
    }
}
