<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OptionalUserAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Older public clients sent a locally generated guest identifier as a
        // bearer token. Preserve guest feedback while the frontend is upgraded;
        // this value never authenticates or identifies an account.
        $legacyGuest = $request->is('api/v1/feedbacks')
            && preg_match('/^Bearer guest-feedback-[A-Za-z0-9-]{1,100}$/', $request->header('Authorization', ''));
        if (!$request->hasHeader('Authorization') || $legacyGuest) {
            $request->setUserResolver(fn () => null);
            return $next($request);
        }
        // An expired login must be refreshed, never silently treated as a guest.
        return app(UserAuth::class)->handle($request, $next);
    }
}
