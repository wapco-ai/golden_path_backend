<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PublicJwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaptureRouteHistory
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/routing/route')) {
            $this->resolveOptionalUser($request);
        }

        return $next($request);
    }

    private function resolveOptionalUser(Request $request): ?User
    {
        $authHeader = $request->header('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        /** @var PublicJwtService $jwt */
        $jwt = app(PublicJwtService::class);
        $payload = $jwt->decode(substr($authHeader, 7));
        if (!$payload || ($payload['type'] ?? null) !== 'access') {
            return null;
        }

        $userId = $payload['sub'] ?? null;
        /** @var User|null $user */
        $user = $userId ? User::find($userId) : null;

        if (!$user || $user->is_admin || $user->status !== 'active') {
            return null;
        }

        if ($user->locked_until && now()->lessThan($user->locked_until)) {
            return null;
        }

        $request->setUserResolver(fn () => $user);

        return $user;
    }
}
