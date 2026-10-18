<?php

namespace App\Services;

use App\Exceptions\PublicAuthException;
use App\Models\User;
use App\Support\IranianMobile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Exchanges a verified employer login for the existing local application session. */
class RazaviAuthService
{
    public function requestOtp(string $mobile): array
    {
        return $this->withPhoneLock($mobile, function () use ($mobile) {
            $this->eligibleUser($mobile);
            $previous = Cache::get($this->challengeKey($mobile));
            $cooldown = $this->otpSetting('cooldown_seconds', 30);
            $remaining = is_array($previous) ? $cooldown - (now()->timestamp - $previous['sent_at']) : 0;
            if ($remaining > 0) {
                throw new PublicAuthException('TOO_MANY_REQUESTS', 'لطفاً کمی بعد دوباره تلاش کنید.', 429, $remaining);
            }

            $this->send('POST', '/app/v1/login', $this->loginParameters($mobile));
            $ttl = $this->otpSetting('ttl_seconds', 120);
            Cache::put($this->challengeKey($mobile), [
                'sent_at' => now()->timestamp,
                'expires_at' => now()->timestamp + $ttl,
                'failures' => 0,
            ], max($ttl, $cooldown));

            return [
                'ok' => true,
                'message' => 'OTP_SENT',
                'devOtp' => null,
                'expiresIn' => $ttl,
                'retryAfter' => $cooldown,
                'otpLength' => $this->otpSetting('length', 4),
            ];
        });
    }

