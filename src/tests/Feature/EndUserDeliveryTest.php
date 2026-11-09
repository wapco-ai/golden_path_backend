<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PublicJwtService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class EndUserDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => 'isolated-enduser-test-secret']);
        if (DB::getDriverName() === 'sqlite') {
            Schema::create('users', function (Blueprint $t) {
                $t->id(); $t->string('name')->nullable(); $t->string('mobile')->nullable();
                $t->boolean('is_admin')->default(false); $t->string('status')->default('active');
                $t->timestamp('locked_until')->nullable(); $t->timestamps();
            });
            Schema::create('support_feedbacks', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('user_id')->nullable();
                $t->uuid('request_id')->nullable()->unique(); $t->string('subject'); $t->text('message');
                $t->string('status'); $t->json('meta'); $t->timestamps();
            });
            Schema::create('user_feedbacks', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('user_id')->nullable(); $t->string('target_type');
                $t->unsignedBigInteger('target_id'); $t->string('lang'); $t->integer('rating')->nullable();
                $t->string('title')->nullable(); $t->text('body')->nullable();
                $t->string('status'); $t->text('admin_note')->nullable(); $t->timestamps();
                $t->unique(['user_id', 'target_type', 'target_id', 'lang']);
            });
        }
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function user(): User
    {
        $id = DB::table('users')->insertGetId(['name' => 'isolated test', 'mobile' => '09'.random_int(100000000, 999999999),
            'is_admin' => false, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        return User::findOrFail($id);
    }

    private function login(User $user): void
    {
        $this->withHeader('Authorization', 'Bearer '.app(PublicJwtService::class)->createAccessToken(['sub' => $user->id]));
    }

    private function emergency(array $overrides = []): array
    {
        return $overrides + ['requestId' => (string) Str::uuid(), 'type' => 'medical',
            'description' => 'isolated automated test', 'location' => null];
    }

    public function test_guest_emergency_is_durable_in_the_staff_inbox_and_retry_is_idempotent(): void
    {
        $payload = $this->emergency();
        $id = $this->postJson('/api/v1/emergencies', $payload)->assertCreated()->assertJsonPath('status', 'received')->json('id');
        $this->postJson('/api/v1/emergencies', $payload)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseHas('support_feedbacks', ['id' => $id, 'request_id' => $payload['requestId'], 'user_id' => null, 'status' => 'new']);
        $this->assertSame(1, DB::table('support_feedbacks')->where('request_id', $payload['requestId'])->count());
        $payload['description'] = 'different incident';
        $this->postJson('/api/v1/emergencies', $payload)->assertStatus(409);
    }

    public function test_logged_in_emergency_has_real_sender_and_location_in_existing_admin_message(): void
    {
        $user = $this->user(); $this->login($user);
        $payload = $this->emergency(['location' => ['lat' => 36.28, 'lng' => 59.61, 'accuracy' => 20,
            'source' => 'gps', 'timestamp' => now()->getTimestampMs(), 'floor' => 1]]);
        $id = $this->postJson('/api/v1/emergencies', $payload)->assertCreated()->json('id');
        $row = DB::table('support_feedbacks')->find($id);
        $this->assertEquals($user->id, $row->user_id);
        $this->assertStringContainsString('36.28, 59.61', $row->message);
        $this->assertSame('gps', json_decode($row->meta, true)['location']['source']);
        $this->getJson('/api/v1/support/feedbacks/me')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->travel(1)->minutes();
        $this->postJson('/api/v1/emergencies', $payload)->assertOk()->assertJsonPath('id', $id);
    }

    public function test_expired_login_is_not_silently_saved_as_a_guest(): void
    {
        $this->withHeader('Authorization', 'Bearer invalid');
        $this->postJson('/api/v1/emergencies', $this->emergency())->assertUnauthorized();
        $this->assertDatabaseCount('support_feedbacks', 0);
    }

    public function test_stale_imprecise_and_out_of_range_locations_are_rejected(): void
    {
        $base = ['lat' => 36.28, 'lng' => 59.61, 'source' => 'gps', 'accuracy' => 20, 'timestamp' => now()->getTimestampMs()];
        foreach ([['lat' => 91], ['accuracy' => -1], ['accuracy' => 30], ['timestamp' => now()->subMinute()->getTimestampMs()]] as $change) {
            $this->postJson('/api/v1/emergencies', $this->emergency(['location' => array_merge($base, $change)]))->assertStatus(422);
        }
        $this->assertDatabaseCount('support_feedbacks', 0);
    }

    public function test_guest_comments_do_not_impersonate_or_overwrite_any_account(): void
    {
        $payload = ['targetType' => 'poi', 'targetId' => 987654321, 'lang' => 'fa', 'body' => 'comment', 'rating' => null];
        $first = $this->postJson('/api/v1/feedbacks', $payload)->assertCreated()->json('id');
        $second = $this->postJson('/api/v1/feedbacks', $payload)->assertCreated()->json('id');
        $this->assertNotEquals($first, $second);
        $this->assertDatabaseHas('user_feedbacks', ['id' => $first, 'user_id' => null]);
        $this->assertDatabaseHas('user_feedbacks', ['id' => $second, 'user_id' => null]);
    }

    public function test_legacy_frontend_guest_token_is_anonymous_during_rollout(): void
    {
        $this->withHeader('Authorization', 'Bearer guest-feedback-12345678-1234-1234-1234-123456789012');
        $payload = ['targetType' => 'poi', 'targetId' => 987654321, 'lang' => 'fa', 'body' => 'legacy guest', 'rating' => 0];
        $id = $this->postJson('/api/v1/feedbacks', $payload)->assertCreated()->json('id');
        $this->assertDatabaseHas('user_feedbacks', ['id' => $id, 'user_id' => null, 'rating' => null]);
        $this->postJson('/api/v1/emergencies', $this->emergency())->assertUnauthorized();
    }

    public function test_only_authenticated_owner_updates_their_feedback(): void
    {
        $payload = ['targetType' => 'poi', 'targetId' => 987654321, 'lang' => 'fa', 'body' => 'first', 'rating' => 4];
        $firstUser = $this->user(); $this->login($firstUser);
        $first = $this->postJson('/api/v1/feedbacks', $payload)->assertCreated()->json('id');
        $payload['body'] = 'edited';
        $this->postJson('/api/v1/feedbacks', $payload)->assertCreated()->assertJsonPath('id', $first);
        $this->login($this->user());
        $second = $this->postJson('/api/v1/feedbacks', $payload)->assertCreated()->json('id');
        $this->assertNotEquals($first, $second);
        $this->assertDatabaseHas('user_feedbacks', ['id' => $first, 'user_id' => $firstUser->id, 'body' => 'edited']);
    }
}
