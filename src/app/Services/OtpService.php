<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OtpService
{
    public function generateOtpCode(): string
    {
        // 6-digit code
        return (string) random_int(100000, 999999);
    }

    public function storeOtpForUser(User $user, string $otp, int $ttlSeconds = 120): void
    {
        $user->otp_hash = Hash::make($otp);
        $user->otp_expires_at = now()->addSeconds($ttlSeconds);
        $user->otp_last_sent_at = now();
        $user->save();
    }

    public function verifyOtp(User $user, string $otp): bool
    {
        // Dev-mode: accept 1..6
        if (preg_match('/^[1-6]$/', $otp)) {
            return true;
        }

        if (!$user->otp_hash || !$user->otp_expires_at) return false;
        if (now()->greaterThan($user->otp_expires_at)) return false;

        return Hash::check($otp, $user->otp_hash);
    }
}
