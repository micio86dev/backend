<?php

declare(strict_types=1);

/**
 * AvatarProviderCatalogue — normalizes Tavus and HeyGen/LiveAvatar's own
 * inventory into one shared catalogue shape, cached and failure-safe
 * (avatar-template-catalogue PR1, design D1/D3/D4).
 *
 * Every provider call is FAKED. Tavus and LiveAvatar are real, billed,
 * rate-limited vendor accounts (CLAUDE.md hard constraint) — a live request
 * from a test would be a defect, never a shortcut.
 *
 * Field names (`voice_id`/`voice_name` for Tavus, `id`/`name`/`language` for
 * HeyGen, etc.) are confirmed against each provider's own published API
 * schema (Tavus `openapi.yaml`, LiveAvatar `openapi.json`) plus this
 * project's own live-queried findings in `proposal.md` — never guessed.
 *
 * REQ: avatar-templates "Provider catalogue is fetchable, cached,
 *      admin-only, and never leaks a secret"
 */

use App\Support\AvatarTemplates\AvatarProviderCatalogue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'interview.tavus.api_key' => 'SUPER_SECRET_TAVUS_KEY_12345',
        'interview.heygen.api_key' => 'SUPER_SECRET_HEYGEN_KEY_67890',
    ]);
});

// ─── 1.1 — Tavus voices: no language field on the resource, always null ───

test('Tavus voices normalize with language always null — Tavus has no language field at all', function (): void {
    Http::fake([
        'tavusapi.com/v2/voices*' => Http::response([
            'data' => [
                ['voice_id' => 'v1', 'voice_name' => 'Alessandra', 'status' => 'completed'],
                ['voice_id' => 'v2', 'voice_name' => 'Marco', 'status' => 'completed'],
            ],
            'total_count' => 2,
        ], 200),
    ]);

    $result = AvatarProviderCatalogue::fetch('tavus', 'voice');

    expect($result)->toBe([
        'status' => 'ok',
        'items' => [
            ['id' => 'v1', 'label' => 'Alessandra', 'language' => null, 'preview_image_url' => null, 'preview_audio_url' => null],
            ['id' => 'v2', 'label' => 'Marco', 'language' => null, 'preview_image_url' => null, 'preview_audio_url' => null],
        ],
    ]);
});

// ─── 1.2 — Tavus replicas: preview fields sourced from the thumbnail pair ──

test('Tavus replicas normalize preview_image_url/preview_audio_url from thumbnail_image_url/thumbnail_video_url', function (): void {
    Http::fake([
        'tavusapi.com/v2/replicas*' => Http::response([
            'data' => [[
                'replica_id' => 'r1',
                'replica_name' => 'Face One',
                'replica_type' => 'system',
                'tags' => [],
                'thumbnail_image_url' => 'https://cdn.replica.tavus.io/r1/thumb.jpg',
                'thumbnail_video_url' => 'https://cdn.replica.tavus.io/r1/thumb.mp4',
                'default_voice_id' => 'v1',
            ]],
            'total_count' => 1,
        ], 200),
    ]);

    $result = AvatarProviderCatalogue::fetch('tavus', 'replica');

    expect($result)->toBe([
        'status' => 'ok',
        'items' => [[
            'id' => 'r1',
            'label' => 'Face One',
            'language' => null,
            'preview_image_url' => 'https://cdn.replica.tavus.io/r1/thumb.jpg',
            'preview_audio_url' => 'https://cdn.replica.tavus.io/r1/thumb.mp4',
        ]],
    ]);
});

// ─── 1.3 — HeyGen voices: language verbatim, never guessed ────────────────

test('HeyGen voices normalize with language populated verbatim from the provider — never guessed, never defaulted', function (): void {
    Http::fake([
        'api.liveavatar.com/v1/voices*' => Http::response([
            'code' => 100,
            'data' => [
                'count' => 2,
                'results' => [
                    // The exact production accent bug this change exists to catch:
                    // an Italian-sounding NAME on an English-tagged voice.
                    ['id' => 'hv1', 'name' => 'Alessandra - IA', 'language' => 'en'],
                    ['id' => 'hv2', 'name' => 'Marco', 'language' => 'it'],
                ],
            ],
            'message' => 'ok',
        ], 200),
    ]);

    $result = AvatarProviderCatalogue::fetch('heygen', 'voice');

    expect($result)->toBe([
        'status' => 'ok',
        'items' => [
            ['id' => 'hv1', 'label' => 'Alessandra - IA', 'language' => 'en', 'preview_image_url' => null, 'preview_audio_url' => null],
            ['id' => 'hv2', 'label' => 'Marco', 'language' => 'it', 'preview_image_url' => null, 'preview_audio_url' => null],
        ],
    ]);
});

