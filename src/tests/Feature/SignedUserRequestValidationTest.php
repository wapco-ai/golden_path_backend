<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Http\Resources\Admin\SignedUserResource;
use App\Models\User;
use App\Services\Admin\SignedUserService;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SignedUserRequestValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(AdminAuth::class);
    }

    private function service(): \Mockery\MockInterface
    {
        $service = Mockery::mock(SignedUserService::class);
        $this->instance(SignedUserService::class, $service);
        return $service;
    }

    public static function invalidFilters(): array
    {
        return [
            [['startDate' => '2026-02-30'], 'startDate'],
            [['endDate' => '2026-2-01'], 'endDate'],
            [['startDate' => '2026-10-18T00:00:00Z'], 'startDate'],
            [['startDate' => '۲۰۲۶-۱۰-۱۸'], 'startDate'],
            [['startDate' => '2026-10-19', 'endDate' => '2026-10-18'], 'endDate'],
            [['endDate' => ['2026-10-18']], 'endDate'],
            [['pageSize' => 101], 'pageSize'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_dates_ranges_and_page_sizes_fail_before_repository_access(array $query, string $field): void
    {
        $this->service()->shouldNotReceive('paginate');
        $this->getJson('/api/v1/admin/users?'.http_build_query($query))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_valid_filters_search_and_pagination_reach_service_together(): void
    {
        $this->service()->shouldReceive('paginate')->once()->with(2, 2, 'abc', '2026-10-18', '2026-10-18')
            ->andReturn(new LengthAwarePaginator([], 5, 2, 2));
        $this->getJson('/api/v1/admin/users?'.http_build_query([
            'page' => 2, 'pageSize' => 2, 'search' => 'abc', 'startDate' => '2026-10-18', 'endDate' => '2026-10-18',
        ]))->assertOk()->assertJsonPath('meta.total', 5)->assertJsonPath('meta.current_page', 2);
    }

    public static function optionalBounds(): array
    {
        return [
            [[], null, null],
            [['startDate' => '2026-10-18'], '2026-10-18', null],
            [['endDate' => '2026-10-18'], null, '2026-10-18'],
        ];
    }

    #[DataProvider('optionalBounds')]
    public function test_date_bounds_remain_optional(array $query, ?string $start, ?string $end): void
    {
        $this->service()->shouldReceive('paginate')->once()->with(1, 20, null, $start, $end)
            ->andReturn(new LengthAwarePaginator([], 0, 20));
        $this->getJson('/api/v1/admin/users?'.http_build_query($query))->assertOk();
    }

    public function test_status_payload_cannot_supply_the_manual_activation_timestamp(): void
    {
        $user = new User(['name' => 'کاربر', 'status' => 'active', 'is_active' => true]);
        $user->id = 7;
        $user->last_manual_activation_at = '2022-01-01T12:00:00Z';
        $this->service()->shouldReceive('updateStatus')->once()->with(7, 'active')->andReturn($user);
        $this->patchJson('/api/v1/admin/users/7/status', [
            'status' => 'active', 'lastManualActivationAt' => '2035-01-01T00:00:00Z',
            'last_manual_activation_at' => '2035-01-01T00:00:00Z',
        ])->assertOk()->assertJsonPath('lastManualActivationAt', '2022-01-01T12:00:00.000000Z');
    }

    public function test_resource_emits_explicit_null_or_utc_instant_without_changing_login_fields(): void
    {
        $user = new User(['name' => 'کاربر']);
        $this->assertNull((new SignedUserResource($user))->toArray(request())['lastManualActivationAt']);
        // Hydrated timestamptz retains its zone and precision; writing through
        // Eloquent's generic naive date setter is intentionally not used here.
        $user->setRawAttributes($user->getAttributes() + ['last_manual_activation_at' => '2026-10-18T18:30:12.123456+03:30']);
        $user->last_login_at = '2026-10-17T12:00:00Z';
        $payload = (new SignedUserResource($user))->toArray(request());
        $this->assertSame('2026-10-18T15:00:12.123456Z', $payload['lastManualActivationAt']);
        $this->assertSame('2026-10-17T12:00:00.000000Z', $payload['lastLoginAt']);
    }
}
