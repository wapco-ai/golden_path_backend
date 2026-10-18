<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.can' => \App\Http\Middleware\AdminCan::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Admin category endpoints are consumed by fetch clients. A missing or
        // wildcard Accept header must not turn validation into a redirect back
        // to the frontend origin, where the browser hides the useful error.
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $exception): bool {
            return $request->is(
                'api/v1/admin/categories',
                'api/v1/admin/categories/*',
                'api/v1/admin/subcategories/*',
            ) || $request->expectsJson();
        });
    })->create();
