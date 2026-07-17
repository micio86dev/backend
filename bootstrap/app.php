<?php

use App\Http\Middleware\TenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Apply security headers globally (task 7.7 / D29).
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // C2: Register TenantContext on the `api` middleware group AFTER auth:api.
        // TenantContext reads $request->user() which is only available after auth:api
        // has authenticated the bearer token and loaded the User from the DB.
        // IMPORTANT: TenantContext must never run before auth:api — it would receive null user.
        $middleware->appendToGroup('api', TenantContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
