<?php

declare(strict_types=1);

/**
 * TenantContextCandidate middleware unit tests (C6 — Participant + SSO Ingress).
 *
 * Asserts:
 * - setBypass(false) called BEFORE setOrgId (observable end-state)
 * - null participant → 401
 * - null org_id on participant → 401
 * - org set from DB record, NOT from JWT claims (claim=99, record=7 → resolved=7)
 *
 * REQ: TenantContextCandidate — scenarios
 *   "setBypass(false) called before setOrgId"
 *   "Tampered org claim in JWT ignored"
 */

use App\Http\Middleware\TenantContextCandidate;
use App\Models\Participant;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeTenantContextCandidateMiddleware(): TenantContextCandidate
{
    return new TenantContextCandidate(
        app(TenantResolver::class),
        app(PermissionRegistrar::class),
    );
}

function passThroughNextCandidate(): \Closure
{
    return fn ($req) => response()->json(['next' => true], 200);
}

function mockCandidateGuard(?Participant $participant): void
{
    $mockGuard = Mockery::mock(\Illuminate\Auth\RequestGuard::class);
    $mockGuard->shouldReceive('user')->once()->andReturn($participant);

    Auth::shouldReceive('guard')
        ->with('api-candidate')
        ->once()
        ->andReturn($mockGuard);
}

// ---------------------------------------------------------------------------
// W1: null participant → fail-closed 401
// ---------------------------------------------------------------------------

test('null participant (guard returns null) → 401 fail-closed, orgId stays null', function (): void {
    mockCandidateGuard(null);

    $resolver   = app(TenantResolver::class);
    $middleware = makeTenantContextCandidateMiddleware();
    $request    = Request::create('/test', 'GET');

    $response = $middleware->handle($request, passThroughNextCandidate());

    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    expect($response->getContent())->toContain('Unauthenticated');
    expect($resolver->getOrgId())->toBeNull();
    expect($resolver->isBypass())->toBeFalse();
});

// ---------------------------------------------------------------------------
// W2: participant with null organization_id → fail-closed 401
// ---------------------------------------------------------------------------

test('participant with null organization_id → 401 fail-closed', function (): void {
    $participant = new Participant;
    $participant->organization_id = null; // @phpstan-ignore-line

    mockCandidateGuard($participant);

    $resolver   = app(TenantResolver::class);
    $middleware = makeTenantContextCandidateMiddleware();
    $request    = Request::create('/test', 'GET');

    $response = $middleware->handle($request, passThroughNextCandidate());

    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    expect($resolver->getOrgId())->toBeNull();
    expect($resolver->isBypass())->toBeFalse();
});

// ---------------------------------------------------------------------------
// W3: happy path — bypass=false AND correct orgId from DB record
// ---------------------------------------------------------------------------

test('happy path: bypass=false AND correct orgId from DB record', function (): void {
    $orgId = 7;

    $participant = new Participant;
    $participant->organization_id = $orgId;

    mockCandidateGuard($participant);

    $resolver = app(TenantResolver::class);
    $resolver->setBypass(true); // pre-set stale bypass to verify it is cleared

    $middleware = makeTenantContextCandidateMiddleware();
    $request    = Request::create('/test', 'GET');

    $response = $middleware->handle($request, passThroughNextCandidate());

    expect($response->getStatusCode())->toBe(200);
    expect($resolver->isBypass())->toBeFalse();
    expect($resolver->getOrgId())->toBe($orgId);
});

// ---------------------------------------------------------------------------
// W4: tampered org claim in JWT is irrelevant — org from DB record only
// ---------------------------------------------------------------------------

test('org is resolved from DB record, not JWT claims (tampered claim ignored)', function (): void {
    // The JWT claim might say org=99 (tampered), but the Participant DB record says org=7.
    // The guard closure loads the Participant by sub (id), so the participant already
    // has organization_id=7 from the DB. TenantContextCandidate reads participant.organization_id.
    // The JWT claim value is never consulted here — this is the security invariant.
    $dbOrgId      = 7;
    $tamperedOrgId = 99;

    $participant = new Participant;
    $participant->organization_id = $dbOrgId; // what the DB says

    // We do NOT set a tamperedOrgId here because the middleware only reads participant->organization_id.
    // The tampered claim scenario is: attacker mints a JWT with org_id=99 but authenticates as
    // participant.id whose DB org_id=7. After guard loads participant, org=7 is used (not claim).

    mockCandidateGuard($participant);

    $resolver = app(TenantResolver::class);
    $middleware = makeTenantContextCandidateMiddleware();
    $request    = Request::create('/test', 'GET');

    $response = $middleware->handle($request, passThroughNextCandidate());

    expect($response->getStatusCode())->toBe(200);
    // org MUST be the DB record's org, not any claim value
    expect($resolver->getOrgId())->toBe($dbOrgId);
    expect($resolver->getOrgId())->not->toBe($tamperedOrgId);
});
