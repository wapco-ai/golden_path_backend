<?php

namespace Tests\Feature;

use App\Http\Requests\UserProfileUpdateRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

// No database access: contract/resource/source regression coverage only.
class ProfileNameContractTest extends TestCase
{
    public function test_resource_preserves_multi_part_name_fields(): void
    {
        $user = new User();
        $user->id = 10;
        $user->mobile = '09121234567';
        $user->first_name = 'محمد رضا';
        $user->last_name = 'حسینی سادات';
        $user->name = 'legacy value';

        $payload = (new UserResource($user, true))->toArray(request());

        $this->assertSame('محمد رضا', $payload['firstName']);
        $this->assertSame('حسینی سادات', $payload['lastName']);
        $this->assertSame('محمد رضا حسینی سادات', $payload['fullName']);
    }

    public function test_resource_uses_legacy_full_name_without_guessing_boundaries(): void
    {
        $user = new User();
        $user->id = 11;
        $user->name = 'سید محمد رضا موسوی نژاد';
        $user->first_name = null;
        $user->last_name = null;

        $payload = (new UserResource($user, false))->toArray(request());

        $this->assertNull($payload['firstName']);
        $this->assertNull($payload['lastName']);
        $this->assertSame('سید محمد رضا موسوی نژاد', $payload['fullName']);
    }

    public function test_update_contract_accepts_spaces_inside_each_name_field(): void
    {
        $rules = (new UserProfileUpdateRequest())->rules();
        $validator = Validator::make([
            'firstName' => 'محمد رضا',
            'lastName' => 'حسینی سادات',
            'birthDate' => '2000-01-01',
        ], $rules);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE));
    }

    public function test_controller_syncs_legacy_name_without_splitting_full_name(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/UsersController.php'));

        $this->assertStringContainsString("$user->first_name = trim", $source);
        $this->assertStringContainsString("$user->last_name = trim", $source);
        $this->assertStringContainsString("$user->name = trim($user->first_name.' '.$user->last_name)", $source);
        $this->assertStringNotContainsString('preg_split', $source);
        $this->assertStringNotContainsString('explode(', $source);
    }
}
