<?php

declare(strict_types=1);

/**
 * TenantContextM2m middleware unit tests — fail-closed branches (C5).
 *
 * Covers the two fail-closed invariants that HTTP-level feature tests
 * cannot reach in isolation:
 *   - Line 52: null client (guard returned null)   → 401, no org set
 *   - Line 59: null organization_id on the client  → 401, no org set
 *
 * TenantResolver is `final`, so call-order cannot be spied on directly.
 * The observable invariant is instead asserted:
 *   - fail-closed path: bypass stays false AND orgId stays null
 *   - happy path:       bypass=false AND correct orgId (double-check of existing
 *                       feature tests, confirming the unit view matches)
 *
 * Because TenantResolver is registered as `scoped` (new instance per request),
 * we resolve it fresh from the container to observe the state after handle().
 *
 * REQ-4, REQ-T1 / design §TenantContextM2m
 */

use App\Http\Middleware\TenantContextM2m;
use App\Models\ApiClient;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a fresh middleware instance from the container (real deps).
 */
function makeTenantContextM2mMiddleware(): TenantContextM2m
{
    return new TenantContextM2m(
        app(TenantResolver::class),
        app(PermissionRegistrar::class),
    );
}

/**
 * A minimal "next" closure that always returns 200.
 */
function passThroughNext(): \Closure
{
    return fn ($req) => response()->json(['next' => true], 200);
}

// ---------------------------------------------------------------------------
// W1a: null client → fail-closed 401, no org stamped
// ---------------------------------------------------------------------------

test('W1 — null client (guard returns null) → 401 fail-closed, orgId stays null', function (): void {
    // Arrange: fake the guard so user() returns null
    $mockGuard = Mockery::mock(\Illuminate\Auth\RequestGuard::class);
    $mockGuard->shouldReceive('user')->once()->andReturn(null);

    Auth::shouldReceive('guard')
        ->with('api-m2m')
        ->once()
        ->andReturn($mockGuard);

    $resolver = app(TenantResolver::class);
    $middleware = makeTenantContextM2mMiddleware();
    $request = Request::create('/test', 'GET');

    // Act
    $response = $middleware->handle($request, passThroughNext());

    // Assert: fail-closed → 401
    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    expect($response->getContent())->toContain('Unauthenticated');

    // Assert: resolver state untouched — no org was stamped
    expect($resolver->getOrgId())->toBeNull();
    expect($resolver->isBypass())->toBeFalse();
});

// ---------------------------------------------------------------------------
// W1b: client with null organization_id → fail-closed 401, no org stamped
// ---------------------------------------------------------------------------

test('W1 — client with null organization_id → 401 fail-closed, orgId stays null', function (): void {
    // Arrange: construct an ApiClient with organization_id = null
    // (This represents a misconfigured credential — allowed by the type system
    // but rejected as a security invariant.)
    $client = new ApiClient;
    $client->organization_id = null; // @phpstan-ignore-line: intentional null for test
    $client->is_active = true;
    $client->abilities = ['participants:read'];

    $mockGuard = Mockery::mock(\Illuminate\Auth\RequestGuard::class);
    $mockGuard->shouldReceive('user')->once()->andReturn($client);

    Auth::shouldReceive('guard')
        ->with('api-m2m')
        ->once()
        ->andReturn($mockGuard);

    $resolver = app(TenantResolver::class);
    $middleware = makeTenantContextM2mMiddleware();
    $request = Request::create('/test', 'GET');

    // Act
    $response = $middleware->handle($request, passThroughNext());

    // Assert: fail-closed → 401
    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    expect($response->getContent())->toContain('Unauthenticated');

    // Assert: no org was leaked through the resolver
    expect($resolver->getOrgId())->toBeNull();
    expect($resolver->isBypass())->toBeFalse();
});

// ---------------------------------------------------------------------------
// W1c: happy path — setBypass(false) + correct orgId observable end-state
// ---------------------------------------------------------------------------

test('W1 — happy path: bypass=false AND correct orgId after handle()', function (): void {
    $orgId = 42;

    $client = new ApiClient;
    $client->organization_id = $orgId;
    $client->is_active = true;
    $client->abilities = ['participants:read'];

    $mockGuard = Mockery::mock(\Illuminate\Auth\RequestGuard::class);
    $mockGuard->shouldReceive('user')->once()->andReturn($client);

    Auth::shouldReceive('guard')
        ->with('api-m2m')
        ->once()
        ->andReturn($mockGuard);

    // Pre-set bypass=true to verify that the middleware actively clears it
    $resolver = app(TenantResolver::class);
    $resolver->setBypass(true);

    $middleware = makeTenantContextM2mMiddleware();
    $request = Request::create('/test', 'GET');

    // Act
    $response = $middleware->handle($request, passThroughNext());

    // Assert: happy path → 200 from next
    expect($response->getStatusCode())->toBe(200);

    // Assert: bypass cleared to false (setBypass(false) was called)
    expect($resolver->isBypass())->toBeFalse();

    // Assert: org stamped correctly
    expect($resolver->getOrgId())->toBe($orgId);
});
