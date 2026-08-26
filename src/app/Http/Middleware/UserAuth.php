<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PublicJwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UserAuth
{
    public function __construct(protected PublicJwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'message' => 'Missing Bearer token',
                'code' => 'UNAUTHORIZED',
                'errors' => (object)[],
            ], 401);
        }

        $token   = substr($authHeader, 7);
        $payload = $this->jwt->decode($token);

        if (!$payload || ($payload['type'] ?? null) !== 'access') {
            return response()->json([
                'message' => 'Invalid or expired token',
                'code' => 'INVALID_TOKEN',
                'errors' => (object)[],
            ], 401);
        }

        $userId = $payload['sub'] ?? null;
        /** @var User|null $user */
        $user = $userId ? User::find($userId) : null;

        if (!$user || $user->is_admin) {
            return response()->json([
                'message' => 'User account required',
                'code' => 'UNAUTHORIZED',
                'errors' => (object)[],
            ], 401);
        }

        if ($user->status !== 'active' || ($user->locked_until && now()->lessThan($user->locked_until))) {
            return response()->json([
                'message' => 'Account is locked or disabled',
                'code' => 'ACCOUNT_LOCKED',
                'errors' => (object)[],
            ], 423);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
