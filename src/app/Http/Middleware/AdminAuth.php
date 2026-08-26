<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth
{
    public function __construct(protected JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'error' => 'UNAUTHORIZED',
                'message' => 'Missing Bearer token'
            ], 401);
        }

        $token   = substr($authHeader, 7);
        $payload = $this->jwt->decode($token);

        if (!$payload || ($payload['type'] ?? null) !== 'access') {
            return response()->json([
                'error' => 'TOKEN_INVALID',
                'message' => 'Invalid or expired token'
            ], 401);
        }

        $userId = $payload['sub'] ?? null;
        /** @var User|null $user */
        $user = $userId ? User::find($userId) : null;

        if (!$user || !$user->is_admin) {
            return response()->json([
                'error' => 'UNAUTHORIZED',
                'message' => 'Admin account required'
            ], 401);
        }

        if ($user->status !== 'active' || ($user->locked_until && now()->lessThan($user->locked_until))) {
            return response()->json([
                'error' => 'ACCOUNT_LOCKED',
                'message' => 'Account is locked or disabled'
            ], 423);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
