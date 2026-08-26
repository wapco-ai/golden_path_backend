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
            ->assertJsonStructure(['id','phone','fullName','email','profileCompleted']);
    }

    public function test_get_and_update_profile()
    {
        $user = User::factory()->create(['is_admin' => false, 'mobile' => '09120000000', 'name' => 'A', 'email' => 'a@test.com']);
        $jwt = app(PublicJwtService::class);
        $access = $jwt->createAccessToken(['sub' => $user->id, 'role' => 'USER']);

        $this->withHeader('Authorization', 'Bearer '.$access)
            ->getJson('/api/v1/users/me')
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$access)
            ->patchJson('/api/v1/users/me', [
                'fullName' => 'نام کامل',
                'birthDate' => '2000-01-01',
                'gender' => 'male',
            ])
            ->assertStatus(200)
            ->assertJsonPath('profileCompleted', true);
    }
}
