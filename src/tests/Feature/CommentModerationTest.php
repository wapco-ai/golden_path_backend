<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminSectionAccess;
use App\Services\JwtService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommentModerationTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL/PostGIS.');
        }

        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->withoutMiddleware(AdminSectionAccess::class);
        config(['app.jwt_secret' => 'isolated-comment-moderation-test-secret']);
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_admin_sees_the_place_and_public_list_only_shows_approved_comments(): void
    {
        DB::table('routing_floors')->insertOrIgnore(['floor' => 0, 'label' => 'همکف', 'sort_order' => 0]);
        $poiId = DB::selectOne("INSERT INTO poi_points(geom, poi_type, floor, has_content)
            VALUES (ST_SetSRID(ST_MakePoint(500005,4000005),32640), 'landmark', 0, true) RETURNING id")->id;
        $placeTitle = 'رواق آزمایشی';
        DB::table('i18n_texts')->insert([
            'entity_table' => 'poi_points', 'entity_id' => $poiId, 'field' => 'name',
            'lang' => 'fa', 'txt' => $placeTitle,
        ]);

        $adminId = DB::table('users')->insertGetId([
            'name' => 'Moderation test', 'username' => 'moderation-'.bin2hex(random_bytes(6)),
            'mobile' => '09'.random_int(100000000, 999999999), 'is_admin' => true,
            'status' => 'active', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $adminHeaders = ['Authorization' => 'Bearer '.app(JwtService::class)->createAccessToken(['sub' => $adminId])];
        $target = ['targetType' => 'poi', 'targetId' => $poiId, 'lang' => 'fa'];
        $publicUrl = '/api/v1/feedbacks?'.http_build_query($target);
        $body = 'دیدگاه آزمایشی بدون امتیاز';
        $id = $this->postJson('/api/v1/feedbacks', $target + ['body' => $body])
            ->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $this->getJson($publicUrl)->assertOk()->assertJsonCount(0, 'feedbacks.data');

        $adminUrl = '/api/v1/admin/comments';
        $this->getJson($adminUrl.'?postId='.$poiId, $adminHeaders)->assertOk()
            ->assertJsonPath('data.0.postTitle', $placeTitle)->assertJsonPath('data.0.postId', $poiId);
        $this->getJson($adminUrl.'/'.$id, $adminHeaders)->assertOk()
            ->assertJsonPath('postTitle', $placeTitle)->assertJsonPath('status', 'pending');
        $this->patchJson($adminUrl.'/'.$id.'/status', ['status' => 'approved'], $adminHeaders)->assertOk();

        $this->getJson($publicUrl)->assertOk()->assertJsonPath('feedbacks.pagination.total', 1)
            ->assertJsonPath('feedbacks.data.0.body', $body)->assertJsonPath('summary.ratingCount', 0);
        $this->patchJson($adminUrl.'/'.$id.'/status', ['status' => 'rejected'], $adminHeaders)->assertOk();
        $this->getJson($publicUrl)->assertOk()->assertJsonCount(0, 'feedbacks.data');
    }
}
