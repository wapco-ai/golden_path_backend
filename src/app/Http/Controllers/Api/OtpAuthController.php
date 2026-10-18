<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exceptions\PublicAuthException;
use App\Http\Requests\OtpRequest;
use App\Http\Requests\OtpVerifyRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserRefreshToken;
use App\Services\OtpService;
use App\Services\ProfileService;
use App\Services\PublicJwtService;
use App\Services\RazaviAuthService;
use App\Support\ApiError;
use Illuminate\Support\Facades\DB;

class OtpAuthController extends Controller
{
    use ApiError;

    public function __construct(
        protected OtpService $otpService,
        protected PublicJwtService $jwt,
        protected ProfileService $profiles,
        protected RazaviAuthService $razavi
    ) {}

    public function requestOtp(OtpRequest $request)
    {
        if (config('public_auth.provider') === 'razavi') {
            try {
                return response()->json($this->razavi->requestOtp($request->validated()['phone']));
            } catch (PublicAuthException $exception) {
                return $this->providerError($exception);
            }
        }
        if (config('public_auth.provider') !== 'local') {
            return $this->error('AUTH_PROVIDER_UNAVAILABLE', 'سرویس احراز هویت پیکربندی نشده است.', 503);
        }

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
            ? $user->otp_last_sent_at->diffInSeconds(now(), false)
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
        $debug = app()->environment(['local', 'testing']) && (bool) config('public_auth.local.return_in_response', false);

        return response()->json([
            'ok' => true,
            'message' => 'OTP_SENT',
            'devOtp' => $debug ? $otp : null,
            'expiresIn' => (int) env('OTP_TTL_SECONDS', 120),
            'retryAfter' => 30,
            'otpLength' => 6,
        ]);
    }

    public function verifyOtp(OtpVerifyRequest $request)
    {
        $phone = $request->validated()['phone'];
        $otp   = $request->validated()['otp'];

        if (config('public_auth.provider') === 'razavi') {
            try {
                $user = $this->razavi->verifyOtp($phone, $otp);
            } catch (PublicAuthException $exception) {
                return $this->providerError($exception);
            }
        } elseif (config('public_auth.provider') === 'local') {
            return $this->verifyLocalOtp($request, $phone, $otp);
        } else {
            return $this->error('AUTH_PROVIDER_UNAVAILABLE', 'سرویس احراز هویت پیکربندی نشده است.', 503);
        }

        return $this->issueSession($request, $user);
    }

    private function verifyLocalOtp(OtpVerifyRequest $request, string $phone, string $otp)
    {
        $user = User::where('mobile', $phone)->first();
        if (!$user) return $this->error('USER_NOT_FOUND', 'کاربر یافت نشد.', 404);

        // اگر خواستی: enforce locked_until / status
        if ($user->status !== 'active' || ($user->locked_until && now()->lessThan($user->locked_until))) {
            return $this->error('ACCOUNT_LOCKED', 'حساب قفل است.', 423);
        }

        $devBypass = app()->environment(['local', 'testing']) && (bool) config('public_auth.local.dev_bypass', false);
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

        return $this->issueSession($request, $user);
    }

    private function issueSession(OtpVerifyRequest $request, User $user)
    {
        return DB::transaction(function () use ($request, $user) {
            // Re-read under a row lock: an administrator may have disabled or
            // locked the local account while the provider calls were in flight.
            $current = User::whereKey($user->id)->lockForUpdate()->first();
            if (!$current || $current->is_admin) {
                return $this->error('UNAUTHORIZED', 'ورود به این حساب مجاز نیست.', 403);
            }
            if ($current->status !== 'active' || !$current->is_active
                || ($current->locked_until && now()->lessThan($current->locked_until))) {
                return $this->error('ACCOUNT_LOCKED', 'حساب قفل یا غیرفعال است.', 423);
            }

            return $this->createSession($request, $current);
        });
    }

    private function createSession(OtpVerifyRequest $request, User $user)
    {
        // Consume any legacy local code as well as the provider challenge.
        $user->otp_hash = null;
        $user->otp_expires_at = null;

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

    private function providerError(PublicAuthException $exception)
    {
        $payload = [
            'code' => $exception->errorCode,
            'message' => $exception->getMessage(),
            'errors' => (object) [],
        ];
        $headers = [];
        if ($exception->retryAfter !== null) {
            $payload['retryAfter'] = $exception->retryAfter;
            $headers['Retry-After'] = (string) $exception->retryAfter;
        }

        return response()->json($payload, $exception->httpStatus, $headers);
    }

}
