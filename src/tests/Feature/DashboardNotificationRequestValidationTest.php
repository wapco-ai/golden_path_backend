<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\User;
use App\Services\Admin\DashboardService;
use App\Services\JwtService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real routes and request validation, with no database access. */
class DashboardNotificationRequestValidationTest extends TestCase
{
    private const ENDPOINT = '/api/v1/admin/dashboard/notifications';
    private const ADMIN_ID = 73;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([AdminAuth::class, \App\Http\Middleware\AdminSectionAccess::class]);

        $admin = new User;
        $admin->id = self::ADMIN_ID;
        $admin->is_admin = true;
        $admin->status = 'active';
        $this->actingAs($admin);
    }

    private function service(): \Mockery\MockInterface
    {
        $service = Mockery::mock(DashboardService::class);
        $this->instance(DashboardService::class, $service);
        return $service;
    }

    public function test_default_feed_preserves_the_bare_array_and_uses_the_authenticated_admin(): void
    {
        $items = [[
            'id' => 'new_comment:7', 'type' => 'new_comment', 'entityId' => 7,
            'title' => 'Comment', 'message' => 'New comment',
            'createdAt' => '2026-10-18T12:00:00Z', 'read' => false,
        ]];
        $this->service()->shouldReceive('notifications')->once()
            ->with(self::ADMIN_ID, 10, false, false)->andReturn($items);

        $this->getJson(self::ENDPOINT.'?adminUserId=999&admin_user_id=999')
            ->assertOk()->assertExactJson($items);
    }

    public static function acceptedFlags(): array
    {
        return [
            'string true' => ['true', true],
            'string false' => ['false', false],
            'string one' => ['1', true],
            'string zero' => ['0', false],
            'integer one' => [1, true],
            'integer zero' => [0, false],
            'literal true' => [true, true],
            'literal false' => [false, false],
        ];
    }

    #[DataProvider('acceptedFlags')]
    public function test_both_boolean_flags_accept_only_the_documented_boolean_forms(mixed $input, bool $expected): void
    {
        $this->service()->shouldReceive('notifications')->once()
            ->with(self::ADMIN_ID, 10, $expected, $expected)->andReturn([]);

        $this->call('GET', self::ENDPOINT, [
            'unreadOnly' => $input, 'includeMeta' => $input,
        ], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertOk()->assertExactJson([]);
    }

    public static function independentFlags(): array
    {
        return [
            'unread filtering only' => [['unreadOnly' => 'true'], true, false],
            'metadata only' => [['includeMeta' => 'true'], false, true],
        ];
    }

    #[DataProvider('independentFlags')]
    public function test_unread_filtering_and_metadata_are_independent(array $query, bool $unreadOnly, bool $includeMeta): void
    {
        $this->service()->shouldReceive('notifications')->once()
            ->with(self::ADMIN_ID, 10, $unreadOnly, $includeMeta)->andReturn([]);

        $this->getJson(self::ENDPOINT.'?'.http_build_query($query))->assertOk()->assertExactJson([]);
    }

    public static function acceptedLimits(): array
    {
        return [[1, 1], ['1', 1], [50, 50], ['50', 50]];
    }

    #[DataProvider('acceptedLimits')]
    public function test_limit_boundaries_are_valid_and_forwarded_as_integers(mixed $input, int $expected): void
    {
        $this->service()->shouldReceive('notifications')->once()
            ->with(self::ADMIN_ID, $expected, false, false)->andReturn([]);

        $this->call('GET', self::ENDPOINT, ['limit' => $input], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ])->assertOk()->assertExactJson([]);
    }

    public static function invalidQueries(): array
    {
        $cases = [
            'zero limit' => [['limit' => 0], 'limit'],
            'negative limit' => [['limit' => -1], 'limit'],
            'oversized limit' => [['limit' => 51], 'limit'],
            'fractional limit' => [['limit' => 1.5], 'limit'],
            'noninteger limit' => [['limit' => 'ten'], 'limit'],
            'array limit' => [['limit' => [10]], 'limit'],
        ];
        foreach (['unreadOnly', 'includeMeta'] as $flag) {
            foreach ([
                'unrecognized' => 'invalid', 'yes' => 'yes', 'no' => 'no',
                'on' => 'on', 'off' => 'off', 'two' => 2, 'fractional' => 0.5,
                'empty' => '', 'null' => null, 'array' => ['true'],
            ] as $label => $value) {
                $cases[$flag.' '.$label] = [[$flag => $value], $flag];
            }
        }
        return $cases;
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_query_values_fail_before_service_access(array $query, string $field): void
    {
        $this->service()->shouldNotReceive('notifications');

        $this->call('GET', self::ENDPOINT, $query, [], [], ['HTTP_ACCEPT' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_mark_read_accepts_all_types_and_the_signed_bigint_boundary_with_server_identity(): void
    {
        $ids = ['new_user:1', 'new_comment:9223372036854775807', 'support_message:67'];
        $result = ['success' => true, 'unreadCount' => 3];
        $this->service()->shouldReceive('markNotificationsRead')->once()
            ->with(self::ADMIN_ID, $ids)->andReturn($result);

        $this->postJson(self::ENDPOINT.'/read', [
            'ids' => $ids, 'adminUserId' => 999, 'admin_user_id' => 999, 'user_id' => 999,
        ])->assertOk()->assertExactJson($result);
    }

    public function test_mark_read_accepts_a_full_batch_of_fifty_distinct_ids(): void
    {
        $ids = array_map(fn (int $id) => 'new_user:'.$id, range(1, 50));
        $result = ['success' => true, 'unreadCount' => 0];
        $this->service()->shouldReceive('markNotificationsRead')->once()
            ->with(self::ADMIN_ID, $ids)->andReturn($result);

        $this->postJson(self::ENDPOINT.'/read', ['ids' => $ids])->assertOk()->assertExactJson($result);
    }

    public static function invalidReadBodies(): array
    {
        return [
            'missing ids' => [[], 'ids'],
            'null ids' => [['ids' => null], 'ids'],
            'empty batch' => [['ids' => []], 'ids'],
            'string batch' => [['ids' => 'new_user:1'], 'ids'],
            'numeric batch' => [['ids' => 1], 'ids'],
            'object batch' => [['ids' => ['first' => 'new_user:1']], 'ids'],
            'numeric id' => [['ids' => [1000000001]], 'ids.0'],
            'boolean id' => [['ids' => [true]], 'ids.0'],
            'null id' => [['ids' => [null]], 'ids.0'],
            'nested id' => [['ids' => [['new_user:1']]], 'ids.0'],
            'legacy numeric string' => [['ids' => ['1000000001']], 'ids.0'],
            'zero entity' => [['ids' => ['new_user:0']], 'ids.0'],
            'negative entity' => [['ids' => ['new_comment:-1']], 'ids.0'],
            'leading zero entity' => [['ids' => ['support_message:01']], 'ids.0'],
            'unknown type' => [['ids' => ['new_message:1']], 'ids.0'],
            'uppercase type' => [['ids' => ['NEW_USER:1']], 'ids.0'],
            'missing entity' => [['ids' => ['new_user:']], 'ids.0'],
            'fractional entity' => [['ids' => ['new_user:1.5']], 'ids.0'],
            'scientific entity' => [['ids' => ['new_user:1e3']], 'ids.0'],
            'explicit positive sign' => [['ids' => ['new_user:+1']], 'ids.0'],
            'non ASCII digits' => [['ids' => ['new_user:۱']], 'ids.0'],
            'too large signed bigint' => [['ids' => ['new_comment:9223372036854775808']], 'ids.0'],
            'twenty digit entity' => [['ids' => ['support_message:10000000000000000000']], 'ids.0'],
            'oversized identifier' => [['ids' => ['new_user:'.str_repeat('9', 200)]], 'ids.0'],
            'duplicates' => [['ids' => ['new_user:1', 'new_user:1']], 'ids.0'],
            'oversized batch' => [[
                'ids' => array_map(fn (int $id) => 'new_user:'.$id, range(1, 51)),
            ], 'ids'],
        ];
    }

    #[DataProvider('invalidReadBodies')]
    public function test_invalid_read_batches_fail_before_service_side_effects(array $body, string $field): void
    {
        $this->service()->shouldNotReceive('markNotificationsRead');

        $this->postJson(self::ENDPOINT.'/read', $body)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function readAllBodies(): array
    {
        return [
            'omitted body' => [null],
            'empty body' => [[]],
            'untrusted identity' => [[
                'adminUserId' => 999, 'admin_user_id' => 999, 'user_id' => 999,
            ]],
        ];
    }

    #[DataProvider('readAllBodies')]
    public function test_read_all_uses_only_the_authenticated_admin_with_an_optional_body(?array $body): void
    {
        $result = ['success' => true, 'unreadCount' => 0];
        $this->service()->shouldReceive('markAllNotificationsRead')->once()
            ->with(self::ADMIN_ID)->andReturn($result);

        $response = $body === null
            ? $this->call('POST', self::ENDPOINT.'/read-all', [], [], [], ['HTTP_ACCEPT' => 'application/json'])
            : $this->postJson(self::ENDPOINT.'/read-all', $body);
        $response->assertOk()->assertExactJson($result);
    }

    public static function authenticatedRoutes(): array
    {
        return [['GET', ''], ['POST', '/read'], ['POST', '/read-all']];
    }

    #[DataProvider('authenticatedRoutes')]
    public function test_notification_routes_reject_a_missing_bearer_token_before_service_access(string $method, string $suffix): void
    {
        $this->withMiddleware(AdminAuth::class);
        $this->instance(JwtService::class, Mockery::mock(JwtService::class));
        $service = $this->service();
        $service->shouldNotReceive('notifications');
        $service->shouldNotReceive('markNotificationsRead');
        $service->shouldNotReceive('markAllNotificationsRead');

        $this->json($method, self::ENDPOINT.$suffix, ['ids' => ['new_user:1']])
            ->assertUnauthorized()->assertJsonPath('error', 'UNAUTHORIZED');
    }
}
