<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuthRefreshRequest;
use App\Http\Requests\AuthLogoutRequest;
use App\Http\Resources\UserResource;
use App\Services\ProfileService;
use App\Services\UserTokenService;
use App\Support\ApiError;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use ApiError;

    public function __construct(
        protected UserTokenService $tokens,
        protected ProfileService $profiles
    ) {}

    public function refresh(AuthRefreshRequest $request)
    {
        $res = $this->tokens->rotateRefreshToken(
            $request->validated()['refreshToken'],
            $request->ip(),
            $request->userAgent()
        );

        if (!$res['ok']) {
            $code = $res['code'] ?? 'INVALID_TOKEN';
            return $this->error($code, $code === 'INVALID_TOKEN' ? 'Refresh token نامعتبر یا منقضی است.' : 'UNAUTHORIZED', 401);
        }

        $user = $res['user'];
        $completed = $this->profiles->isProfileCompleted($user);

        return response()->json([
            'accessToken' => $res['accessToken'],
            'refreshToken' => $res['refreshToken'],
            'expiresIn' => $res['expiresIn'],
            'user' => (new UserResource($user, $completed))->toArray($request),
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->error('UNAUTHORIZED', 'UNAUTHORIZED', 401);

        $completed = $this->profiles->isProfileCompleted($user);
        return response()->json((new UserResource($user, $completed))->toArray($request));
    }

    public function logout(AuthLogoutRequest $request)
    {
        $ok = $this->tokens->logoutByRefreshToken($request->validated()['refreshToken']);

        // نیازمندی: 204 یا پیام موفقیت
        return response()->json(['message' => 'LOGOUT_SUCCESS', 'ok' => $ok], 204);
    }
}
