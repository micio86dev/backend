<?php

declare(strict_types=1);

/**
 * BEAI Public API (`/v1`) metadata limits at the HTTP level (public-api
 * step 3) — SPEC.md §3.2 "Metadata". T-CONV-011.
 *
 * Unit-level rule coverage (every boundary, in isolation) lives in
 * tests/Unit/PublicApi/MetadataRuleTest.php; these prove the Problem shape
 * end to end: 422 validation_failed, with a metadata_limit_exceeded
 * per-field code.
 */

use App\Http\Middleware\PublicApi\AssignRequestId;
use App\Rules\PublicApi\Metadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware([AssignRequestId::class])->prefix('api/v1')->group(function (): void {
        Route::post('/_probe/metadata', function (Request $request) {
            $request->validate(['metadata' => ['sometimes', new Metadata]]);

            return response()->json(['ok' => true]);
        });
    });
});

test('T-CONV-011: 21 metadata keys → 422 validation_failed, metadata_limit_exceeded', function (): void {
    $metadata = [];
    foreach (range(1, 21) as $i) {
        $metadata["k{$i}"] = 'v';
    }

    $response = $this->postJson('/api/v1/_probe/metadata', ['metadata' => $metadata]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.0.field', 'metadata')
        ->assertJsonPath('errors.0.code', 'metadata_limit_exceeded');
    $this->assertProblemMatchesContract($response, 422);
});

test('T-CONV-011: a 41-char metadata key → 422 validation_failed, metadata_limit_exceeded', function (): void {
    $response = $this->postJson('/api/v1/_probe/metadata', [
        'metadata' => [str_repeat('k', 41) => 'value'],
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.0.field', 'metadata')
        ->assertJsonPath('errors.0.code', 'metadata_limit_exceeded');
});

test('T-CONV-011: a 501-char metadata value → 422 validation_failed, metadata_limit_exceeded', function (): void {
    $response = $this->postJson('/api/v1/_probe/metadata', [
        'metadata' => ['k' => str_repeat('v', 501)],
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.0.field', 'metadata')
        ->assertJsonPath('errors.0.code', 'metadata_limit_exceeded');
});

test('T-CONV-011: a non-string metadata value → 422 validation_failed, metadata_limit_exceeded', function (): void {
    $response = $this->postJson('/api/v1/_probe/metadata', [
        'metadata' => ['k' => 12345],
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.0.field', 'metadata')
        ->assertJsonPath('errors.0.code', 'metadata_limit_exceeded');
});

test('T-CONV-011: metadata within every limit passes', function (): void {
    $response = $this->postJson('/api/v1/_probe/metadata', [
        'metadata' => ['ats_application_id' => 'A-4471'],
    ]);

    $response->assertOk();
});