    public function verifyOtp(string $mobile, string $code): User
    {
        return $this->withPhoneLock($mobile, function () use ($mobile, $code) {
            $this->eligibleUser($mobile);
            $key = $this->challengeKey($mobile);
            $challenge = Cache::get($key);
            if (!is_array($challenge) || $challenge['expires_at'] <= now()->timestamp
                || $challenge['failures'] >= $this->otpSetting('max_attempts', 5)) {
                throw new PublicAuthException('INVALID_OTP', 'کد نامعتبر یا منقضی است؛ کد جدید دریافت کنید.', 400);
            }

            try {
                $tokens = $this->send('POST', '/app/v1/verify', $this->loginParameters($mobile) + ['code' => $code], verification: true);
            } catch (PublicAuthException $exception) {
                if ($exception->errorCode === 'INVALID_OTP') {
                    $challenge['failures']++;
                    Cache::put($key, $challenge, max(1, $challenge['expires_at'] - now()->timestamp));
                }
                throw $exception;
            }

            $accessToken = $tokens['access_token'] ?? null;
            if (!is_string($accessToken) || strlen($accessToken) > 16384
                || !preg_match('/^[A-Za-z0-9\-._~+\/=]+$/D', $accessToken)
                || (isset($tokens['token_type']) && (!is_string($tokens['token_type']) || strcasecmp($tokens['token_type'], 'Bearer') !== 0))) {
                throw $this->invalidResponse();
            }

            // The employer validates its own JWT. Unverified JWT claims and the
            // submitted phone alone are never accepted as a verified identity.
            $profile = $this->send('GET', '/app/v1/profile', bearer: $accessToken);
            $subject = $profile['uuid'] ?? null;
            $verifiedMobile = isset($profile['mobile']) && is_string($profile['mobile'])
                ? IranianMobile::normalize($profile['mobile']) : null;
            if (!is_string($subject) || !Str::isUuid($subject)) {
                throw $this->invalidResponse();
            }
            if ($verifiedMobile === null || $verifiedMobile !== $mobile) {
                throw new PublicAuthException('IDENTITY_MISMATCH', 'اطلاعات هویت دریافتی با شماره ورود مطابقت ندارد.', 502);
            }
            if (isset($profile['status']) && is_string($profile['status'])
                && in_array(strtolower($profile['status']), ['disabled', 'inactive', 'locked', 'blocked'], true)) {
                throw new PublicAuthException('ACCOUNT_LOCKED', 'حساب قفل یا غیرفعال است.', 423);
            }

            try {
                $user = $this->linkIdentity($mobile, strtolower($subject), $profile);
            } catch (QueryException $exception) {
                // Concurrent first logins must fail closed if a unique mobile or
                // identity wins the race; do not return a SQL error to the client.
                if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                    throw $this->identityConflict();
                }
                throw $exception;
            }
            Cache::forget($key);

            // Employer credentials deliberately are not stored or sent to the browser.
            return $user;
        });
    }

    private function linkIdentity(string $mobile, string $subject, array $profile): User
    {
        return DB::transaction(function () use ($mobile, $subject, $profile) {
            $identityQuery = DB::table('user_auth_identities')
                ->where('provider', 'razavi')->where('issuer', $this->baseUrl());
            $identity = (clone $identityQuery)->where('subject', $subject)->lockForUpdate()->first();
            $user = $this->eligibleUser($mobile, true);

            if ($identity && (!$user || (int) $identity->user_id !== (int) $user->id)) {
                throw $this->identityConflict();
            }
            if ($user) {
                $other = (clone $identityQuery)->where('user_id', $user->id)->first();
                if ($other && $other->subject !== $subject) {
                    throw $this->identityConflict();
                }
            } else {
                // Import names only when creating a public user. Existing profile,
                // roles, saved destinations and status are retained without overwrite.
                $first = $this->profileName($profile['first_name'] ?? $profile['firstname'] ?? null);
                $last = $this->profileName($profile['last_name'] ?? $profile['lastname'] ?? null);
                $user = User::create([
                    'mobile' => $mobile,
                    'name' => trim(($first ?? '').' '.($last ?? '')) ?: 'کاربر',
                    'first_name' => $first,
                    'last_name' => $last,
                    'is_admin' => false,
                    'is_active' => true,
                    'status' => 'active',
                    'email' => null,
                    'password' => null,
                ]);
            }
            if (!$identity) {
                DB::table('user_auth_identities')->insert([
                    'user_id' => $user->id,
                    'provider' => 'razavi',
                    'issuer' => $this->baseUrl(),
                    'subject' => $subject,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $user;
        });
    }

    private function eligibleUser(string $mobile, bool $lock = false): ?User
    {
        $query = $this->usersWithMobile($mobile);
        $users = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($users->count() > 1) {
            throw $this->identityConflict();
        }
        $user = $users->first();
        if ($user?->is_admin) {
            throw new PublicAuthException('UNAUTHORIZED', 'ورود کاربران از این مسیر برای این حساب مجاز نیست.', 403);
        }
        if ($user && ($user->status !== 'active' || !$user->is_active
            || ($user->locked_until && now()->lessThan($user->locked_until)))) {
            throw new PublicAuthException('ACCOUNT_LOCKED', 'حساب قفل یا غیرفعال است.', 423);
        }

        return $user;
    }

    private function usersWithMobile(string $mobile): Builder
    {
        // Legacy registration accepted formatted mobiles. Match using the same
        // digits/format normalization before applying local account controls;
        // otherwise a disabled historical account could get an active duplicate.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $expression = IranianMobile::postgresStorageExpression();
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite has no translate/regexp_replace. Its deterministic SQL
            // function uses the exact same canonicalization as request input.
            DB::connection()->getPdo()->sqliteCreateFunction(
                'goldenpath_mobile',
                static fn ($value) => is_string($value) ? IranianMobile::normalize($value) : null,
                1,
                \PDO::SQLITE_DETERMINISTIC,
            );

            return User::whereRaw('goldenpath_mobile(mobile) = ?', [$mobile]);
        } else {
            throw $this->unavailable();
        }
        $national = substr($mobile, 1);
        $aliases = [$mobile, '+98'.$national, '98'.$national, '0098'.$national, $national];
        $placeholders = implode(', ', array_fill(0, count($aliases), '?'));

        return User::whereRaw($expression.' IN ('.$placeholders.')', $aliases);
    }

    private function send(string $method, string $path, array $query = [], ?string $bearer = null, bool $verification = false): array
    {
        $client = Http::acceptJson()->withoutRedirecting()
            ->connectTimeout(max(1, (int) config('public_auth.razavi.connect_timeout', 3)))
            ->timeout(max(1, (int) config('public_auth.razavi.timeout', 10)));
        if ($bearer !== null) {
            $client = $client->withToken($bearer);
        }
        try {
            // No retries: login sends an SMS and verify may consume a one-time code.
            $response = $client->send($method, $this->baseUrl().$path, ['query' => $query]);
        } catch (ConnectionException) {
            throw $this->unavailable();
        }
        if ($response->status() === 429) {
            $retry = $response->header('Retry-After');
            $seconds = ctype_digit($retry) ? min(3600, max(1, (int) $retry)) : $this->otpSetting('cooldown_seconds', 30);
            throw new PublicAuthException('TOO_MANY_REQUESTS', 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.', 429, $seconds);
        }
        if ($verification && in_array($response->status(), [400, 401, 403, 404, 422], true)) {
            throw new PublicAuthException('INVALID_OTP', 'کد نامعتبر یا منقضی است.', 400);
        }
        if ($path === '/app/v1/login' && $response->status() === 422) {
            throw new PublicAuthException('AUTH_PROVIDER_REJECTED', 'اطلاعات ورود توسط سرویس احراز هویت پذیرفته نشد.', 422);
        }
        if (!$response->successful()) {
            throw $this->unavailable();
        }
        $payload = $response->json();
        if (!is_array($payload) || ($payload['status'] ?? null) !== 'success' || !is_array($payload['data'] ?? null)) {
            throw $this->invalidResponse();
        }

        return $payload['data'];
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('public_auth.razavi.base_url'), '/');
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || strlen($url) > 255) {
            throw $this->unavailable();
        }

        return $url;
    }

    private function loginParameters(string $mobile): array
    {
        return [
            'mobile' => IranianMobile::international($mobile),
            'project' => (string) config('public_auth.razavi.project', 'noorolhoda'),
            'device' => (string) config('public_auth.razavi.device', 'web'),
        ];
    }

    private function challengeKey(string $mobile): string
    {
        return 'public-auth:razavi:'.hash('sha256', $this->baseUrl().'|'.config('public_auth.razavi.project').'|'.$mobile);
    }

    private function withPhoneLock(string $mobile, callable $callback): mixed
    {
        $lock = Cache::lock($this->challengeKey($mobile).':lock', max(30, (int) config('public_auth.razavi.timeout', 10) * 3));
        if (!$lock->get()) {
            throw new PublicAuthException('TOO_MANY_REQUESTS', 'درخواست قبلی در حال پردازش است.', 429, 1);
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function otpSetting(string $name, int $fallback): int
    {
        if ($name === 'length') {
            return max(4, min(8, (int) config('public_auth.otp.length', $fallback)));
        }

        return max(1, (int) config('public_auth.otp.'.$name, $fallback));
    }

    private function profileName(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 100) : null;
    }

    private function unavailable(): PublicAuthException
    {
        return new PublicAuthException('AUTH_PROVIDER_UNAVAILABLE', 'سرویس احراز هویت در دسترس نیست؛ کمی بعد دوباره تلاش کنید.', 503);
    }

    private function invalidResponse(): PublicAuthException
    {
        return new PublicAuthException('AUTH_PROVIDER_INVALID_RESPONSE', 'پاسخ سرویس احراز هویت معتبر نیست.', 502);
    }

    private function identityConflict(): PublicAuthException
    {
        return new PublicAuthException('IDENTITY_CONFLICT', 'تطبیق حساب کاربری نیازمند بررسی پشتیبانی است.', 409);
    }
}
