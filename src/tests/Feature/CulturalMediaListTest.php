<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Integration coverage for mixed media and unchanged persisted JSON. */
class CulturalMediaListTest extends TestCase
{
    private bool $transactionStarted = false;
    private string $marker;
    private int $poiId;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL/PostGIS database.');
        }
        DB::beginTransaction();
        $this->transactionStarted = true;
        (require base_path('database/migrations/2026_10_18_160000_add_cultural_content_created_at.php'))->up();
        $this->withoutMiddleware([AdminAuth::class, \App\Http\Middleware\AdminSectionAccess::class]);
        $this->marker = 'mixed-cultural-media-'.bin2hex(random_bytes(6));
        $this->poiId = (int) DB::selectOne(
            "INSERT INTO poi_points(geom,poi_type,floor,has_content,attrs)
             VALUES (ST_SetSRID(ST_MakePoint(500005,4000005),32640),'other',0,true,'{}'::jsonb) RETURNING id"
        )->id;
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) DB::rollBack();
        parent::tearDown();
    }

    private function content(string $lang, array $media): void
    {
        DB::table('contents')->insert([
            'poi_id' => $this->poiId, 'lang' => $lang, 'title' => $this->marker,
            'body' => 'متن فرهنگی', 'media' => json_encode($media), 'created_at' => '2026-10-18 12:00:00+00',
        ]);
    }

    private function listed(): array
    {
        $items = $this->getJson('/api/v1/cultural-items?'.http_build_query(['search' => $this->marker]))
            ->assertOk()->json('items');
        $item = collect($items)->firstWhere('poiId', $this->poiId);
        $this->assertNotNull($item);

        return $item;
    }

    public function test_video_audio_and_document_cannot_become_primary_image_and_all_media_are_retained(): void
    {
        $media = [
            ['type' => 'video', 'mime' => 'video/mp4', 'url' => '/storage/movie.mp4', 'isPrimary' => true],
            ['type' => 'audio', 'mime' => 'audio/mpeg', 'url' => '/storage/voice.mp3'],
            ['mime' => 'application/pdf', 'url' => '/storage/guide.pdf'],
            ['type' => 'image', 'mime' => 'image/jpeg', 'url' => '/storage/photo.jpg'],
        ];
        $this->content('fa', $media);
        $this->assertSame('/storage/photo.jpg', $this->listed()['primaryImage']);
        $this->assertEquals($media, $this->listed()['media']);
    }

    public function test_marked_primary_photo_is_preferred_and_legacy_src_photo_still_works(): void
    {
        $this->content('fa', [
            ['url' => '/storage/first.jpg'],
            ['src' => '/storage/chosen.PNG?version=1', 'isPrimary' => true],
        ]);
        $this->assertSame('/storage/chosen.PNG?version=1', $this->listed()['primaryImage']);
    }

    public function test_item_with_only_movie_has_no_primary_photo(): void
    {
        $media = [['mime' => 'video/mp4', 'url' => '/storage/movie.mp4']];
        $this->content('fa', $media);
        $this->assertNull($this->listed()['primaryImage']);
        $this->assertEquals($media, $this->listed()['media']);
    }

    public function test_unknown_or_conflicting_media_cannot_become_a_photo(): void
    {
        $media = [
            ['mime' => 'application/octet-stream', 'url' => '/storage/unknown.jpg'],
            ['type' => 'image', 'mime' => 'application/pdf', 'url' => '/storage/not-a-photo.jpg'],
            ['type' => 'image', 'url' => 'data:video/mp4;base64,dmlkZW8='],
            ['type' => 'image', 'url' => '/storage/not-a-photo.mp3'],
            ['url' => '/storage/unknown'],
        ];
        $this->content('fa', $media);
        $this->assertNull($this->listed()['primaryImage']);
        $this->assertEquals($media, $this->listed()['media']);
    }

    public function test_legacy_string_and_base64_photo_sources_are_supported(): void
    {
        $this->content('fa', [
            '/storage/string-photo.jpg',
            ['mime' => 'image/png', 'data' => 'aW1hZ2U=', 'isPrimary' => true],
        ]);
        $this->assertSame('data:image/png;base64,aW1hZ2U=', $this->listed()['primaryImage']);
    }

    public function test_photo_in_next_language_does_not_discard_preferred_language_media(): void
    {
        $fa = [['type' => 'video', 'url' => '/storage/persian.mp4']];
        $this->content('fa', $fa);
        $this->content('en', [['mime' => 'image/png', 'path' => '/storage/english.png']]);
        $this->assertSame('/storage/english.png', $this->listed()['primaryImage']);
        $this->assertEquals($fa, $this->listed()['media']);
    }

    public function test_edit_does_not_reject_or_rewrite_existing_video_metadata_or_dates(): void
    {
        $legacy = [[
            'type' => 'video', 'mime' => 'video/webm', 'url' => '/storage/old.webm',
            'title' => 'فیلم قدیمی', 'description' => 'توضیح',
            'languageTitles' => ['fa' => 'عنوان', 'en' => 'Title'], 'custom' => ['keep' => true],
        ]];
        $this->content('fa', $legacy);
        $item = $this->putJson('/api/v1/cultural-items/'.$this->poiId, [
            'poi_id' => $this->poiId,
            'translations' => ['fa' => ['title' => $this->marker, 'body' => 'ویرایش متن', 'media' => $legacy]],
        ])->assertOk()->json();
        $this->assertEquals($legacy, $item['media']['fa']);
        $this->assertSame('2026-10-18T12:00:00+00:00', $item['createdAt']);
        $this->assertNull($this->listed()['primaryImage']);
        $this->assertEquals($legacy, json_decode(DB::table('contents')->where('poi_id', $this->poiId)->value('media'), true));
    }
}
