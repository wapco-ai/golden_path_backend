<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuth;
use App\Models\AdminLoginLog;
use App\Models\AdminRefreshToken;
use App\Models\User;
use App\Services\JwtService;
use App\Services\Admin\AdminService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function __construct(protected JwtService $jwt)
    {
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'usernameOrEmail' => 'required|string|max:255',
            'password'        => 'required|string|min:6',
        ]);

        return DB::transaction(function () use ($request, $data) {
            $identifier = $data['usernameOrEmail'];
            $ip         = $request->ip();
            $ua         = $request->userAgent();

            // پیدا کردن یوزر (ایمیل یا username)
            $user = User::where('email', $identifier)
                ->orWhere('username', $identifier)
                ->lockForUpdate()->first();

            // اگر یوزر پیدا نشد، لاگ ناموفق
            if (!$user) {
                AdminLoginLog::create([
                    'user_id'        => null,
                    'identifier'     => $identifier,
                    'ip'             => $ip,
                    'user_agent'     => $ua,
                    'success'        => false,
                    'failure_reason' => 'USER_NOT_FOUND',
                ]);

                return response()->json([
                    'error' => 'INVALID_CREDENTIALS',
                    'message' => 'نام کاربری یا رمز عبور اشتباه است.',
                ], 401);
            }

            // فقط ادمین‌ها اجازه ورود دارند
            if (!$user->is_admin) {
                AdminLoginLog::create([
                    'user_id'        => $user->id,
                    'identifier'     => $identifier,
                    'ip'             => $ip,
                    'user_agent'     => $ua,
                    'success'        => false,
                    'failure_reason' => 'NOT_ADMIN',
                ]);

                return response()->json([
                    'error' => 'UNAUTHORIZED',
                    'message' => 'این کاربر دسترسی ادمین ندارد.',
                ], 401);
            }

            // چک قفل بودن
            if ($user->status !== 'active' || !$user->is_active || ($user->locked_until && now()->lessThan($user->locked_until))) {
                AdminLoginLog::create([
                    'user_id'        => $user->id,
                    'identifier'     => $identifier,
                    'ip'             => $ip,
                    'user_agent'     => $ua,
                    'success'        => false,
                    'failure_reason' => 'ACCOUNT_LOCKED',
                ]);

                return response()->json([
                    'error' => 'ACCOUNT_LOCKED',
                    'message' => 'حساب کاربری قفل یا غیرفعال است.',
                ], 423);
            }

            // بررسی رمز: از جدول admin_auth استفاده کن (OTP users پسورد ندارند)
            $adminAuth = AdminAuth::where('user_id', $user->id)->first();
            $passwordHash = $adminAuth?->password_hash ?? $user->password;

            if (!$passwordHash || !Hash::check($data['password'], $passwordHash)) {
                $user->failed_login_attempts++;
                // اگر مثلا 5 بار اشتباه، 15 دقیقه قفل
                if ($user->failed_login_attempts >= 5) {
                    $user->locked_until = now()->addMinutes(15);
                    $user->failed_login_attempts = 0;
                }
                $user->save();

                AdminLoginLog::create([
                    'user_id'        => $user->id,
                    'identifier'     => $identifier,
                    'ip'             => $ip,
                    'user_agent'     => $ua,
                    'success'        => false,
                    'failure_reason' => 'WRONG_PASSWORD',
                ]);

                return response()->json([
                    'error' => 'INVALID_CREDENTIALS',
                    'message' => 'نام کاربری یا رمز عبور اشتباه است.',
                ], 401);
            }

            // موفقیت در لاگین: صفر کردن شمارش
            $user->failed_login_attempts = 0;
            $user->last_login_at = now();
            $user->last_login_ip = $ip;
            $user->save();

            // ایجاد accessToken
            $accessToken = $this->jwt->createAccessToken([
                'sub'  => $user->id,
                'role' => 'ADMIN',
            ]);

            // ایجاد refreshToken (رشته تصادفی)
            $refreshToken = bin2hex(random_bytes(32));
            $refreshTtl   = (int) env('JWT_REFRESH_TTL', 2592000);

            $refreshModel = AdminRefreshToken::create([
                'user_id'    => $user->id,
                'token'      => $refreshToken,
                'ip'         => $ip,
                'user_agent' => $ua,
                'expires_at' => now()->addSeconds($refreshTtl),
            ]);

            AdminLoginLog::create([
                'user_id'        => $user->id,
                'identifier'     => $identifier,
                'ip'             => $ip,
                'user_agent'     => $ua,
                'success'        => true,
                'failure_reason' => null,
            ]);

            $roles = $user->adminRoles()->pluck('code')->values();
            $permissions = $user->adminPermissions()->pluck('code')->values();

            return response()->json([
                'accessToken'  => $accessToken,
                'refreshToken' => $refreshToken,
                'expiresIn'    => (int) env('JWT_ACCESS_TTL', 1800),
                'user' => [
                    'id'       => $user->id,
                    'username' => $user->username,
                    'name'     => $user->name,
                    'email'    => $user->email,
                    'avatarUrl' => $user->avatar_url,
                    'avatar' => $user->avatar_url,
                    'roles'    => $roles,
                    'roleTitles' => $user->adminRoles()->pluck('title')->values(),
                    'permissions' => $permissions,
                ],
            ]);
        });
    }

    public function refresh(Request $request)
    {
        $data = $request->validate([
            'refreshToken' => 'required|string',
        ]);

        return DB::transaction(function () use ($request, $data) {
            $token = $data['refreshToken'];

            $refresh = AdminRefreshToken::where('token', $token)->first();

            if (!$refresh || $refresh->revoked_at || $refresh->expires_at->isPast()) {
                return response()->json([
                    'error' => 'TOKEN_INVALID',
                    'message' => 'Refresh token نامعتبر یا منقضی است.',
                ], 401);
            }

            // Lock the user first, then re-read the token after any password change.
            $user = User::query()->whereKey($refresh->user_id)->lockForUpdate()->first();
            $refresh = AdminRefreshToken::where('token', $token)->first();
            if (!$refresh || $refresh->revoked_at || $refresh->expires_at->isPast()) {
                return response()->json(['error' => 'TOKEN_INVALID', 'message' => 'Refresh token نامعتبر یا منقضی است.'], 401);
            }
            if (!$user || !$user->is_admin) {
                return response()->json([
                    'error' => 'UNAUTHORIZED',
                    'message' => 'کاربر ادمین پیدا نشد.',
                ], 401);
            }

            if ($user->status !== 'active' || !$user->is_active || ($user->locked_until && now()->lessThan($user->locked_until))) {
                return response()->json([
                    'error' => 'ACCOUNT_LOCKED',
                    'message' => 'حساب کاربری قفل یا غیرفعال است.',
                ], 423);
            }

            // rotate: این refreshToken را باطل کن
            $refresh->revoked_at = now();
            $refresh->save();

            $ip = $request->ip();
            $ua = $request->userAgent();

            // accessToken جدید
            $accessToken = $this->jwt->createAccessToken([
                'sub'  => $user->id,
                'role' => 'ADMIN',
            ]);

            // refreshToken جدید
            $newRefreshToken = bin2hex(random_bytes(32));
            $refreshTtl      = (int) env('JWT_REFRESH_TTL', 2592000);

            AdminRefreshToken::create([
                'user_id'    => $user->id,
                'token'      => $newRefreshToken,
                'ip'         => $ip,
                'user_agent' => $ua,
                'expires_at' => now()->addSeconds($refreshTtl),
            ]);

            return response()->json([
                'accessToken'  => $accessToken,
                'refreshToken' => $newRefreshToken,
                'expiresIn'    => (int) env('JWT_ACCESS_TTL', 1800),
            ]);
        });
    }

    public function me(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => 'UNAUTHORIZED',
            ], 401);
        }

        $roles = $user->adminRoles()->pluck('code')->values();
        $permissions = $user->adminPermissions()->pluck('code')->values();

        return response()->json([
            'id'       => $user->id,
            'username' => $user->username,
            'name'     => $user->name,
            'email'    => $user->email,
            'roles'    => $roles,
            'roleTitles' => $user->adminRoles()->pluck('title')->values(),
            'permissions' => $permissions,
            'status'   => $user->status,
            'lastLoginAt' => $user->last_login_at,
            'lastLoginIp' => $user->last_login_ip,
            'avatarUrl' => $user->avatar_url,
            'avatar' => $user->avatar_url,
        ]);
    }

    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'file' => 'required|file|image|mimes:jpeg,jpg,png,gif,webp|max:5120',
            'user_id' => 'prohibited', 'userId' => 'prohibited',
            'adminId' => 'prohibited', 'id' => 'prohibited',
        ]);
        $userId = (int) $request->user()->id;
        $path = null;
        try {
            [$oldUrl, $url] = DB::transaction(function () use ($request, $userId, &$path) {
                $user = User::query()->where('is_admin', true)->lockForUpdate()->findOrFail($userId);
                // Reuse the shared uploads directory and PHP file response; no new Nginx mount is needed.
                $path = $request->file('file')->store('uploads/admin_avatars/'.$userId, 'public');
                if (!$path) {
                    throw new \RuntimeException('Avatar storage failed.');
                }
                $url = '/api/v1/files?'.http_build_query(['path' => $path, 'inline' => 1], '', '&', PHP_QUERY_RFC3986);
                $oldUrl = $user->avatar_url;
                $user->avatar_url = $url;
                $user->save();
                return [$oldUrl, $url];
            });
        } catch (\Throwable $exception) {
            if ($path) {
                $this->deleteAdminAvatarFile('/api/v1/files?'.http_build_query(['path' => $path]), $userId);
            }
            report($exception);
            return response()->json(['error' => 'AVATAR_UPLOAD_FAILED', 'message' => 'ذخیره تصویر پروفایل انجام نشد.'], 500);
        }
        $this->deleteAdminAvatarFile($oldUrl, $userId);
        return response()->json(['url' => $url, 'avatarUrl' => $url]);
    }

    public function deleteAvatar(Request $request)
    {
        $request->validate([
            'user_id' => 'prohibited', 'userId' => 'prohibited',
            'adminId' => 'prohibited', 'id' => 'prohibited',
        ]);
        $userId = (int) $request->user()->id;
        try {
            $oldUrl = DB::transaction(function () use ($userId) {
                $user = User::query()->where('is_admin', true)->lockForUpdate()->findOrFail($userId);
                $oldUrl = $user->avatar_url;
                $user->avatar_url = null;
                $user->save();
                return $oldUrl;
            });
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['error' => 'AVATAR_DELETE_FAILED', 'message' => 'حذف تصویر پروفایل انجام نشد.'], 500);
        }
        $this->deleteAdminAvatarFile($oldUrl, $userId);
        return response()->json(['message' => 'AVATAR_DELETED', 'url' => null, 'avatarUrl' => null]);
    }

    private function deleteAdminAvatarFile(?string $url, int $userId): void
    {
        if (!$url || !str_starts_with($url, '/api/v1/files?')) {
            return;
        }
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
        $path = $query['path'] ?? null;
        if (!is_string($path) || !preg_match('#\Auploads/admin_avatars/'.$userId.'/[A-Za-z0-9_-]+\.(?:jpe?g|png|gif|webp)\z#', $path)) {
            return;
        }
        try {
            Storage::disk('public')->delete($path);
        } catch (\Throwable $exception) {
            // The saved account must not be rolled back just because old-file cleanup failed.
            report($exception);
        }
    }

    public function changePassword(Request $request, AdminService $admins)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
            'user_id' => 'prohibited',
            'userId' => 'prohibited',
            'adminId' => 'prohibited',
            'id' => 'prohibited',
        ], [
            'current_password.required' => 'رمز عبور فعلی را وارد کنید.',
            'password.required' => 'رمز عبور جدید را وارد کنید.',
            'password.min' => 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد.',
            'password.confirmed' => 'رمز عبور جدید با تأیید آن مطابقت ندارد.',
        ]);

        // Count verification attempts across access tokens and IPs for this account.
        $attemptKey = 'admin-password-change:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($attemptKey, 5)) {
            return response()->json([
                'error' => 'PASSWORD_CHANGE_RATE_LIMITED',
                'message' => 'تعداد تلاش‌ها بیش از حد مجاز است. کمی بعد دوباره امتحان کنید.',
            ], 429)->header('Retry-After', RateLimiter::availableIn($attemptKey));
        }
        RateLimiter::hit($attemptKey, 60);

        try {
            $admins->changeOwnPassword((int) $request->user()->id, $data['current_password'], $data['password']);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            // Database exceptions can contain password hashes in SQL bindings.
            return response()->json(['error' => 'PASSWORD_CHANGE_FAILED', 'message' => 'تغییر رمز عبور انجام نشد.'], 500);
        }

        return response()->json(['message' => 'PASSWORD_CHANGED']);
    }

    public function logout(Request $request)
    {
        $data = $request->validate([
            'refreshToken' => 'required|string',
        ]);

        $token = $data['refreshToken'];
        $refresh = AdminRefreshToken::where('token', $token)->first();

        if ($refresh && !$refresh->revoked_at) {
            $refresh->revoked_at = now();
            $refresh->save();
        }

        return response()->json([
            'message' => 'LOGOUT_SUCCESS',
        ]);
    }
}
