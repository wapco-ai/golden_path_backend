<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuth;
use App\Models\AdminLoginLog;
use App\Models\AdminRefreshToken;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

        $identifier = $data['usernameOrEmail'];
        $ip         = $request->ip();
        $ua         = $request->userAgent();

        // پیدا کردن یوزر (ایمیل یا username)
        $user = User::where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();

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
        if ($user->status !== 'active' || ($user->locked_until && now()->lessThan($user->locked_until))) {
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
                'roles'    => $roles,
                'permissions' => $permissions,
            ],
        ]);
    }

    public function refresh(Request $request)
    {
        $data = $request->validate([
            'refreshToken' => 'required|string',
        ]);

        $token = $data['refreshToken'];

        $refresh = AdminRefreshToken::where('token', $token)->first();

        if (!$refresh || $refresh->revoked_at || $refresh->expires_at->isPast()) {
            return response()->json([
                'error' => 'TOKEN_INVALID',
                'message' => 'Refresh token نامعتبر یا منقضی است.',
            ], 401);
        }

        $user = $refresh->user;
        if (!$user || !$user->is_admin) {
            return response()->json([
                'error' => 'UNAUTHORIZED',
                'message' => 'کاربر ادمین پیدا نشد.',
            ], 401);
        }

        if ($user->status !== 'active' || ($user->locked_until && now()->lessThan($user->locked_until))) {
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
            'permissions' => $permissions,
            'status'   => $user->status,
            'lastLoginAt' => $user->last_login_at,
            'lastLoginIp' => $user->last_login_ip,
        ]);
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
