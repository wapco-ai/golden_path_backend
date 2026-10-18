<?php

namespace Tests\Feature;

use App\Services\JwtService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Real PostgreSQL source rows, receipt persistence and admin JWT authorization. */
class DashboardNotificationAdminTest extends TestCase
{
    private const ENDPOINT = '/api/v1/admin/dashboard/notifications';

    private bool $transactionStarted = false;
    private string $marker;
    private int $admin;
    private int $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL/PostGIS database.');
        }
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->marker = 'dashboard-notifications-'.bin2hex(random_bytes(6));
        config(['app.jwt_secret' => 'dashboard-notification-isolated-test-secret']);
        $this->admin = $this->user(['is_admin' => true]);
        $this->otherAdmin = $this->user(['is_admin' => true]);
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
            'created_at' => '2099-10-18 10:00:00', 'updated_at' => '2099-10-18 10:00:00',
        ]);
    }

    private function support(array $values = []): int
    {
        return (int) DB::table('support_feedbacks')->insertGetId($values + [
            'user_id' => null, 'subject' => $this->marker, 'message' => 'پیام پشتیبانی',
            'status' => 'new', 'meta' => '{}',
            'created_at' => '2099-10-18T10:00:00Z', 'updated_at' => '2099-10-18T10:00:00Z',
        ]);
    }

    private function comment(int $user, array $values = []): int
    {
        return (int) DB::table('user_feedbacks')->insertGetId($values + [
            'user_id' => $user, 'target_type' => 'content', 'target_id' => 1, 'lang' => 'fa',
            'title' => $this->marker, 'body' => 'متن دیدگاه', 'status' => 'pending',
            'created_at' => '2099-10-18T10:00:00Z', 'updated_at' => '2099-10-18T10:00:00Z',
        ]);
    }

    private function authenticate(int $admin): void
    {
        $token = app(JwtService::class)->createAccessToken(['sub' => $admin, 'role' => 'ADMIN']);
        $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function feed(array $query = []): array
    {
        return $this->getJson(self::ENDPOINT.'?'.http_build_query($query))
            ->assertOk()->json();
    }

    private function read(array $ids, array $extra = []): array
    {
        return $this->postJson(self::ENDPOINT.'/read', ['ids' => $ids] + $extra)
            ->assertOk()->assertJsonPath('success', true)->json();
    }

    private function receipts(int $admin): array
    {
        return DB::table('admin_notification_reads')->where('admin_user_id', $admin)
            ->orderBy('notification_type')->orderBy('entity_id')->get()->toArray();
    }

    private function sources(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toArray(), [
            'users', 'user_feedbacks', 'support_feedbacks',
        ]);
    }

    public function test_reads_persist_per_authenticated_admin_and_retries_preserve_original_read_time(): void
    {
        $id = 'support_message:'.$this->support();
        $before = $this->feed(['includeMeta' => 'true']);
        $this->read([$id], ['admin_user_id' => $this->otherAdmin, 'adminUserId' => $this->otherAdmin]);
        $receipt = $this->receipts($this->admin);
        $this->assertCount(1, $receipt);
        $this->assertNotEmpty($receipt[0]->read_at);
        $this->assertSame([], $this->receipts($this->otherAdmin));

        // A subsequent HTTP request reads persisted state, without a local read set.
        $this->authenticate($this->admin);
        $after = $this->feed(['includeMeta' => 'true']);
        $this->assertSame($before['unreadCount'] - 1, $after['unreadCount']);
        $items = array_column($after['items'], null, 'id');
        $this->assertTrue($items[$id]['read']);
        $this->read([$id]);
        $this->assertEquals($receipt, $this->receipts($this->admin));

        $this->authenticate($this->otherAdmin);
        $other = $this->feed(['includeMeta' => 'true']);
        $this->assertSame($before['unreadCount'], $other['unreadCount']);
        $this->assertFalse(array_column($other['items'], null, 'id')[$id]['read']);
    }

    public function test_batch_acknowledges_only_explicit_existing_type_and_entity_pairs(): void
    {
        $user = $this->user();
        $comment = $this->comment($user);
        $support = $this->support();
        $notSelected = $this->support();
        $missing = 'support_message:9223372036854775807';
        $before = $this->feed(['includeMeta' => 'true'])['unreadCount'];
        $result = $this->read(['new_comment:'.$comment, 'support_message:'.$support, $missing]);
        $this->assertSame($before - 2, $result['unreadCount']);
        $this->assertSame([
            ['new_comment', $comment], ['support_message', $support],
        ], array_map(fn ($row) => [$row->notification_type, (int) $row->entity_id], $this->receipts($this->admin)));
        $unread = array_column($this->feed(['unreadOnly' => 'true', 'limit' => 50]), 'id');
        $this->assertContains('new_user:'.$user, $unread);
        $this->assertContains('support_message:'.$notSelected, $unread);
        $this->assertNotContains('new_comment:'.$comment, $unread);
        $this->assertNotContains('support_message:'.$support, $unread);
    }

    public function test_deleted_and_not_yet_existing_ids_create_no_receipt_for_future_rows(): void
    {
        $deleted = $this->support();
        DB::table('support_feedbacks')->where('id', $deleted)->delete();
        $this->read(['support_message:'.$deleted]);
        $this->assertSame([], $this->receipts($this->admin));

        // The same ID becomes eligible later, without inheriting a premature read.
        $this->support(['id' => $deleted]);
        $items = array_column($this->feed(), null, 'id');
        $this->assertFalse($items['support_message:'.$deleted]['read']);
    }

    public function test_unread_filter_runs_before_limit_and_count_covers_all_sources(): void
    {
        $older = $this->support(['created_at' => '2099-10-17T10:00:00Z']);
        $newer = $this->support(['created_at' => '2099-10-18T10:00:00Z']);
        $this->read(['support_message:'.$newer]);
        $response = $this->feed(['limit' => 1, 'unreadOnly' => 'true', 'includeMeta' => 'true']);
        $this->assertSame('support_message:'.$older, $response['items'][0]['id']);
        $this->assertGreaterThanOrEqual(1, $response['unreadCount']);

        for ($i = 0; $i < 55; $i++) {
            $this->support();
        }
        $response = $this->feed(['limit' => 10, 'includeMeta' => 'true']);
        $this->assertCount(10, $response['items']);
        $this->assertGreaterThan(50, $response['unreadCount']);
        $this->assertCount(50, $this->feed(['limit' => 50]));
    }

    public function test_read_all_is_one_insert_select_for_all_sources_and_new_rows_remain_unread_even_with_equal_or_older_time(): void
    {
        $user = $this->user();
        $this->comment($user, ['status' => 'approved']);
        for ($i = 0; $i < 55; $i++) {
            $this->support(['status' => 'closed']);
        }
        $count = $this->feed(['includeMeta' => 'true'])['unreadCount'];
        $this->assertGreaterThan(50, $count);
        $sources = $this->sources();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/INSERT\s+INTO\s+public\.admin_notification_reads/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $this->postJson(self::ENDPOINT.'/read-all', ['admin_user_id' => $this->otherAdmin])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('unreadCount', 0);
        $this->assertCount(1, $writes);
        $this->assertStringContainsString('SELECT ?::bigint, notification_type, entity_id FROM sources', $writes[0]);
        $receipts = $this->receipts($this->admin);
        $this->assertCount($count, $receipts);
        $this->assertEquals($sources, $this->sources());
        $this->assertSame([], $this->receipts($this->otherAdmin));
        $this->assertSame([], $this->feed(['unreadOnly' => 'true']));

        $this->postJson(self::ENDPOINT.'/read-all')->assertOk()->assertJsonPath('unreadCount', 0);
        $this->assertEquals($receipts, $this->receipts($this->admin));
        $equalClock = $this->support(['created_at' => $receipts[0]->read_at]);
        $older = $this->support(['created_at' => '2000-01-01T00:00:00Z']);
        $new = $this->feed(['unreadOnly' => 'true', 'includeMeta' => 'true']);
        $this->assertSame(2, $new['unreadCount']);
        $this->assertEqualsCanonicalizing([
            'support_message:'.$equalClock, 'support_message:'.$older,
        ], array_column($new['items'], 'id'));
    }

    public function test_timestamps_are_sorted_as_instants_and_formatted_in_utc_under_a_non_utc_session(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'Asia/Tehran'");
        $user = $this->user(['created_at' => '2099-10-18 09:00:00']);
        $comment = $this->comment($user, ['created_at' => '2099-10-18T13:30:00.123456+03:30']);
        $support = $this->support(['created_at' => '2099-10-18T11:00:00.123456Z']);
        $items = $this->feed();
        $this->assertSame([
            'support_message:'.$support, 'new_comment:'.$comment, 'new_user:'.$user,
        ], array_column(array_slice($items, 0, 3), 'id'));
        $this->assertSame('2099-10-18T11:00:00.123456Z', $items[0]['createdAt']);
        $this->assertSame('2099-10-18T10:00:00.123456Z', $items[1]['createdAt']);
        $this->assertSame('2099-10-18T09:00:00.000000Z', $items[2]['createdAt']);
    }

    public function test_equal_instants_have_stable_type_then_descending_entity_order(): void
    {
        $user = $this->user();
        $comment = $this->comment($user);
        $firstSupport = $this->support();
        $lastSupport = $this->support();
        $expected = ['new_comment:'.$comment, 'new_user:'.$user, 'support_message:'.$lastSupport, 'support_message:'.$firstSupport];
        $this->assertSame($expected, array_column(array_slice($this->feed(), 0, 4), 'id'));
        $this->assertSame($expected, array_column(array_slice($this->feed(), 0, 4), 'id'));
    }

    public function test_undated_users_and_admins_are_excluded_without_fake_new_timestamps(): void
    {
        $before = $this->feed(['includeMeta' => 'true'])['unreadCount'];
        $undated = $this->user(['created_at' => null]);
        $admin = $this->user(['is_admin' => true]);
        $dated = $this->user();
        $response = $this->feed(['includeMeta' => 'true']);
        $this->assertSame($before + 1, $response['unreadCount']);
        $ids = array_column($response['items'], 'id');
        $this->assertContains('new_user:'.$dated, $ids);
        $this->assertNotContains('new_user:'.$undated, $ids);
        $this->assertNotContains('new_user:'.$admin, $ids);
        $this->read(['new_user:'.$undated, 'new_user:'.$admin]);
        $this->assertSame([], $this->receipts($this->admin));
        $this->assertNull(DB::table('users')->where('id', $undated)->value('created_at'));
    }

    public function test_default_bare_array_and_opt_in_metadata_share_items_and_preserve_source_states(): void
    {
        $user = $this->user();
        $comment = $this->comment($user, ['status' => 'rejected', 'approved_at' => '2099-10-18T12:00:00Z']);
        $support = $this->support(['status' => 'seen']);
        $sources = $this->sources();
        $bare = $this->feed();
        $meta = $this->feed(['includeMeta' => 'true']);
        $this->assertIsList($bare);
        $this->assertSame($bare, $meta['items']);
        foreach ($bare as $item) {
            $this->assertMatchesRegularExpression('/\A(new_user|new_comment|support_message):[1-9][0-9]*\z/', $item['id']);
            $this->assertIsBool($item['read']);
        }
        $this->read(['new_comment:'.$comment, 'support_message:'.$support]);
        $this->assertEquals($sources, $this->sources());
        $this->assertSame('rejected', DB::table('user_feedbacks')->where('id', $comment)->value('status'));
        $this->assertSame('seen', DB::table('support_feedbacks')->where('id', $support)->value('status'));
    }

    public function test_all_notification_routes_require_an_active_admin_token(): void
    {
        $id = 'support_message:'.$this->support();
        foreach ([null, $this->user(), $this->user(['is_admin' => true, 'status' => 'disabled'])] as $actor) {
            if ($actor === null) {
                $this->withHeader('Authorization', '');
            } else {
                $this->authenticate($actor);
            }
            $status = $actor !== null && DB::table('users')->where('id', $actor)->value('status') === 'disabled' ? 423 : 401;
            $this->getJson(self::ENDPOINT)->assertStatus($status);
            $this->postJson(self::ENDPOINT.'/read', ['ids' => [$id]])->assertStatus($status);
            $this->postJson(self::ENDPOINT.'/read-all')->assertStatus($status);
        }
        $this->assertSame([], $this->receipts($this->admin));
        $this->assertSame([], $this->receipts($this->otherAdmin));
    }

    public function test_malformed_inputs_return_422_with_real_admin_auth_and_create_no_receipts(): void
    {
        $support = $this->support();
        $sources = $this->sources();
        foreach ([
            ['ids' => ['support_message:9223372036854775808']],
            ['ids' => ['support_message:0']],
            ['ids' => ['unknown:'.$support]],
            ['ids' => ['support_message:'.$support, 'support_message:'.$support]],
            ['ids' => array_map(fn ($id) => 'support_message:'.$id, range(1, 51))],
        ] as $body) {
            $this->postJson(self::ENDPOINT.'/read', $body)->assertUnprocessable();
        }
        $this->getJson(self::ENDPOINT.'?unreadOnly=invalid')->assertUnprocessable()->assertJsonValidationErrors('unreadOnly');
        $this->getJson(self::ENDPOINT.'?includeMeta[]=true')->assertUnprocessable()->assertJsonValidationErrors('includeMeta');
        $this->getJson(self::ENDPOINT.'?limit=51')->assertUnprocessable()->assertJsonValidationErrors('limit');
        $this->assertSame([], $this->receipts($this->admin));
        $this->assertEquals($sources, $this->sources());
    }

    public function test_receipts_are_removed_when_their_admin_is_deleted_without_touching_sources(): void
    {
        $support = $this->support();
        $this->read(['support_message:'.$support]);
        $this->assertCount(1, $this->receipts($this->admin));
        DB::table('users')->where('id', $this->admin)->delete();
        $this->assertSame([], $this->receipts($this->admin));
        $this->assertTrue(DB::table('support_feedbacks')->where('id', $support)->exists());
    }
}
