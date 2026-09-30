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

/**
 * The one item shape, with every key present.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function catalogueItem(string $provider, string $id, string $name, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'provider' => $provider,
        'label' => $name,
        'name' => $name,
        'language' => null,
        'locale' => null,
        'accent' => null,
        'italian' => null,
        'preview_image_url' => null,
        'preview_audio_url' => null,
        'preview_video_url' => null,
    ], $overrides);
}

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
            catalogueItem('tavus', 'v1', 'Alessandra'),
            catalogueItem('tavus', 'v2', 'Marco'),
        ],
    ]);
});

// ─── 1.2 — Tavus replicas: preview fields sourced from the thumbnail pair ──

test('Tavus faces (the former replicas) normalize the thumbnail video into preview_video_url and skip untrained faces', function (): void {
    Http::fake([
        'tavusapi.com/v2/faces*' => Http::response([
            'data' => [
                [
                    'face_id' => 'r1',
                    'face_name' => 'Face One',
                    'status' => 'completed',
                    'thumbnail_video_url' => 'https://cdn.replica.tavus.io/r1/thumb.mp4',
                    'default_voice_id' => 'v1',
                ],
                ['face_id' => 'r2', 'face_name' => 'Still Training', 'status' => 'started'],
                ['face_id' => 'r3', 'face_name' => 'Broken', 'status' => 'error'],
            ],
            'total_count' => 3,
        ], 200),
    ]);

    $result = AvatarProviderCatalogue::fetch('tavus', 'replica');

    expect($result)->toBe([
        'status' => 'ok',
        'items' => [catalogueItem('tavus', 'r1', 'Face One', [
            'preview_video_url' => 'https://cdn.replica.tavus.io/r1/thumb.mp4',
        ])],
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
            // Provider-tagged Italian voices sort first.
            catalogueItem('heygen', 'hv2', 'Marco', ['language' => 'it', 'locale' => 'it', 'italian' => 'native']),
            catalogueItem('heygen', 'hv1', 'Alessandra - IA', ['language' => 'en', 'locale' => 'en']),
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
        'items' => [catalogueItem('heygen', 'ha1', 'Studio Avatar', [
            'preview_image_url' => 'https://cdn.liveavatar.com/ha1.png',
        ])],
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

test('a provider HTTP failure does not throw, returns a provider_error state, and is not cached — the next call retries', function (): void {
    Http::fake([
        'tavusapi.com/v2/voices*' => Http::response(['message' => 'internal error'], 500),
    ]);

    $result = AvatarProviderCatalogue::fetch('tavus', 'voice');

    expect($result)->toBe(['status' => 'provider_error', 'items' => [], 'code' => 'provider_unavailable']);
    Http::assertSentCount(1);

    // Right after the failure: a second call retries the provider rather
    // than replaying the failure for the rest of the 24h TTL (D3) — the
    // failure was never written to cache.
    AvatarProviderCatalogue::fetch('tavus', 'voice');
    Http::assertSentCount(2);
});

test('a provider connection failure (timeout) also degrades to a provider_error state without throwing', function (): void {
    Http::fake(function (): never {
        throw new ConnectionException('Connection timed out');
    });

    $result = AvatarProviderCatalogue::fetch('heygen', 'voice');

    expect($result)->toBe(['status' => 'provider_error', 'items' => [], 'code' => 'provider_unreachable']);
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

// ─── States, pagination, HeyGen public + own avatars ─────────────────────

test('an empty answer is its own state, and is not cached', function (): void {
    Http::fake(['tavusapi.com/v2/voices*' => Http::response(['data' => [], 'total_count' => 0], 200)]);

    expect(AvatarProviderCatalogue::fetch('tavus', 'voice'))->toBe(['status' => 'empty', 'items' => []]);

    AvatarProviderCatalogue::fetch('tavus', 'voice');
    Http::assertSentCount(2);
});

test('provider failures map to distinct safe codes', function (int $status, string $code): void {
    Http::fake(['tavusapi.com/v2/voices*' => Http::response(['message' => 'SUPER_SECRET_TAVUS_KEY_12345'], $status)]);

    $result = AvatarProviderCatalogue::fetch('tavus', 'voice');

    expect($result)->toBe(['status' => 'provider_error', 'items' => [], 'code' => $code]);
    expect(json_encode($result))->not->toContain('SUPER_SECRET');
})->with([
    [401, 'provider_unauthorized'],
    [403, 'provider_unauthorized'],
    [429, 'provider_rate_limited'],
    [503, 'provider_unavailable'],
    [404, 'provider_rejected'],
]);

test('a missing platform key is reported as such without calling the provider', function (): void {
    config(['interview.heygen.api_key' => '']);
    Http::fake();

    expect(AvatarProviderCatalogue::fetch('heygen', 'avatar'))
        ->toBe(['status' => 'provider_error', 'items' => [], 'code' => 'provider_key_missing']);
    Http::assertNothingSent();
});

test('Tavus voices are paginated past the provider default of ten', function (): void {
    $page1 = array_map(fn (int $i): array => ['voice_id' => "a{$i}", 'voice_name' => "A{$i}", 'status' => 'completed'], range(1, 100));

    Http::fake([
        'tavusapi.com/v2/voices*' => Http::sequence()
            ->push(['data' => $page1, 'total_count' => 101], 200)
            ->push(['data' => [['voice_id' => 'b1', 'voice_name' => 'B1', 'status' => 'completed']], 'total_count' => 101], 200),
    ]);

    expect(AvatarProviderCatalogue::fetch('tavus', 'voice')['items'])->toHaveCount(101);
    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'limit=100') && str_contains($r->url(), 'source=all'));
});

test('HeyGen avatars merge the public catalogue with the account\'s own, drop expired ones and dedupe', function (): void {
    Http::fake([
        'api.liveavatar.com/v1/avatars/public*' => Http::response(['code' => 100, 'data' => ['count' => 3, 'next' => null, 'results' => [
            ['id' => 'p1', 'name' => 'Public One', 'status' => 'ACTIVE', 'is_expired' => false, 'preview_url' => 'https://cdn.example/p1.png'],
            ['id' => 'p2', 'name' => 'Expired', 'status' => 'ACTIVE', 'is_expired' => true],
            ['id' => 'p3', 'name' => 'Failed', 'status' => 'FAILED', 'is_expired' => false],
        ]]], 200),
        'api.liveavatar.com/v1/avatars*' => Http::response(['code' => 100, 'data' => ['count' => 2, 'next' => null, 'results' => [
            ['id' => 'u1', 'name' => 'Mine', 'status' => 'ACTIVE', 'is_expired' => false, 'preview_url' => 'https://cdn.example/u1.png'],
            ['id' => 'p1', 'name' => 'Public One', 'status' => 'ACTIVE', 'is_expired' => false],
        ]]], 200),
    ]);

    $result = AvatarProviderCatalogue::fetch('heygen', 'avatar');

    expect($result['status'])->toBe('ok');
    expect(array_column($result['items'], 'id'))->toBe(['p1', 'u1']);
    expect($result['items'][0]['preview_image_url'])->toBe('https://cdn.example/p1.png');
    Http::assertSent(fn ($r): bool => str_contains($r->url(), '/avatars/public') && str_contains($r->url(), 'page_size=100'));
});

test('HeyGen still lists public avatars when the account\'s own list is refused', function (): void {
    Http::fake([
        'api.liveavatar.com/v1/avatars/public*' => Http::response(['code' => 100, 'data' => ['count' => 1, 'next' => null, 'results' => [
            ['id' => 'p1', 'name' => 'Public One', 'status' => 'ACTIVE'],
        ]]], 200),
        'api.liveavatar.com/v1/avatars*' => Http::response(['message' => 'nope'], 403),
    ]);

    expect(AvatarProviderCatalogue::fetch('heygen', 'avatar')['items'])->toHaveCount(1);
});

test('HeyGen pagination follows next until it is empty', function (): void {
    Http::fake([
        'api.liveavatar.com/v1/avatars/public*' => Http::sequence()
            ->push(['data' => ['next' => 'https://api.liveavatar.com/v1/avatars/public?page=2', 'results' => [['id' => 'p1', 'name' => 'A']]]], 200)
            ->push(['data' => ['next' => null, 'results' => [['id' => 'p2', 'name' => 'B']]]], 200),
        'api.liveavatar.com/v1/avatars*' => Http::response(['data' => ['results' => []]], 200),
    ]);

    expect(array_column(AvatarProviderCatalogue::fetch('heygen', 'avatar')['items'], 'id'))->toBe(['p1', 'p2']);
});

test('HeyGen voices include private (third-party bound) voices', function (): void {
    Http::fake([
        'api.liveavatar.com/v1/voices*' => Http::sequence()
            ->push(['data' => ['next' => null, 'results' => [['id' => 'hv1', 'name' => 'Pub', 'language' => 'en']]]], 200)
            ->push(['data' => ['next' => null, 'results' => [['id' => 'hv9', 'name' => 'Mine', 'language' => 'it']]]], 200),
    ]);

    $items = AvatarProviderCatalogue::fetch('heygen', 'voice')['items'];

    expect(array_column($items, 'id'))->toBe(['hv9', 'hv1']);
    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'voice_type=private'));
});

test('Tavus PALs list only id, name and editable — never the system prompt or layers', function (): void {
    Http::fake([
        'tavusapi.com/v2/pals*' => Http::response([
            'data' => [[
                'pal_id' => 'p1', 'pal_name' => 'Interviewer', 'default_face_id' => 'f1',
                'system_prompt' => 'CONFIDENTIAL PROMPT', 'layers' => ['llm' => ['api_key' => 'SECRET_LAYER_KEY']],
            ]],
            'total_count' => 1,
        ], 200),
    ]);

    $result = AvatarProviderCatalogue::fetch('tavus', 'pal');

    // The wildcard stub also answers the persona_type lists, so p1 reads as the
    // account's own. The editable rules themselves live in TavusPalEditableCatalogueTest.
    expect($result)->toBe(['status' => 'ok', 'items' => [catalogueItem('tavus', 'p1', 'Interviewer', ['editable' => true])]]);
    expect(json_encode($result))->not->toContain('CONFIDENTIAL')->not->toContain('SECRET_LAYER_KEY');
});
