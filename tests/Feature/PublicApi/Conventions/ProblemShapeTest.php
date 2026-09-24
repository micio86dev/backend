<?php

declare(strict_types=1);

/**
 * BEAI Public API (`/v1`) problem+json shape on every error path
 * (public-api step 3) — SPEC.md §3.2 "Errors: RFC 9457 Problem Details".
 *
 * T-CONV-004: every error path on `/v1` — an unknown route (404), a wrong
 * HTTP method on a known route (405, mapped to `not_found` — G-27), a
 * failed request validation (422), an unhandled exception (500), a missing
 * bearer key (401) and a missing scope (403) — all render
 * `application/problem+json` matching `components.schemas.Problem`.
 *
 * `App\Support\PublicApi\PublicApiExceptionRenderer` is the class under
 * test; these are its end-to-end HTTP-level proof, since the mapping is
 * only reachable through Laravel's own exception-handling pipeline.
 */

use App\Http\Middleware\PublicApi\AssignRequestId;
use App\Http\Middleware\PublicApi\AuthenticatePublicApi;
use App\Http\Middleware\PublicApi\RejectApiKeyInQuery;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Services\ApiKeyGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\Helpers\PublicApi\ThrowsHttpResponseExceptionMiddleware;

beforeEach(function (): void {
    Route::middleware([AssignRequestId::class])->prefix('api/v1')->group(function (): void {
        Route::get('/_probe/method-mismatch', fn () => response()->json(['ok' => true]));

        Route::post('/_probe/validate', function (Request $request) {
            $request->validate(['name' => ['required', 'string']]);

            return response()->json(['ok' => true]);
        });

        Route::get('/_probe/boom', function (): never {
            throw new RuntimeException('boom — must never leak into the response body');
        });

        // Defence-in-depth mapping: this API's own RateLimitPublicApi
        // middleware never throws this (it builds its 429 Problem
        // directly), but Laravel's built-in throttle middleware — or any
        // future code — might, so PublicApiExceptionRenderer maps it too.
        Route::get('/_probe/throttled', function (): never {
            throw new TooManyRequestsHttpException(5, 'Too Many Attempts.');
        });

        // Step 3 review follow-up 8: a Gate/policy denial thrown as a raw
        // AuthorizationException (e.g. Gate::authorize()) — Laravel's OWN
        // exception handler converts this to AccessDeniedHttpException
        // BEFORE any render() callback ever sees it (see
        // Illuminate\Foundation\Exceptions\Handler::prepareException()),
        // so this probe exercises the REAL end-to-end conversion, not a
        // direct call to the renderer.
        Route::get('/_probe/authz', function (): never {
            throw new AuthorizationException('This action is unauthorized.');
        });

        // Step 3 review follow-up 10: a generic HttpExceptionInterface
        // (neither a 404/405 nor TooManyRequestsHttpException) must keep
        // its OWN headers — e.g. Retry-After on a 503 maintenance response.
        Route::get('/_probe/maintenance', function (): never {
            throw new HttpException(503, 'Maintenance.', null, ['Retry-After' => '120']);
        });

        // Step 3 review follow-up 9 — see ThrowsHttpResponseExceptionMiddleware's
        // own docblock for why this must throw from MIDDLEWARE, not a route action.
        Route::get('/_probe/http-response-exception', fn () => response()->json(['ok' => true]))
            ->middleware(ThrowsHttpResponseExceptionMiddleware::class);
    });

    Route::middleware([AssignRequestId::class, RejectApiKeyInQuery::class, AuthenticatePublicApi::class])
        ->prefix('api/v1')
        ->group(function (): void {
            Route::get('/_probe/auth', fn () => response()->json(['ok' => true]));

            Route::get('/_probe/scope', fn () => response()->json(['ok' => true]))
                ->middleware('scope:interviews:read');
        });
});

test('T-CONV-004: an unknown /v1 route → 404 not_found, problem+json valid', function (): void {
    $response = $this->getJson('/api/v1/_probe/does-not-exist-at-all');

    $response->assertStatus(404)->assertJsonPath('code', 'not_found');
    $this->assertProblemMatchesContract($response, 404);
});

test('T-CONV-004: a wrong HTTP method on a known /v1 route → 404 not_found (G-27), problem+json valid', function (): void {
    $response = $this->postJson('/api/v1/_probe/method-mismatch', []);

    $response->assertStatus(404)->assertJsonPath('code', 'not_found');
    $this->assertProblemMatchesContract($response, 404);
});

test('T-CONV-004: a failed request validation → 422 validation_failed with a per-field errors[] entry, problem+json valid', function (): void {
    $response = $this->postJson('/api/v1/_probe/validate', []);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.0.field', 'name')
        ->assertJsonPath('errors.0.code', 'required');
    $this->assertProblemMatchesContract($response, 422);
});

test('T-CONV-004: an unhandled exception → 500 internal_error with no leaked detail, problem+json valid', function (): void {
    $response = $this->getJson('/api/v1/_probe/boom');

    $response->assertStatus(500)->assertJsonPath('code', 'internal_error');
    expect($response->json())->not->toHaveKey('detail');
    $body = (string) $response->getContent();
    expect($body)->not->toContain('boom');
    expect($body)->not->toContain('RuntimeException');
    $this->assertProblemMatchesContract($response, 500);
});

test('T-CONV-004: a missing bearer key → 401 invalid_api_key, problem+json valid', function (): void {
    $response = $this->getJson('/api/v1/_probe/auth');

    $response->assertStatus(401)->assertJsonPath('code', 'invalid_api_key');
    $this->assertProblemMatchesContract($response, 401);
});

test('T-CONV-004: a thrown TooManyRequestsHttpException → 429 rate_limited with Retry-After, problem+json valid', function (): void {
    $response = $this->getJson('/api/v1/_probe/throttled');

    $response->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited')
        ->assertHeader('Retry-After', '5');
    $this->assertProblemMatchesContract($response, 429);
});

test('T-CONV-004: a missing scope → 403 insufficient_scope, problem+json valid', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/_probe/scope');

    $response->assertStatus(403)->assertJsonPath('code', 'insufficient_scope');
    $this->assertProblemMatchesContract($response, 403);
});

// ─── Step 3 review follow-ups (items 8-10) ─────────────────────────────────

test('review follow-up 8 (G-31): a policy AuthorizationException on /v1 renders 404 not_found, never a misleading 403', function (): void {
    $response = $this->getJson('/api/v1/_probe/authz');

    $response->assertStatus(404)->assertJsonPath('code', 'not_found');
    $this->assertProblemMatchesContract($response, 404);
});

test('review follow-up 9: HttpResponseException passes through untouched — abort(response()) on /v1 is never turned into a 500', function (): void {
    $response = $this->getJson('/api/v1/_probe/http-response-exception');

    $response->assertStatus(418)->assertJson(['custom' => 'shape']);
});

test('review follow-up 10: a generic HttpException on /v1 keeps its own headers (e.g. Retry-After on a 503)', function (): void {
    $response = $this->getJson('/api/v1/_probe/maintenance');

    $response->assertStatus(503)
        ->assertJsonPath('code', 'internal_error')
        ->assertHeader('Retry-After', '120');
    $this->assertProblemMatchesContract($response, 503);
});
