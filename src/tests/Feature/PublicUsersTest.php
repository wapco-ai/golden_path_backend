<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PublicJwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_user_success()
    {
        $res = $this->postJson('/api/v1/users', [
            'phone' => '09123334444',
            'fullName' => 'نام تست',
            'email' => 'x@test.com',
            'nationalId' => '1234567890',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('phone', '09123334444')
            ->assertJsonStructure(['id','phone','firstName','lastName','fullName','email','profileCompleted']);
    }

    public function test_get_and_update_profile_with_multi_part_first_name()
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'mobile' => '09120000000',
            'name' => 'نام قدیمی',
            'email' => 'a@test.com',
        ]);
        $jwt = app(PublicJwtService::class);
        $access = $jwt->createAccessToken(['sub' => $user->id, 'role' => 'USER']);

        $this->withHeader('Authorization', 'Bearer '.$access)
            ->getJson('/api/v1/users/me')
            ->assertStatus(200)
            ->assertJsonPath('fullName', 'نام قدیمی')
            ->assertJsonPath('firstName', null)
            ->assertJsonPath('lastName', null);

        $this->withHeader('Authorization', 'Bearer '.$access)
            ->patchJson('/api/v1/users/me', [
                'firstName' => 'محمد رضا',
                'lastName' => 'حسینی',
                'fullName' => 'این مقدار نباید مرجع canonical باشد',
                'birthDate' => '2000-01-01',
                'gender' => 'male',
            ])
            ->assertStatus(200)
            ->assertJsonPath('firstName', 'محمد رضا')
            ->assertJsonPath('lastName', 'حسینی')
            ->assertJsonPath('fullName', 'محمد رضا حسینی')
            ->assertJsonPath('profileCompleted', true);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'first_name' => 'محمد رضا',
            'last_name' => 'حسینی',
            'name' => 'محمد رضا حسینی',
        ]);
    }

    public function test_legacy_full_name_update_does_not_guess_name_boundaries()
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'mobile' => '09120000001',
            'name' => 'کاربر قدیمی',
            'email' => 'legacy@test.com',
        ]);
        $jwt = app(PublicJwtService::class);
        $access = $jwt->createAccessToken(['sub' => $user->id, 'role' => 'USER']);

        $this->withHeader('Authorization', 'Bearer '.$access)
            ->patchJson('/api/v1/users/me', [
                'fullName' => 'سید محمد رضا موسوی نژاد',
            ])
            ->assertStatus(200)
            ->assertJsonPath('fullName', 'سید محمد رضا موسوی نژاد')
            ->assertJsonPath('firstName', null)
            ->assertJsonPath('lastName', null);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'سید محمد رضا موسوی نژاد',
            'first_name' => null,
            'last_name' => null,
        ]);
    }
}
