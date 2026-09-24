<?php

declare(strict_types=1);

/**
 * BEAI Public API (`/v1`) `Idempotency-Key` support (public-api step 3) —
 * SPEC.md §3.2 "Idempotency".
 *
 * T-CONV-008: replay returns the ORIGINAL status/body and
 * `Idempotent-Replayed: true`, without re-executing the handler.
 * T-CONV-009: same key + a different body → 409 `idempotency_key_reused`.
 * T-CONV-010: a concurrent duplicate (lock already held) → 409
 * `idempotency_in_progress`.
 */

use App\Enums\ApiKeyMode;
use App\Http\Middleware\PublicApi\AssignRequestId;
use App\Http\Middleware\PublicApi\AuthenticatePublicApi;
use App\Http\Middleware\PublicApi\IdempotencyKey;
use App\Http\Middleware\PublicApi\PublicApiTenantContext;
use App\Http\Middleware\PublicApi\RejectApiKeyInQuery;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Services\ApiKeyGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware([
        AssignRequestId::class,
        RejectApiKeyInQuery::class,
        AuthenticatePublicApi::class,
        PublicApiTenantContext::class,
        IdempotencyKey::class,
    ])->prefix('api/v1')->group(function (): void {
        Route::post('/_probe/idempotent', function (Request $request) {
            $callNumber = Cache::increment('idempotent_probe_calls');

            return response()->json(['call_number' => $callNumber, 'received' => $request->all()], 201);
        });
    });
});

test('T-CONV-008: a replayed request returns the original status/body and Idempotent-Replayed: true, without re-executing the handler', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id]);

    $headers = ['Authorization' => 'Bearer '.$rawKey, 'Idempotency-Key' => 'replay-key-1'];
    $payload = ['a' => 1];

    $first = $this->withHeaders($headers)->postJson('/api/v1/_probe/idempotent', $payload);
    $first->assertCreated();
    expect($first->headers->get('Idempotent-Replayed'))->toBeNull();
    $firstCallNumber = $first->json('call_number');

    $second = $this->withHeaders($headers)->postJson('/api/v1/_probe/idempotent', $payload);

    $second->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('call_number', $firstCallNumber);

    expect($second->json())->toBe($first->json());
});

test('T-CONV-009: the same key with a different body → 409 idempotency_key_reused, problem+json valid', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id]);

    $headers = ['Authorization' => 'Bearer '.$rawKey, 'Idempotency-Key' => 'reused-key-1'];

    $this->withHeaders($headers)->postJson('/api/v1/_probe/idempotent', ['a' => 1])->assertCreated();

    $response = $this->withHeaders($headers)->postJson('/api/v1/_probe/idempotent', ['a' => 2]);

    $response->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
    $this->assertProblemMatchesContract($response, 409);
});

test('T-CONV-010: a concurrent duplicate (lock already held) → 409 idempotency_in_progress, problem+json valid', function (): void {
    // A fast wait for this test only — the SPEC-mandated 5s default stays
    // for production; see config/public_api.php's own docblock.
    config(['public_api.idempotency.lock_wait_seconds' => 1]);

    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    $client = ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id]);

    $syntheticRequest = Request::create('/api/v1/_probe/idempotent', 'POST');
    $scope = IdempotencyKey::scopeFor($client, $syntheticRequest, 'concurrent-key-1');

    $lock = Cache::lock('idem:'.$scope, 30);
    expect($lock->get())->toBeTrue();

    try {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$rawKey,
            'Idempotency-Key' => 'concurrent-key-1',
        ])->postJson('/api/v1/_probe/idempotent', ['a' => 1]);

        $response->assertStatus(409)->assertJsonPath('code', 'idempotency_in_progress');
        $this->assertProblemMatchesContract($response, 409);
    } finally {
        $lock->release();
    }
});

test('an Idempotency-Key over 255 chars → 422 validation_failed', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$rawKey,
        'Idempotency-Key' => str_repeat('k', 256),
    ])->postJson('/api/v1/_probe/idempotent', ['a' => 1]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.0.field', 'Idempotency-Key');
});

test('no Idempotency-Key header at all → the handler runs normally, untouched', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/_probe/idempotent', ['a' => 1]);

    $response->assertCreated();
    expect($response->headers->get('Idempotent-Replayed'))->toBeNull();
});

test('a request with no resolved public_api.client → 401 invalid_api_key (defensive branch)', function (): void {
    Route::middleware([IdempotencyKey::class])->prefix('api/v1')->group(function (): void {
        Route::post('/_probe/idempotent-unauth', fn () => response()->json(['ok' => true]));
    });

    $response = $this->withHeaders(['Idempotency-Key' => 'no-client-key'])
        ->postJson('/api/v1/_probe/idempotent-unauth', []);

    $response->assertStatus(401)->assertJsonPath('code', 'invalid_api_key');
});

test('a non-2xx response is never replayed — a second identical-key request re-executes the handler', function (): void {
    Route::middleware([
        AssignRequestId::class,
        RejectApiKeyInQuery::class,
        AuthenticatePublicApi::class,
        PublicApiTenantContext::class,
        IdempotencyKey::class,
    ])->prefix('api/v1')->group(function (): void {
        Route::post('/_probe/idempotent-fails', fn () => response()->json(['error' => 'nope'], 422));
    });

    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id]);

    $headers = ['Authorization' => 'Bearer '.$rawKey, 'Idempotency-Key' => 'error-key-1'];

    $first = $this->withHeaders($headers)->postJson('/api/v1/_probe/idempotent-fails', ['a' => 1]);
    $second = $this->withHeaders($headers)->postJson('/api/v1/_probe/idempotent-fails', ['a' => 1]);

    $first->assertStatus(422);
    $second->assertStatus(422);
    expect($second->headers->get('Idempotent-Replayed'))->toBeNull();
});
