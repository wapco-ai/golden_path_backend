<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Services\CategoryAdminService;
use App\Services\JwtService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real request/controller validation with a mocked service and no DB server. */
class CategoryRequestValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([AdminAuth::class, \App\Http\Middleware\AdminSectionAccess::class]);
    }

    private function service(): \Mockery\MockInterface
    {
        $service = Mockery::mock(CategoryAdminService::class);
        $this->instance(CategoryAdminService::class, $service);
        return $service;
    }

    public static function invalidBodies(): array
    {
        return [
            'missing title' => [[], 'title'],
            'broken json' => [['payload' => '{'], 'payload'],
            'list json' => [['payload' => '[]'], 'payload'],
            'null json' => [['payload' => 'null'], 'payload'],
            'string json' => [['payload' => '"title"'], 'payload'],
            'empty json string' => [['payload' => ''], 'payload'],
            'wrong title type in payload' => [['payload' => '{"title":["x"]}'], 'title'],
            'missing title in payload' => [['payload' => '{"status":"active"}'], 'title'],
            'invalid status in payload' => [['payload' => '{"title":"گروه","status":"bad"}'], 'status'],
            'empty batch title in payload' => [['payload' => '{"title":"گروه","subcategories":[{"title":"درست"},{"title":""}]}'], 'subcategories.1.title'],
            'nonobject batch entry' => [['title' => 'گروه', 'subcategories' => ['wrong']], 'subcategories.0'],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_json_and_multipart_payloads_fail_before_service_side_effects(array $body, string $field): void
    {
        $this->service()->shouldNotReceive('create');
        $this->postJson('/api/v1/admin/categories', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_json_controller_uses_validated_fields_and_preserves_icon_and_property_target_alias(): void
    {
        $this->service()->shouldReceive('create')->once()->with([
            'title' => 'گروه', 'icon' => 'Pictogram34.png', 'property_target' => 'group',
        ], null)->andReturn(['id' => 91, 'createdAt' => null]);
        $this->postJson('/api/v1/admin/categories', [
            'title' => 'گروه', 'image' => 'Pictogram34.png', 'propertyTarget' => 'group',
            'created_at' => '1999-01-01', 'unvalidated' => ['ignored' => true],
        ])->assertCreated()->assertJsonPath('id', 91);
    }

    public static function fileAliases(): array
    {
        return [['icon'], ['image']];
    }

    #[DataProvider('fileAliases')]
    public function test_multipart_file_aliases_and_json_payload_are_validated_then_delivered(string $fileField): void
    {
        $file = new UploadedFile(__DIR__.'/../Fixtures/cultural-media/tiny.jpg', 'photo.jpg', 'image/jpeg', null, true);
        $this->service()->shouldReceive('create')->once()->with(
            ['title' => 'دسته', 'description' => 'توضیح', 'property_target' => 'group'],
            Mockery::on(fn ($image) => $image instanceof UploadedFile && $image->getClientOriginalName() === 'photo.jpg')
        )->andReturn(['id' => 92]);
        $this->post('/api/v1/admin/categories', [
            'payload' => json_encode(['title' => 'دسته', 'description' => 'توضیح', 'property_target' => 'group']),
            $fileField => $file,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('id', 92);
    }

    #[DataProvider('fileAliases')]
    public function test_invalid_uploaded_file_is_not_accepted_through_either_alias(string $fileField): void
    {
        $this->service()->shouldNotReceive('create');
        $this->post('/api/v1/admin/categories', [
            'payload' => json_encode(['title' => 'دسته']),
            $fileField => new UploadedFile(__DIR__.'/../Fixtures/cultural-media/tiny.mp4', 'fake.jpg', 'image/jpeg', null, true),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_multipart_update_decodes_before_validation_and_passes_the_file_alias(): void
    {
        $file = new UploadedFile(__DIR__.'/../Fixtures/cultural-media/tiny.png', 'photo.png', 'image/png', null, true);
        $this->service()->shouldReceive('update')->once()->with(
            93, ['title' => 'ویرایش', 'status' => 'inactive'], Mockery::type(UploadedFile::class)
        )->andReturn(['id' => 93]);
        $this->call('PUT', '/api/v1/admin/categories/93', [
            'payload' => json_encode(['title' => 'ویرایش', 'status' => 'inactive']),
        ], [], ['icon' => $file], ['HTTP_ACCEPT' => 'application/json'])->assertOk()->assertJsonPath('id', 93);
    }

    public function test_multipart_update_rejects_unvalidated_nested_title(): void
    {
        $this->service()->shouldNotReceive('update');
        $this->call('PUT', '/api/v1/admin/categories/93', ['payload' => '{"title":["bad"]}'], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ])->assertUnprocessable()->assertJsonValidationErrors('title');
    }

    public function test_multipart_post_with_method_override_reaches_the_update_route(): void
    {
        $this->service()->shouldReceive('update')->once()->with(
            94, ['title' => 'ویرایش با تصویر'], Mockery::type(UploadedFile::class)
        )->andReturn(['id' => 94]);
        $this->post('/api/v1/admin/categories/94', [
            '_method' => 'PUT', 'payload' => json_encode(['title' => 'ویرایش با تصویر']),
            'icon' => new UploadedFile(__DIR__.'/../Fixtures/cultural-media/tiny.jpg', 'photo.jpg', 'image/jpeg', null, true),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('id', 94);
    }

    public static function duplicateRequestsWithoutJsonAccept(): array
    {
        return [
            'create missing Accept' => ['POST', '/api/v1/admin/categories', 'create', null],
            'create wildcard Accept' => ['POST', '/api/v1/admin/categories', 'create', '*/*'],
            'update missing Accept' => ['PUT', '/api/v1/admin/categories/95', 'update', null],
            'update wildcard Accept' => ['PUT', '/api/v1/admin/categories/95', 'update', '*/*'],
            'create HTML Accept' => ['POST', '/api/v1/admin/categories', 'create', 'text/html'],
        ];
    }

    #[DataProvider('duplicateRequestsWithoutJsonAccept')]
    public function test_duplicate_validation_is_json_422_without_a_frontend_redirect(string $method, string $url, string $serviceMethod, ?string $accept): void
    {
        $this->service()->shouldReceive($serviceMethod)->once()->andThrow(ValidationException::withMessages([
            'title' => ['دسته‌بندی دیگری با این عنوان وجود دارد.'],
        ]));
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_REFERER' => 'http://localhost:5173/admin'];
        if ($accept !== null) $headers['HTTP_ACCEPT'] = $accept;
        $this->call($method, $url, [], [], [], $headers, json_encode(['title' => 'عنوان تکراری']))
            ->assertUnprocessable()->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')->assertJsonValidationErrors('title')
            ->assertJsonPath('errors.title.0', 'دسته‌بندی دیگری با این عنوان وجود دارد.');
    }

    public static function invalidRequestsWithoutJsonAccept(): array
    {
        return [
            ['POST', '/api/v1/admin/categories', 'create'],
            ['PUT', '/api/v1/admin/categories/95', 'update'],
        ];
    }

    #[DataProvider('invalidRequestsWithoutJsonAccept')]
    public function test_form_request_json_errors_do_not_require_an_accept_header(string $method, string $url, string $serviceMethod): void
    {
        $this->service()->shouldNotReceive($serviceMethod);
        $this->call($method, $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_REFERER' => 'http://localhost:5173/admin',
        ], json_encode(['title' => ['invalid']]))->assertUnprocessable()->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')->assertJsonValidationErrors('title');
    }

    #[DataProvider('invalidRequestsWithoutJsonAccept')]
    public function test_multipart_errors_do_not_redirect_when_accept_is_absent(string $method, string $url, string $serviceMethod): void
    {
        $this->service()->shouldNotReceive($serviceMethod);
        $body = ['payload' => '{"title":["invalid"]}'];
        if ($method === 'PUT') $body['_method'] = 'PUT';
        $this->post($url, $body + ['icon' => new UploadedFile(
            __DIR__.'/../Fixtures/cultural-media/tiny.jpg', 'photo.jpg', 'image/jpeg', null, true
        )], ['Referer' => 'http://localhost:5173/admin'])->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')->assertHeaderMissing('Location')->assertJsonValidationErrors('title');
    }

    public function test_category_authorization_still_returns_json_without_accept(): void
    {
        $this->instance(JwtService::class, Mockery::mock(JwtService::class));
        $this->withMiddleware(AdminAuth::class);
        $this->post('/api/v1/admin/categories', ['title' => 'گروه'], ['Referer' => 'http://localhost:5173/admin'])
            ->assertUnauthorized()->assertHeader('Content-Type', 'application/json')->assertHeaderMissing('Location');
    }

    public function test_web_and_other_api_routes_keep_their_existing_content_negotiation(): void
    {
        foreach (['category-negotiation-web-check', 'api/v1/unrelated-negotiation-check'] as $path) {
            Route::post($path, fn () => throw ValidationException::withMessages(['title' => ['invalid']]));
            $this->post('/'.$path, [], ['Referer' => 'http://localhost:5173/admin'])->assertRedirect('http://localhost:5173/admin');
            $this->postJson('/'.$path, [])->assertUnprocessable()->assertHeader('Content-Type', 'application/json');
        }
    }
}
