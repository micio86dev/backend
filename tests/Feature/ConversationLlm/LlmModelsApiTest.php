<?php

declare(strict_types=1);

/**
 * GET /api/llm-models (pluggable-conversation-llm PR P1, design D9).
 *
 * A public price list, not a secret — every one of the three authorization
 * roles reads it. There is no Spatie permission gate here (this codebase has
 * none) and no ownership to authorize: `llm_models` is global, so hiding it
 * from the operator who has to explain a cost line is pointless.
 *
 * REQ: conversation-llm "The model registry is global, upserted, and carries
 *      a per-request context pricing tier" (D9's readability note)
 */

use App\Models\Organization;
use Database\Seeders\LlmModelRegistrySeeder;

test('an unauthenticated caller gets 401', function (): void {
    $this->getJson('/api/llm-models')->assertUnauthorized();
});

test('admin, operator, and viewer can all read the registry', function (): void {
    (new LlmModelRegistrySeeder)->run();
    $org = Organization::factory()->create();

    foreach (['admin', 'operator', 'viewer'] as $role) {
        $this->withToken(authTokenForRole($org, $role))
            ->getJson('/api/llm-models')
            ->assertOk()
            ->assertJsonCount(4, 'data');

        resetAuthGuardState();
    }
});

test('each row carries the fields a picker and a price list both need', function (): void {
    (new LlmModelRegistrySeeder)->run();
    $org = Organization::factory()->create();

    $response = $this->withToken(authTokenForRole($org, 'operator'))
        ->getJson('/api/llm-models')
        ->assertOk();

    $row = collect($response->json('data'))->firstWhere('key', 'gemini-3.1-pro-preview');

    expect($row)->not->toBeNull();
    expect($row['vendor'])->toBe('google');
    expect($row['capability'])->toBe('text');
    expect($row['mode'])->toBe('managed');
    expect($row['is_available'])->toBeTrue();
    expect($row['context_tier_threshold_tokens'])->toBe(200000);
    expect($row['text_input_usd_per_million_high'])->toBe('4.000000');

    // Genuinely unpublished — must be null, never coerced to a number.
    expect($row['audio_input_usd_per_million'])->toBeNull();
});

test('a native_duplex model reports the native_duplex mode', function (): void {
    (new LlmModelRegistrySeeder)->run();
    $org = Organization::factory()->create();

    $response = $this->withToken(authTokenForRole($org, 'viewer'))
        ->getJson('/api/llm-models')
        ->assertOk();

    $row = collect($response->json('data'))->firstWhere('key', 'gemini-3.1-flash-live-preview');

    expect($row['mode'])->toBe('native_duplex');
});
