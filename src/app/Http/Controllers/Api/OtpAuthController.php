<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OtpRequest;
use App\Http\Requests\OtpVerifyRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserRefreshToken;
use App\Services\OtpService;
use App\Services\ProfileService;
use App\Services\PublicJwtService;
use App\Support\ApiError;

class OtpAuthController extends Controller
{
    use ApiError;

    public function __construct(
        protected OtpService $otpService,
        protected PublicJwtService $jwt,
        protected ProfileService $profiles
    ) {}

    public function requestOtp(OtpRequest $request)
    {
        $phone = $request->validated()['phone'];

        // find or create user with phone only
        $user = User::firstOrCreate(
            ['mobile' => $phone],
            [
                'is_admin' => false,
                'status' => 'active',
                'name' => 'کاربر',
                'email' => null,
                'password' => null,
            ]
        );

        // rate-limit ساده (اختیاری): اگر otp_last_sent_at خیلی نزدیک بود
        // logger()->info('OTP_REQUEST_IN', [
        //     'phone_in' => $phone,
        //     'user_id' => $user?->id,
        //     'otp_last_sent_at' => $user?->otp_last_sent_at?->toIso8601String(),
        //     'diff' => $user?->otp_last_sent_at ? now()->diffInSeconds($user->otp_last_sent_at) : null,
        // ]);

        $diff = $user->otp_last_sent_at
            ? now()->diffInSeconds($user->otp_last_sent_at, false)
            : null;

        if ($diff !== null && $diff >= 0 && $diff < 30) {
            // logger()->warning('OTP_REQUEST_429', [
            //     'reason' => 'cooldown_or_other',
            //     'phone_in' => $phone,
            //     'user_id' => $user?->id,
            // ]);

            return $this->error('TOO_MANY_REQUESTS', 'لطفاً کمی بعد دوباره تلاش کنید.', 429);
        }
        // logger()->info('OTP_REQUEST_IN', [
        //     'phone_in' => $phone,
        //     'user_id' => $user?->id,
        //     'otp_last_sent_at' => $user?->otp_last_sent_at?->toIso8601String(),
        //     'diff' => $user?->otp_last_sent_at ? now()->diffInSeconds($user->otp_last_sent_at) : null,
        // ]);


        $otp = $this->otpService->generateOtpCode();
        $this->otpService->storeOtpForUser($user, $otp, (int) env('OTP_TTL_SECONDS', 120));

        $user->otp_last_sent_at = now();
        $user->save();

        // چون SMS ندارید، فعلاً در dev می‌توانیم کد را برگردانیم (فقط dev!)
        $debug = (bool) env('OTP_RETURN_IN_RESPONSE', true);

        return response()->json([
            'ok' => true,
            'message' => 'OTP_SENT',
            'devOtp' => $debug ? $otp : null,
            'expiresIn' => (int) env('OTP_TTL_SECONDS', 120),
        ]);
    }

    public function verifyOtp(OtpVerifyRequest $request)
    {
        $phone = $request->validated()['phone'];
        $otp   = $request->validated()['otp'];

        $user = User::where('mobile', $phone)->first();
        if (!$user) return $this->error('USER_NOT_FOUND', 'کاربر یافت نشد.', 404);

        // اگر خواستی: enforce locked_until / status
        if ($user->status !== 'active' || ($user->locked_until && now()->lessThan($user->locked_until))) {
            return $this->error('ACCOUNT_LOCKED', 'حساب قفل است.', 423);
        }

        $devBypass = (bool) env('OTP_DEV_BYPASS', true);
        if (preg_match('/^[1-6]$/', $otp) && !$devBypass) {
            return $this->error('INVALID_OTP', 'کد نامعتبر است.', 400);
        }

        if (!$this->otpService->verifyOtp($user, $otp)) {
            // (اختیاری) failed_login_attempts++ و قفل
            $user->failed_login_attempts = (int)($user->failed_login_attempts ?? 0) + 1;
            if ($user->failed_login_attempts >= (int) env('OTP_MAX_FAILS', 10)) {
                $user->locked_until = now()->addMinutes((int) env('OTP_LOCK_MINUTES', 10));
            }
            $user->save();

            return $this->error('INVALID_OTP', 'کد نامعتبر یا منقضی است.', 400);
        }

        // reset fail counters on success
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->last_login_at = now();
        $user->last_login_ip = $request->ip();
        $user->save();

        // issue tokens
        $accessTtl  = (int) env('JWT_PUBLIC_ACCESS_TTL', 1800);
        $refreshTtl = (int) env('JWT_PUBLIC_REFRESH_TTL', 2592000);

        $accessToken = $this->jwt->createAccessToken([
            'sub' => $user->id,
            'role' => 'USER',
        ]);

        $refreshToken = bin2hex(random_bytes(32));

        UserRefreshToken::create([
            'user_id' => $user->id,
            'token' => $refreshToken,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'expires_at' => now()->addSeconds($refreshTtl),
        ]);

        $completed = $this->profiles->isProfileCompleted($user);

        return response()->json([
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'expiresIn' => $accessTtl,
            'user' => (new UserResource($user, $completed))->toArray($request),
        ]);
    }
}