// ─── 1.4 — HeyGen avatars: preview_image_url from preview_url ─────────────

test('HeyGen avatars normalize preview_image_url from preview_url', function (): void {
    Http::fake([
        'api.liveavatar.com/v1/avatars*' => Http::response([
            'code' => 100,
            'data' => [
                'count' => 1,
                'results' => [
                    ['id' => 'ha1', 'name' => 'Studio Avatar', 'preview_url' => 'https://cdn.liveavatar.com/ha1.png'],
                ],
            ],
            'message' => 'ok',
        ], 200),
    ]);

    $result = AvatarProviderCatalogue::fetch('heygen', 'avatar');

    expect($result)->toBe([
        'status' => 'ok',
        'items' => [[
            'id' => 'ha1',
            'label' => 'Studio Avatar',
            'language' => null,
            'preview_image_url' => 'https://cdn.liveavatar.com/ha1.png',
            'preview_audio_url' => null,
        ]],
    ]);
});

// ─── 1.6 — cached: a second call within the TTL makes zero HTTP requests ──

test('a second fetch() call for the same (provider, resource) within the TTL makes zero additional HTTP requests', function (): void {
    Http::fake([
        'tavusapi.com/v2/voices*' => Http::response([
            'data' => [['voice_id' => 'v1', 'voice_name' => 'One']],
            'total_count' => 1,
        ], 200),
    ]);

    AvatarProviderCatalogue::fetch('tavus', 'voice');
    AvatarProviderCatalogue::fetch('tavus', 'voice');
    AvatarProviderCatalogue::fetch('tavus', 'voice');

    Http::assertSentCount(1);
});

test('the cache is scoped per (provider, resource) — a different pair still fetches live', function (): void {
    Http::fake([
        'tavusapi.com/v2/voices*' => Http::response(['data' => [], 'total_count' => 0], 200),
        'tavusapi.com/v2/replicas*' => Http::response(['data' => [], 'total_count' => 0], 200),
    ]);

    AvatarProviderCatalogue::fetch('tavus', 'voice');
    AvatarProviderCatalogue::fetch('tavus', 'replica');

    Http::assertSentCount(2);
});

// ─── 1.8 — a provider failure degrades, and is never cached ───────────────

test('a provider HTTP failure does not throw, returns the unavailable shape, and is not cached — the next call retries', function (): void {
    Http::fake([
        'tavusapi.com/v2/voices*' => Http::response(['message' => 'internal error'], 500),
    ]);

    $result = AvatarProviderCatalogue::fetch('tavus', 'voice');

    expect($result)->toBe(['status' => 'unavailable', 'items' => []]);
    Http::assertSentCount(1);

    // Right after the failure: a second call retries the provider rather
    // than replaying the failure for the rest of the 24h TTL (D3) — the
    // failure was never written to cache.
    AvatarProviderCatalogue::fetch('tavus', 'voice');
    Http::assertSentCount(2);
});

test('a provider connection failure (timeout) also degrades to the unavailable shape without throwing', function (): void {
    Http::fake(function (): never {
        throw new ConnectionException('Connection timed out');
    });

    $result = AvatarProviderCatalogue::fetch('heygen', 'voice');

    expect($result)->toBe(['status' => 'unavailable', 'items' => []]);
});

// ─── 1.10 — no API key ever reaches the response, success or failure ──────

test('no response from fetch(), on success or failure, contains the configured API key as a substring', function (): void {
    Http::fake([
        'tavusapi.com/v2/voices*' => Http::response([
            'message' => 'Unauthorized: key SUPER_SECRET_TAVUS_KEY_12345 is invalid',
        ], 401),
    ]);

    $failure = AvatarProviderCatalogue::fetch('tavus', 'voice');

    expect(json_encode($failure))->not->toContain('SUPER_SECRET_TAVUS_KEY_12345');

    Http::fake([
        'api.liveavatar.com/v1/voices*' => Http::response([
            'code' => 100,
            'data' => ['count' => 1, 'results' => [['id' => 'hv1', 'name' => 'One', 'language' => 'en']]],
            'message' => 'ok',
        ], 200),
    ]);

    $success = AvatarProviderCatalogue::fetch('heygen', 'voice');

    expect(json_encode($success))->not->toContain('SUPER_SECRET_HEYGEN_KEY_67890');
});
