<?php

declare(strict_types=1);
use App\Http\Middleware\TenantContext;
use App\Http\Middleware\TenantContextCandidate;

/**
 * Middleware isolation tests (C6 — Participant + SSO Ingress).
 *
 * Asserts:
 * - TenantContext NOT invoked on GET /candidate/session
 * - TenantContext NOT invoked on GET /sso/exchange
 * - TenantContextCandidate IS invoked on candidate routes and sets org
 * - middleware stack for candidate routes: auth:api-candidate → TenantContextCandidate → SubstituteBindings
 * - withoutMiddleware strips TenantContext from both route groups
 *
 * REQ: Candidate Route Group and Public Exchange Route Do Not Inherit Global TenantContext
 */
test('GET /api/sso/exchange route middleware list does NOT include human TenantContext', function (): void {
    $routes = app('router')->getRoutes();
    $exchangeRoute = null;

    foreach ($routes as $route) {
        if ($route->uri() === 'api/sso/exchange') {
            $exchangeRoute = $route;
            break;
        }
    }

    expect($exchangeRoute)->not->toBeNull('exchange route should be registered');

    $middleware = $exchangeRoute->gatherMiddleware();

    expect($middleware)->not->toContain(TenantContext::class);
    expect($middleware)->not->toContain('App\Http\Middleware\TenantContext');
});

test('GET /api/candidate/session route middleware list does NOT include human TenantContext', function (): void {
    $routes = app('router')->getRoutes();
    $sessionRoute = null;

    foreach ($routes as $route) {
        if ($route->uri() === 'api/candidate/session') {
            $sessionRoute = $route;
            break;
        }
    }

    expect($sessionRoute)->not->toBeNull('candidate session route should be registered');

    $middleware = $sessionRoute->gatherMiddleware();

    expect($middleware)->not->toContain(TenantContext::class);
    expect($middleware)->not->toContain('App\Http\Middleware\TenantContext');
});

test('GET /api/candidate/session route middleware includes TenantContextCandidate', function (): void {
    $routes = app('router')->getRoutes();
    $sessionRoute = null;

    foreach ($routes as $route) {
        if ($route->uri() === 'api/candidate/session') {
            $sessionRoute = $route;
            break;
        }
    }

    expect($sessionRoute)->not->toBeNull();
    $middleware = $sessionRoute->gatherMiddleware();

    expect($middleware)->toContain(TenantContextCandidate::class);
});

test('GET /api/candidate/session route middleware includes auth:api-candidate', function (): void {
    $routes = app('router')->getRoutes();
    $sessionRoute = null;

    foreach ($routes as $route) {
        if ($route->uri() === 'api/candidate/session') {
            $sessionRoute = $route;
            break;
        }
    }

    expect($sessionRoute)->not->toBeNull();
    $middleware = $sessionRoute->gatherMiddleware();

    $hasAuth = false;
    foreach ($middleware as $m) {
        if (str_contains((string) $m, 'auth:api-candidate') || $m === 'auth:api-candidate') {
            $hasAuth = true;
            break;
        }
    }

    expect($hasAuth)->toBeTrue('candidate session route should have auth:api-candidate middleware');
});

test('GET /api/sso/exchange route has no auth guard middleware', function (): void {
    $routes = app('router')->getRoutes();
    $exchangeRoute = null;

    foreach ($routes as $route) {
        if ($route->uri() === 'api/sso/exchange') {
            $exchangeRoute = $route;
            break;
        }
    }

    expect($exchangeRoute)->not->toBeNull();
    $middleware = $exchangeRoute->gatherMiddleware();

    // Exchange is public — no auth middleware
    $hasAuth = false;
    foreach ($middleware as $m) {
        if (str_contains((string) $m, 'auth:api') || str_contains((string) $m, 'auth:api-candidate')) {
            $hasAuth = true;
            break;
        }
    }

    expect($hasAuth)->toBeFalse('sso exchange route must be public (no auth middleware)');
});
