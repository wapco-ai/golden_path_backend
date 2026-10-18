<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Exercises the deployed SQL and the real public HTTP endpoint against PostgreSQL/PostGIS. */
class CulturalLandmarkMediaTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL/PostGIS database.');
        }
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_18_180000_select_cultural_landmark_images.php');
    }

    private function poi(mixed $media, array $attrs = []): int
    {
        $id = (int) DB::selectOne(
            "INSERT INTO poi_points(geom,poi_type,floor,has_content,attrs)
             VALUES (ST_SetSRID(ST_MakePoint(500005,4000005),32640),'landmark',-1,true,?::jsonb)
             RETURNING id",
            [json_encode((object) $attrs)]
        )->id;
        DB::table('i18n_texts')->insert([
            'entity_table' => 'poi_points', 'entity_id' => $id, 'field' => 'name',
            'lang' => 'fa', 'txt' => 'آزمون رسانه فرهنگی '.$id,
        ]);
        DB::table('contents')->insert([
            'poi_id' => $id, 'lang' => 'fa', 'title' => 'عنوان فرهنگی '.$id,
            'body' => 'متن بدون تغییر', 'media' => json_encode($media),
        ]);

        return $id;
    }

    private function place(int $id): array
    {
        return $this->getJson('/api/v1/landmark-places?'.http_build_query([
            'language' => 'fa', 'poi_id' => $id,
        ]))->assertOk()->assertJsonCount(1, 'places.landmarkPlaces')->json('places.landmarkPlaces.0');
    }

    public function test_video_first_keeps_complete_media_but_selects_the_real_image_in_sql_and_http(): void
    {
        $media = [
            ['type' => 'video', 'mime' => 'video/mp4', 'url' => '/storage/videos/cultural.mp4', 'name' => 'فیلم', 'metadata' => ['keep' => true]],
            ['type' => 'image', 'mime' => 'image/jpeg', 'url' => '/storage/images/cultural.jpg', 'name' => 'تصویر'],
            ['type' => 'audio', 'mime' => 'audio/mpeg', 'url' => '/storage/audios/cultural.mp3'],
            ['type' => 'file', 'mime' => 'application/pdf', 'url' => '/storage/files/cultural.pdf'],
        ];
        $id = $this->poi($media);
        $sql = json_decode(DB::selectOne(
            'SELECT public.fn_landmark_places_json(?::lang_enum, ?, NULL, NULL, ?, NULL, false) AS payload',
            ['fa', 20, $id]
        )->payload, true)['places']['landmarkPlaces'][0];

        foreach ([$sql, $this->place($id)] as $place) {
            $this->assertSame('/storage/images/cultural.jpg', $place['image']);
            $this->assertEquals($media, $place['content']['media']);
            $this->assertSame('متن بدون تغییر', $place['content']['body']);
            $this->assertSame((string) $id, $place['id']);
            $this->assertSame(-1, $place['floor']);
            $this->assertCount(2, $place['coordinates']);
        }
    }

    public function test_legacy_path_src_url_strings_and_base64_images_still_work(): void
    {
        $cases = [
            [[['path' => '/storage/legacy.JPG?version=2']], '/storage/legacy.JPG?version=2'],
            [[['src' => '/storage/legacy.webp#photo']], '/storage/legacy.webp#photo'],
            [[['url' => '/storage/legacy.png']], '/storage/legacy.png'],
            [['/storage/string-photo.jpeg'], '/storage/string-photo.jpeg'],
            [[['mime' => 'image/png', 'data' => 'aW1hZ2U=']], 'data:image/png;base64,aW1hZ2U='],
            [[['type' => 'image/webp', 'data' => 'aW1hZ2U=']], 'data:image/webp;base64,aW1hZ2U='],
            [[['data' => 'data:image/gif;base64,aW1hZ2U=']], 'data:image/gif;base64,aW1hZ2U='],
            [[['type' => 'image', 'url' => '/storage/extensionless']], '/storage/extensionless'],
        ];
        foreach ($cases as [$media, $expected]) {
            $id = $this->poi($media);
            $place = $this->place($id);
            $this->assertSame($expected, $place['image']);
            $this->assertEquals($media, $place['content']['media']);
        }
    }

    public function test_nonimage_unknown_or_conflicting_media_never_become_landmark_images(): void
    {
        $media = [
            ['type' => 'video', 'url' => '/storage/misleading.jpg'],
            ['mime' => 'video/mp4', 'url' => '/storage/misleading.png'],
            ['type' => 'image', 'mime' => 'audio/mpeg', 'url' => '/storage/misleading.webp'],
            ['type' => 'image', 'url' => '/storage/movie.MP4?token=opaque'],
            ['type' => 'image', 'url' => 'data:video/mp4;base64,dmlkZW8='],
            ['type' => 'file', 'url' => '/storage/document.jpg'],
            ['path' => '/storage/unknown'],
            ['mime' => 'application/octet-stream', 'url' => '/storage/unknown.jpg'],
            ['mime' => 'video/mp4', 'data' => 'dmlkZW8='],
            ['url' => '/storage/audio.mp3'],
            ['src' => '/storage/document.pdf'],
        ];
        foreach ([$media, [['type' => 'video', 'mime' => 'video/mp4', 'url' => '/storage/only-video.mp4']], null, (object) ['url' => '/storage/object.png']] as $entries) {
            $id = $this->poi($entries);
            $place = $this->place($id);
            $this->assertNull($place['image']);
            $this->assertEquals(json_decode(json_encode($entries), true), $place['content']['media']);
        }
    }

    public function test_explicit_real_primary_image_is_preferred_and_poi_image_url_keeps_precedence(): void
    {
        $media = [
            ['type' => 'video', 'url' => '/storage/video.mp4', 'isPrimary' => true],
            ['type' => 'image', 'url' => '/storage/first.jpg'],
            ['type' => 'image', 'url' => '/storage/primary.png', 'isPrimary' => true],
        ];
        $id = $this->poi($media);
        $this->assertSame('/storage/primary.png', $this->place($id)['image']);

        foreach (['/storage/explicit.jpg', 'https://images.example.test/extensionless'] as $image) {
            DB::table('poi_points')->where('id', $id)->update(['attrs' => json_encode(['image_url' => $image])]);
            $this->assertSame($image, $this->place($id)['image']);
        }
        foreach (['/storage/stale-video.mp4?token=x', '/storage/stale-audio.mp3', '/storage/stale-document.pdf', 'data:video/mp4;base64,dmlkZW8='] as $image) {
            DB::table('poi_points')->where('id', $id)->update(['attrs' => json_encode(['image_url' => $image])]);
            $this->assertSame('/storage/primary.png', $this->place($id)['image']);
        }
    }

    public function test_migration_restores_the_previous_function_and_preserves_media_geometry_and_route_definitions(): void
    {
        $this->migration()->down();
        $previousDefinition = DB::selectOne("SELECT pg_get_functiondef('public.fn_landmark_places_json(public.lang_enum,integer,double precision,double precision,bigint,text,boolean)'::regprocedure) AS definition")->definition;
        $routingBefore = DB::select("SELECT oid, pg_get_functiondef(oid) AS definition FROM pg_proc WHERE pronamespace = 'public'::regnamespace AND proname LIKE 'fn_route%' ORDER BY oid");
        $media = [
            ['type' => 'video', 'path' => '/storage/video.mp4'],
            ['type' => 'image', 'path' => '/storage/photo.jpg'],
        ];
        $id = $this->poi($media, ['unrelated' => ['keep' => true]]);
        $before = DB::selectOne('SELECT ST_AsEWKB(geom)::text AS geom, floor, attrs::text AS attrs FROM poi_points WHERE id = ?', [$id]);
        $beforeContent = DB::table('contents')->where('poi_id', $id)->first();
        $this->assertSame('/storage/video.mp4', $this->place($id)['image']);

        $this->migration()->up();
        $this->migration()->up();
        $this->assertSame('/storage/photo.jpg', $this->place($id)['image']);
        $this->migration()->down();
        $this->assertSame($previousDefinition, DB::selectOne("SELECT pg_get_functiondef('public.fn_landmark_places_json(public.lang_enum,integer,double precision,double precision,bigint,text,boolean)'::regprocedure) AS definition")->definition);
        $this->assertSame('/storage/video.mp4', $this->place($id)['image']);
        $this->migration()->up();
        $this->assertSame('/storage/photo.jpg', $this->place($id)['image']);
        $this->assertEquals($before, DB::selectOne('SELECT ST_AsEWKB(geom)::text AS geom, floor, attrs::text AS attrs FROM poi_points WHERE id = ?', [$id]));
        $this->assertEquals($beforeContent, DB::table('contents')->where('poi_id', $id)->first());
        $this->assertEquals($routingBefore, DB::select("SELECT oid, pg_get_functiondef(oid) AS definition FROM pg_proc WHERE pronamespace = 'public'::regnamespace AND proname LIKE 'fn_route%' ORDER BY oid"));
    }
}
