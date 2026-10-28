<?php

namespace Tests\Feature;

use App\Services\JwtService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real PostgreSQL and admin JWT middleware; no authentication bypass. */
class SupportFeedbackAdminTest extends TestCase
{
    private bool $transactionStarted = false;
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\AdminSectionAccess::class);
        if (config('database.default') !== 'pgsql') $this->markTestSkipped('Requires isolated PostgreSQL/PostGIS database.');
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->marker = 'support-admin-'.bin2hex(random_bytes(6));
        config(['app.jwt_secret' => 'support-feedback-isolated-test-secret']);
        $admin = $this->createUser(['is_admin' => true]);
        $this->withHeader('Authorization', 'Bearer '.$this->token($admin));
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) DB::rollBack();
        parent::tearDown();
    }

    private function createUser(array $values = []): int
    {
        return (int) DB::table('users')->insertGetId($values + [
            'name' => $this->marker, 'username' => $this->marker.'-'.bin2hex(random_bytes(4)),
            'mobile' => '09'.random_int(100000000, 999999999),
            'is_admin' => false, 'status' => 'active', 'is_active' => true,
            'created_at' => '2026-10-18 10:00:00', 'updated_at' => '2026-10-18 10:00:00',
        ]);
    }

    private function token(int $userId): string
    {
        return app(JwtService::class)->createAccessToken(['sub' => $userId, 'role' => 'ADMIN']);
    }

    private function feedback(array $values = []): int
    {
        return (int) DB::table('support_feedbacks')->insertGetId($values + [
            'user_id' => null, 'subject' => $this->marker, 'message' => 'متن بازخورد',
            'status' => 'new', 'meta' => '{}',
            'created_at' => '2026-10-18T10:00:00Z', 'updated_at' => '2026-10-18T10:00:00Z',
        ]);
    }

    private function listing(array $query = []): array
    {
        return $this->getJson('/api/v1/admin/support-feedbacks?'.http_build_query($query + ['q' => $this->marker]))
            ->assertOk()->json();
    }

    public function test_linked_sender_uses_mobile_as_phone_and_guest_keeps_null_user(): void
    {
        $user = $this->createUser(['name' => 'فرستنده', 'mobile' => '09123456789']);
        $linked = $this->feedback(['user_id' => $user]);
        $guest = $this->feedback();
        $payload = $this->listing();
        $this->assertSame([$guest, $linked], array_column($payload['data'], 'id'));
        $this->assertNull($payload['data'][0]['user']);
        $this->assertSame(['id' => $user, 'name' => 'فرستنده', 'phone' => '09123456789'], $payload['data'][1]['user']);
        $this->assertSame(2, $payload['pagination']['total']);
        $this->assertArrayHasKey('createdAt', $payload['data'][1]);
    }

    public function test_sender_without_mobile_retains_phone_key_with_null_value(): void
    {
        $user = $this->createUser(['mobile' => null]);
        $this->feedback(['user_id' => $user]);
        $this->assertArrayHasKey('phone', $this->listing()['data'][0]['user']);
        $this->assertNull($this->listing()['data'][0]['user']['phone']);
    }

    public function test_status_search_and_pagination_are_combined_with_stable_tie_ordering(): void
    {
        $first = $this->feedback(['status' => 'seen']);
        $second = $this->feedback(['status' => 'seen']);
        $third = $this->feedback(['status' => 'seen']);
        $this->feedback(['status' => 'new']);
        $this->feedback(['status' => 'seen', 'subject' => 'نام دیگر']);
        $page = $this->listing(['status' => 'seen', 'limit' => 1, 'page' => 2]);
        $this->assertSame([$second], array_column($page['data'], 'id'));
        $this->assertSame(['currentPage' => 2, 'lastPage' => 3, 'perPage' => 1, 'total' => 3], $page['pagination']);
        $this->assertSame([$third, $second, $first], array_column($this->listing(['status' => 'seen'])['data'], 'id'));
    }

    public function test_delete_physically_removes_only_selected_support_feedback_and_preserves_users_and_comments(): void
    {
        $user = $this->createUser();
        $target = $this->feedback(['user_id' => $user]);
        $other = $this->feedback(['user_id' => $user, 'status' => 'closed']);
        DB::table('user_feedbacks')->insert([
            'user_id' => $user, 'target_type' => 'content', 'target_id' => 1, 'lang' => 'fa',
            'body' => 'دیدگاه مستقل', 'status' => 'pending',
        ]);
        $usersBefore = DB::table('users')->orderBy('id')->get()->toArray();
        $commentsBefore = DB::table('user_feedbacks')->orderBy('id')->get()->toArray();
        $otherBefore = DB::table('support_feedbacks')->where('id', $other)->first();
        $this->deleteJson('/api/v1/admin/support-feedbacks/'.$target)->assertOk()
            ->assertJsonPath('success', true)->assertJsonPath('id', $target)->assertJsonPath('message', 'بازخورد حذف شد.');
        $this->assertFalse(DB::table('support_feedbacks')->where('id', $target)->exists());
        $this->assertEquals($otherBefore, DB::table('support_feedbacks')->where('id', $other)->first());
        $this->assertEquals($usersBefore, DB::table('users')->orderBy('id')->get()->toArray());
        $this->assertEquals($commentsBefore, DB::table('user_feedbacks')->orderBy('id')->get()->toArray());
        $this->assertSame([$other], array_column($this->listing()['data'], 'id'));
        $this->deleteJson('/api/v1/admin/support-feedbacks/'.$target)->assertNotFound()->assertJsonPath('success', false);
    }

    public function test_guest_feedback_can_be_deleted_and_existing_status_patch_still_works(): void
    {
        $id = $this->feedback();
        $this->patchJson('/api/v1/admin/support-feedbacks/'.$id, ['status' => 'in_progress'])->assertOk()
            ->assertJsonPath('id', $id)->assertJsonPath('status', 'in_progress');
        $this->deleteJson('/api/v1/admin/support-feedbacks/'.$id)->assertOk();
        $this->assertSame(0, $this->listing()['pagination']['total']);
    }

    public function test_deleting_the_last_item_of_a_page_updates_filtered_pagination_total(): void
    {
        $first = $this->feedback(['status' => 'seen']);
        $second = $this->feedback(['status' => 'seen']);
        $this->feedback(['status' => 'new']);
        $this->assertSame([$first], array_column($this->listing(['status' => 'seen', 'limit' => 1, 'page' => 2])['data'], 'id'));
        $this->deleteJson('/api/v1/admin/support-feedbacks/'.$first)->assertOk();
        $page = $this->listing(['status' => 'seen', 'limit' => 1, 'page' => 1]);
        $this->assertSame([$second], array_column($page['data'], 'id'));
        $this->assertSame(1, $page['pagination']['lastPage']);
        $this->assertSame(1, $page['pagination']['total']);
    }

    public static function invalidIds(): array
    {
        return [
            ['not-a-number'], ['-1'], ['0'], ['000'], ['1.5'], ['1e2'],
            ['9223372036854775807'], ['9223372036854775808'], ['999999999999999999999999999999999999999999'],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_nonexistent_malformed_and_out_of_range_ids_return_json_404_without_deleting_other_rows(string $id): void
    {
        $keep = $this->feedback();
        $this->delete('/api/v1/admin/support-feedbacks/'.$id)->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')->assertHeaderMissing('Location')->assertJsonPath('success', false);
        $this->assertTrue(DB::table('support_feedbacks')->where('id', $keep)->exists());
    }

    public function test_missing_token_and_nonadmin_real_token_cannot_delete_feedback(): void
    {
        $id = $this->feedback();
        $this->withHeader('Authorization', '')->deleteJson('/api/v1/admin/support-feedbacks/'.$id)->assertUnauthorized();
        $ordinaryUser = $this->createUser();
        $this->withHeader('Authorization', 'Bearer '.$this->token($ordinaryUser))
            ->deleteJson('/api/v1/admin/support-feedbacks/'.$id)->assertUnauthorized();
        $this->assertTrue(DB::table('support_feedbacks')->where('id', $id)->exists());
    }

    public function test_locked_or_disabled_admin_cannot_delete_feedback(): void
    {
        $id = $this->feedback();
        foreach ([['locked_until' => '2099-01-01T00:00:00Z'], ['status' => 'disabled']] as $state) {
            $admin = $this->createUser($state + ['is_admin' => true]);
            $this->withHeader('Authorization', 'Bearer '.$this->token($admin))
                ->deleteJson('/api/v1/admin/support-feedbacks/'.$id)->assertStatus(423);
        }
        $this->assertTrue(DB::table('support_feedbacks')->where('id', $id)->exists());
    }

    public function test_invalid_status_and_page_limit_validation_contract_remains_unchanged(): void
    {
        $this->getJson('/api/v1/admin/support-feedbacks?status=invalid')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/v1/admin/support-feedbacks?limit=101')->assertUnprocessable()->assertJsonValidationErrors('limit');
    }
}
