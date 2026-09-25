<?php

declare(strict_types=1);

/**
 * BEAI Public API (`/v1`) request-id middleware (public-api step 3) —
 * SPEC.md §3.2 "Every response carries `X-Request-Id`. Clients may send
 * their own; it is echoed and logged."
 *
 * T-CONV-005: client-provided valid id is echoed verbatim; an invalid one is
 * replaced with a generated id; an absent one gets a generated id; the
 * header always equals the body's `request_id` on an error response.
 */

use App\Http\Middleware\PublicApi\AssignRequestId;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware([AssignRequestId::class])->prefix('api/v1')->group(function (): void {
        Route::get('/_probe/ok', fn () => response()->json(['ok' => true]));

        Route::get('/_probe/error', function () {
            abort(400, 'boom');
        });
    });
});

test('T-CONV-005: a valid client-supplied X-Request-Id is echoed verbatim', function (): void {
    $response = $this->withHeaders(['X-Request-Id' => 'client-abc123.def-456'])
        ->getJson('/api/v1/_probe/ok');

    $response->assertOk()->assertHeader('X-Request-Id', 'client-abc123.def-456');
});

test('T-CONV-005: an absent X-Request-Id gets a generated req_ id', function (): void {
    $response = $this->getJson('/api/v1/_probe/ok');

    $response->assertOk();
    $requestId = $response->headers->get('X-Request-Id');

    expect($requestId)->toStartWith('req_');
    expect(strtolower((string) $requestId))->toBe((string) $requestId);
});

test('T-CONV-005: an invalid X-Request-Id (disallowed characters) is replaced with a generated one, not echoed', function (): void {
    $response = $this->withHeaders(['X-Request-Id' => "bad id\r\nwith,commas and spaces"])
        ->getJson('/api/v1/_probe/ok');

    $response->assertOk();
    $requestId = $response->headers->get('X-Request-Id');

    expect($requestId)->toStartWith('req_');
    expect($requestId)->not->toContain(' ');
});

test('T-CONV-005: an X-Request-Id over 128 chars is replaced with a generated one', function (): void {
    $tooLong = str_repeat('a', 129);

    $response = $this->withHeaders(['X-Request-Id' => $tooLong])
        ->getJson('/api/v1/_probe/ok');

    $response->assertOk();
    $requestId = $response->headers->get('X-Request-Id');

    expect($requestId)->not->toBe($tooLong);
    expect($requestId)->toStartWith('req_');
});

test('T-CONV-005: on an error response, the X-Request-Id header equals the body request_id field', function (): void {
    $response = $this->withHeaders(['X-Request-Id' => 'client-error-probe'])
        ->getJson('/api/v1/_probe/error');

    $response->assertStatus(400);
    expect($response->headers->get('X-Request-Id'))->toBe('client-error-probe');
    expect($response->json('request_id'))->toBe('client-error-probe');
});
