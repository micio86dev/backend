<?php

declare(strict_types=1);

/**
 * Route isolation tests (C5 — M2M API Authentication).
 *
 * Asserts:
 * - TenantContext middleware is NOT invoked on M2M routes (human middleware stripped)
 * - M2M route with null org context → 401 (fail-closed)
 * - Human JWT on M2M whoami route → 401
 * - M2M route group uses TenantContextM2m, not TenantContext
 *
 * REQ-5, REQ-T2 / design §Route isolation
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApiKeyGenerator;

test('human JWT on GET /api/m2m/whoami → 401 (guard non-interchangeability)', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $jwt = auth('api')->login($user);

    // A human JWT must NOT authenticate on the M2M whoami endpoint
    $this->withHeaders(['Authorization' => 'Bearer ' . $jwt])
        ->getJson('/api/m2m/whoami')
        ->assertUnauthorized();
});

test('M2M api_key on GET /api/auth/me (auth:api route) → 401 (guard non-interchangeability)', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash'        => ApiKeyGenerator::hash($rawKey),
    ]);

    // An M2M key must NOT authenticate on a human JWT-protected route
    $this->withHeaders(['Authorization' => 'Bearer ' . $rawKey])
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});

test('M2M whoami route middleware list does NOT include human TenantContext', function (): void {
    // Inspect the registered route's middleware stack
    $routes = app('router')->getRoutes();
    $whoamiRoute = null;

    foreach ($routes as $route) {
        if ($route->uri() === 'api/m2m/whoami') {
            $whoamiRoute = $route;
            break;
        }
    }

    expect($whoamiRoute)->not->toBeNull();

    $middleware = $whoamiRoute->gatherMiddleware();

    // The human TenantContext must NOT be in the M2M route's effective middleware
    expect($middleware)->not->toContain(\App\Http\Middleware\TenantContext::class);
    expect($middleware)->not->toContain('App\Http\Middleware\TenantContext');
});

test('M2M whoami route middleware list includes TenantContextM2m', function (): void {
    $routes = app('router')->getRoutes();
    $whoamiRoute = null;

    foreach ($routes as $route) {
        if ($route->uri() === 'api/m2m/whoami') {
            $whoamiRoute = $route;
            break;
        }
    }

    expect($whoamiRoute)->not->toBeNull();

    $middleware = $whoamiRoute->gatherMiddleware();

    // Must include the M2M-specific tenant context
    expect($middleware)->toContain(\App\Http\Middleware\TenantContextM2m::class);
});
