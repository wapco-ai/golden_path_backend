<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserRefreshToken;

class UserTokenService
{
    public function __construct(protected PublicJwtService $jwt) {}

    public function issueAccessToken(User $user): string
    {
        return $this->jwt->createAccessToken([
            'sub' => $user->id,
            'role' => 'USER',
        ]);
    }

    public function rotateRefreshToken(string $refreshToken, string $ip = null, string $ua = null): array
    {
        $row = UserRefreshToken::where('token', $refreshToken)->first();

        if (!$row || $row->revoked_at || $row->expires_at->isPast()) {
            return ['ok' => false, 'code' => 'INVALID_TOKEN'];
        }

        $user = $row->user;
        if (!$user || $user->is_admin) {
            return ['ok' => false, 'code' => 'UNAUTHORIZED'];
        }

        // revoke old
        $row->revoked_at = now();
        $row->save();

        $newRefresh = bin2hex(random_bytes(32));
        $refreshTtl = (int) env('JWT_PUBLIC_REFRESH_TTL', 2592000);

        UserRefreshToken::create([
            'user_id' => $user->id,
            'token' => $newRefresh,
            'ip' => $ip,
            'user_agent' => $ua,
            'expires_at' => now()->addSeconds($refreshTtl),
        ]);

        $access = $this->issueAccessToken($user);

        return [
            'ok' => true,
            'user' => $user,
            'accessToken' => $access,
            'refreshToken' => $newRefresh,
            'expiresIn' => (int) env('JWT_PUBLIC_ACCESS_TTL', 1800),
        ];
    }

    /**
     * Requirement: "تمام نشست‌های فعال مرتبط با این توکن باطل شود"
     * => نشست‌های فعال همان user را revoke می‌کنیم.
     */
    public function logoutByRefreshToken(string $refreshToken): bool
    {
        $row = UserRefreshToken::where('token', $refreshToken)->first();
        if (!$row) return false;

        UserRefreshToken::where('user_id', $row->user_id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return true;
    }
}
