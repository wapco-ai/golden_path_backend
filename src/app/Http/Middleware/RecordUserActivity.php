<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\RequestActivity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RecordUserActivity
{
    private const AUTH_EVENTS = [
        'api/v1/admin/auth/login' => 'auth.login',
        'api/v1/admin/auth/refresh' => 'auth.refresh',
        'api/v1/admin/auth/logout' => 'auth.logout',
        'api/v1/auth/otp/verify' => 'auth.login',
        'api/v1/auth/refresh' => 'auth.refresh',
        'api/v1/auth/logout' => 'auth.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $route = $request->route();
        if (!$route instanceof Route || !str_starts_with($route->uri(), 'api/v1/')) {
            return;
        }

        $uri = $route->uri();
        $event = $request->isMethod('POST') ? (self::AUTH_EVENTS[$uri] ?? null) : null;
        $actor = $request->attributes->get(RequestActivity::ACTOR) ?? $request->user();
        $actor = $actor instanceof User ? $actor : null;
        if ($event === null && $actor === null) {
            return;
        }

        $entityId = $request->attributes->get(RequestActivity::ENTITY_ID)
            ?? ($route->originalParameters()['id'] ?? null);
        $entityId = (is_int($entityId) || is_string($entityId))
            && preg_match('/\A[0-9]{1,19}\z/', (string) $entityId) ? (string) $entityId : null;
        $requestId = (string) Str::uuid();

        try {
            Log::channel('activity')->info($event ?? 'api.request', [
                'request_id' => $requestId,
                'actor_id' => $actor?->getKey(),
                'actor_type' => $actor === null ? null : ($actor->is_admin ? 'admin' : 'user'),
                'method' => $request->method(),
                'route' => $uri,
                'entity_id' => $entityId,
                'status' => $response->getStatusCode(),
                'deleted_rows' => $request->attributes->get(RequestActivity::DELETED_ROWS),
                'logout_token_matched' => $request->attributes->get(RequestActivity::LOGOUT_TOKEN_MATCHED),
                'client_ip' => $request->ip(),
            ]);
        } catch (Throwable) {
            error_log('User activity log write failed for request '.$requestId);
        }
    }
}
