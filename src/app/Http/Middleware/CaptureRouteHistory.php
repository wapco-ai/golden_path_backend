<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaptureRouteHistory
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/routing/route')) {
            return app(OptionalUserAuth::class)->handle($request, $next);
        }

        return $next($request);
    }

}
