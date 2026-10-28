<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Services\JwtService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Mockery;
use Tests\TestCase;

/** Real file bytes and HTTP multipart requests; no database server required. */
class CulturalMediaUploadTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(AdminAuth::class);
        \Illuminate\Support\Facades\Schema::create('admin_roles', function ($table) {
            $table->id(); $table->string('code'); $table->string('title');
        });
        \Illuminate\Support\Facades\Schema::create('admin_user_roles', function ($table) {
            $table->integer('user_id'); $table->integer('role_id');
        });
        \Illuminate\Support\Facades\DB::table('admin_roles')->insert(['id' => 1, 'code' => 'super_dashboard_admin', 'title' => 'ادمین کل داشبورد']);
        \Illuminate\Support\Facades\DB::table('admin_user_roles')->insert(['user_id' => 1, 'role_id' => 1]);
        $this->actingAs((new \App\Models\User())->forceFill(['id' => 1, 'is_admin' => true]));
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) unlink($path);
        }
        parent::tearDown();
    }

    private function upload(string $fixture, string $name, string $claimedMime = 'application/octet-stream', ?int $size = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cultural-upload-');
        $this->temporaryFiles[] = $path;
        copy(__DIR__.'/../Fixtures/cultural-media/'.$fixture, $path);
        if ($size !== null) {
            $handle = fopen($path, 'r+');
            ftruncate($handle, $size);
            fclose($handle);
            clearstatcache(true, $path);
        }

        return new UploadedFile($path, $name, $claimedMime, null, true);
    }

    private function postFile(UploadedFile $file, string $bucket = 'images', string $entity = 'contents')
    {
        return $this->post('/api/v1/files', [
            'file' => $file, 'entity_table' => $entity, 'entity_id' => 123,
            'bucket' => $bucket, 'keep_original_name' => true,
        ], ['Accept' => 'application/json']);
    }

    public static function images(): array
    {
        return [
            ['tiny.jpg', 'photo.jpg', 'image/jpeg'],
            ['tiny.jpg', 'photo.JPEG', 'image/jpeg'],
            ['tiny.png', 'photo.png', 'image/png'],
            ['tiny.webp', 'photo.webp', 'image/webp'],
            ['tiny.gif', 'photo.gif', 'image/gif'],
        ];
    }

    #[DataProvider('images')]
    public function test_real_supported_photos_use_server_mime_and_preserve_both_response_shapes(string $fixture, string $name, string $mime): void
    {
        $response = $this->postFile($this->upload($fixture, $name));
        $response->assertCreated()->assertJsonPath('mime', $mime)->assertJsonPath('data.mime', $mime)
            ->assertJsonPath('attached', false)->assertJsonPath('data.attached', false);
        foreach (['id', 'path', 'url', 'original_name', 'mime', 'size', 'entity_table', 'entity_id', 'bucket', 'attached'] as $key) {
            $this->assertSame($response->json($key), $response->json('data.'.$key));
        }
        Storage::disk('public')->assertExists($response->json('path'));
        $this->assertStringEndsWith('.'.strtolower(pathinfo($name, PATHINFO_EXTENSION)), $response->json('path'));
    }

    public function test_photo_at_five_mib_succeeds_and_one_byte_above_fails(): void
    {
        $this->postFile($this->upload('tiny.png', 'limit.png', 'image/png', 5 * 1024 * 1024))->assertCreated();
        $this->postFile($this->upload('tiny.png', 'too-large.png', 'image/png', 5 * 1024 * 1024 + 1))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_real_mp4_can_use_legacy_images_and_new_videos_buckets(): void
    {
        foreach (['images', 'videos'] as $bucket) {
            $this->postFile($this->upload('tiny.mp4', 'movie-'.$bucket.'.mp4'), $bucket)
                ->assertCreated()->assertJsonPath('mime', 'video/mp4')->assertJsonPath('bucket', $bucket);
        }
    }

    public function test_video_at_fifty_mib_succeeds_and_one_byte_above_fails(): void
    {
        $this->postFile($this->upload('tiny.mp4', 'limit.mp4', 'video/mp4', 50 * 1024 * 1024), 'videos')->assertCreated();
        $this->postFile($this->upload('tiny.mp4', 'too-large.mp4', 'video/mp4', 50 * 1024 * 1024 + 1), 'videos')
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public static function invalidMedia(): array
    {
        return [
            'mismatched photo extension' => ['tiny.png', 'photo.jpg', 'image/jpeg', 'images'],
            'unsupported photo' => ['tiny.bmp', 'photo.bmp', 'image/bmp', 'images'],
            'renamed unsupported photo' => ['tiny.bmp', 'photo.jpg', 'image/jpeg', 'images'],
            'unsupported movie extension' => ['tiny.mp4', 'movie.mov', 'video/quicktime', 'videos'],
            'video named photo' => ['tiny.mp4', 'photo.jpg', 'image/jpeg', 'images'],
            'photo named video' => ['tiny.jpg', 'movie.mp4', 'video/mp4', 'videos'],
            'photo in dedicated video bucket' => ['tiny.jpg', 'photo.jpg', 'image/jpeg', 'videos'],
            'video renamed in documents bucket' => ['tiny.mp4', 'movie.bin', 'application/octet-stream', 'files'],
            'unsupported photo in documents bucket' => ['tiny.bmp', 'photo.bin', 'application/octet-stream', 'files'],
        ];
    }

    #[DataProvider('invalidMedia')]
    public function test_wrong_content_or_extension_is_rejected_before_storage(string $fixture, string $name, string $mime, string $bucket): void
    {
        $this->postFile($this->upload($fixture, $name, $mime), $bucket)
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_claimed_image_mime_does_not_make_text_a_photo(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cultural-spoof-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, '<html>This is not an image.</html>');
        $this->postFile(new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_image_limit_cannot_be_bypassed_using_documents_bucket(): void
    {
        $this->postFile($this->upload('tiny.jpg', 'photo.jpg', 'application/octet-stream', 5 * 1024 * 1024 + 1), 'files')
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_audio_and_document_legacy_fifty_mib_contract_is_preserved(): void
    {
        foreach (['audio' => ['tiny.wav', 'recording.wav', 'audio/x-wav'], 'files' => ['tiny.pdf', 'guide.pdf', 'application/pdf']] as $bucket => [$fixture, $name, $mime]) {
            $this->postFile($this->upload($fixture, $name, $mime, 6 * 1024 * 1024), $bucket)
                ->assertCreated()->assertJsonPath('bucket', $bucket);
        }
        $this->postFile($this->upload('tiny.pdf', 'too-large.pdf', 'application/pdf', 50 * 1024 * 1024 + 1), 'files')
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_other_entities_keep_existing_upload_policy_and_client_mime(): void
    {
        $this->postFile($this->upload('tiny.bmp', 'existing.bmp', 'application/x-legacy', 6 * 1024 * 1024), 'images', 'areas')
            ->assertCreated()->assertJsonPath('mime', 'application/x-legacy')->assertJsonPath('entity_table', 'areas');
        $this->postFile($this->upload('tiny.bmp', 'too-large.bmp', 'image/bmp', 50 * 1024 * 1024 + 1), 'images', 'areas')
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_upload_still_requires_an_admin(): void
    {
        $this->instance(JwtService::class, Mockery::mock(JwtService::class));
        $this->withMiddleware(AdminAuth::class);
        $this->postFile($this->upload('tiny.mp4', 'movie.mp4'), 'videos')->assertUnauthorized();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
