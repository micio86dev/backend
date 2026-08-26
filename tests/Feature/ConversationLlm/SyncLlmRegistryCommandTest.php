<?php

declare(strict_types=1);

/**
 * beai:sync-llm-registry (pluggable-conversation-llm PR P1, design D1).
 *
 * The ONLY production path that populates or refreshes `llm_models` —
 * production never runs `db:seed`. Idempotent, no-TTY-safe, and reports an
 * added/updated/marked-unavailable diff so an operator running it by hand
 * can see what changed.
 *
 * REQ: conversation-llm "Registry sync runs via a console command, never
 *      db:seed"
 */

use App\Models\LlmModel;

test('running the sync command twice yields an identical row set', function (): void {
    $this->artisan('beai:sync-llm-registry')->assertSuccessful();
    $firstRun = LlmModel::orderBy('key')->get(['id', 'key', 'updated_at'])->toArray();

    $this->artisan('beai:sync-llm-registry')->assertSuccessful();
    $secondRun = LlmModel::orderBy('key')->get(['id', 'key', 'updated_at'])->toArray();

    expect($secondRun)->toBe($firstRun);
    expect(LlmModel::count())->toBe(4);
});

test('the first run reports every seeded key as added', function (): void {
    $this->artisan('beai:sync-llm-registry')
        ->expectsOutputToContain('added: gemini-3-flash-preview')
        ->expectsOutputToContain('added: gemini-3.1-pro-preview')
        ->expectsOutputToContain('added: gemini-3.1-flash-live-preview')
        ->expectsOutputToContain('added: gemini-2.5-flash-native-audio-preview-12-2025')
        ->assertSuccessful();
});

test('a second run with no seed-array change reports every key as updated, none added', function (): void {
    $this->artisan('beai:sync-llm-registry')->assertSuccessful();

    $this->artisan('beai:sync-llm-registry')
        ->doesntExpectOutputToContain('added:')
        ->expectsOutputToContain('updated: gemini-3-flash-preview')
        ->assertSuccessful();
});

test('the command runs successfully with no TTY (every input is a flag, none is a prompt)', function (): void {
    // No --option is required at all — the signature accepts none, so this
    // is inherently no-TTY-safe. Asserted explicitly because that safety
    // property is easy to break silently by adding a future ask().
    $this->artisan('beai:sync-llm-registry', ['--no-interaction' => true])->assertSuccessful();
});
