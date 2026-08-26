<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserRefreshToken;
use App\Services\PublicJwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_me_requires_token()
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJson(['code' => 'UNAUTHORIZED']);
    }

    public function test_auth_refresh_rotates_token()
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'mobile' => '09120000000',
            'name' => 'Test User',
            'email' => 'u@test.com',
        ]);

        $rt = UserRefreshToken::create([
            'user_id' => $user->id,
            'token' => 'old',
            'expires_at' => now()->addDays(10),
        ]);

        $res = $this->postJson('/api/v1/auth/refresh', ['refreshToken' => 'old']);
        $res->assertStatus(200)
            ->assertJsonStructure(['accessToken','refreshToken','expiresIn','user']);

        $this->assertNotNull($rt->fresh()->revoked_at);
        $this->assertDatabaseHas('user_refresh_tokens', ['user_id' => $user->id]);
    }

    public function test_auth_logout_revokes_all_sessions_for_user()
    {
        $user = User::factory()->create(['is_admin' => false]);

        UserRefreshToken::create([
            'user_id' => $user->id,
            'token' => 't1',
            'expires_at' => now()->addDays(10),
        ]);
        UserRefreshToken::create([
            'user_id' => $user->id,
            'token' => 't2',
            'expires_at' => now()->addDays(10),
        ]);

        // bearer
        $jwt = app(PublicJwtService::class);
        $access = $jwt->createAccessToken(['sub' => $user->id, 'role' => 'USER']);

        $this->withHeader('Authorization', 'Bearer '.$access)
            ->postJson('/api/v1/auth/logout', ['refreshToken' => 't1'])
            ->assertStatus(204);

        $this->assertDatabaseMissing('user_refresh_tokens', ['token' => 't1', 'revoked_at' => null]);
        $this->assertDatabaseMissing('user_refresh_tokens', ['token' => 't2', 'revoked_at' => null]);
    }
}
