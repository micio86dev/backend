<?php

declare(strict_types=1);

/**
 * The organization-scoped HR-Avatar config catalogue (hr-avatar-adapter A4).
 *
 * `GET /api/avatar-templates/catalogue?provider=hr_avatar&resource=avatar_config` proxies HR-Avatar's
 * `GET /v1/avatar-configs`. The shared account is filtered SERVER-SIDE by `external_ref=org_<id>`
 * (reconciliation item 38) and again here, so one tenant never reads or loses to another's configs; the
 * secret key never reaches a response; a failure degrades to a safe code and is never cached.
 */

use App\Models\Organization;
use App\Support\AvatarTemplates\AvatarProviderCatalogue;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

const HRC_URL = '/api/avatar-templates/catalogue?provider=hr_avatar&resource=avatar_config';

beforeEach(function (): void {
    hraMakeAvailable();
    Cache::flush();
    Http::preventStrayRequests();
});

/** @return array{id: string, name: string, external_ref: string, sync_status: string} */
function hrcConfig(string $id, int|string $org, string $name = 'Interviewer', string $syncStatus = 'synced'): array
{
    return ['id' => $id, 'name' => $name, 'external_ref' => 'org_'.$org, 'sync_status' => $syncStatus];
}

/** @param list<array<string, mixed>> $rows */
function hrcPage(array $rows, ?string $next = null): PromiseInterface
{
    return Http::response(['data' => $rows, 'next_cursor' => $next]);
}

/** @return array<string, string> */
function hrcQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

function hrcAdmin(Organization $org): string
{
    return TemplateActors::token('admin', $org);
}

// ─── The server-side filter and the item shape ───────────────────────────────

test('every request carries the organization filter and the page size, on the first page and on every cursor page', function (): void {
    $org = Organization::factory()->create();
    Http::fake(fn (Request $request) => hrcQuery($request)['cursor'] ?? null
        ? hrcPage([hrcConfig('avc_2', $org->id)])
        : hrcPage([hrcConfig('avc_1', $org->id)], 'cur_1'));

    $this->withToken(hrcAdmin($org))->getJson(HRC_URL)->assertOk();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://hra.example.test/v1/avatar-configs?')
        && ($request->header('Authorization')[0] ?? null) === 'Bearer hra_sk_live_SECRETVALUE'
        && hrcQuery($request)['external_ref'] === 'org_'.$org->id
        && hrcQuery($request)['limit'] === '100');
    Http::assertSent(fn (Request $request): bool => (hrcQuery($request)['cursor'] ?? null) === 'cur_1'
        && hrcQuery($request)['external_ref'] === 'org_'.$org->id);
});

test('three attributed synced configs come back in the existing catalogue item shape', function (): void {
    $org = Organization::factory()->create();
    Http::fake(['*' => hrcPage([
        hrcConfig('avc_A1', $org->id, 'Ada'),
        hrcConfig('avc_A2', $org->id, 'Bruno', 'not_required'),
        hrcConfig('avc_A3', $org->id, 'Chiara'),
    ])]);

    $response = $this->withToken(hrcAdmin($org))->getJson(HRC_URL)->assertOk();

    expect($response->json('data.status'))->toBe('ok')
        ->and($response->json('data.items.0'))->toBe([
            'id' => 'avc_A1',
            'provider' => 'hr_avatar',
            'label' => 'Ada',
            'name' => 'Ada',
            'language' => null,
            'locale' => null,
            'accent' => null,
            'italian' => null,
            'preview_image_url' => null,
            'preview_audio_url' => null,
            'preview_audio_via_api' => false,
            'preview_video_url' => null,
        ])
        ->and(array_column($response->json('data.items'), 'id'))->toBe(['avc_A1', 'avc_A2', 'avc_A3']);
});

test('a config of another organization is dropped even when the upstream ignores the filter', function (): void {
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    Http::fake(['*' => hrcPage([
        hrcConfig('avc_A', $a->id, 'Mine'),
        hrcConfig('avc_B', $b->id, 'Theirs-secret-label'),
        hrcConfig('avc_PREFIX', $a->id.'0', 'Prefix neighbour'),
        ['id' => 'avc_NOREF', 'name' => 'Unattributed', 'external_ref' => null, 'sync_status' => 'synced'],
        ['id' => 'avc_NOKEY', 'name' => 'No member', 'sync_status' => 'synced'],
    ])]);

    $response = $this->withToken(hrcAdmin($a))->getJson(HRC_URL)->assertOk();

    expect(array_column($response->json('data.items'), 'id'))->toBe(['avc_A']);
    expect($response->getContent())->not->toContain('avc_B')->not->toContain('Theirs-secret-label')
        ->not->toContain('avc_NOREF')->not->toContain('avc_PREFIX')->not->toContain('avc_NOKEY');
});

test('only synced and not_required configs are offered', function (): void {
    $org = Organization::factory()->create();
    Http::fake(['*' => hrcPage([
        hrcConfig('avc_S', $org->id, 'S', 'synced'),
        hrcConfig('avc_N', $org->id, 'N', 'not_required'),
        hrcConfig('avc_P', $org->id, 'P', 'pending'),
        hrcConfig('avc_F', $org->id, 'F', 'failed'),
    ])]);

    $ids = array_column($this->withToken(hrcAdmin($org))->getJson(HRC_URL)->json('data.items'), 'id');

    expect($ids)->toBe(['avc_S', 'avc_N']);
});

test('rows without a usable id are skipped and a nameless row is labelled by its id', function (): void {
    $org = Organization::factory()->create();
    Http::fake(['*' => hrcPage([
        hrcConfig('avc_OK', $org->id, ''),
        ['name' => 'No id', 'external_ref' => 'org_'.$org->id, 'sync_status' => 'synced'],
        'not an object',
    ])]);

    $items = $this->withToken(hrcAdmin($org))->getJson(HRC_URL)->json('data.items');

    expect($items)->toHaveCount(1)->and($items[0]['label'])->toBe('avc_OK');
});

test('an empty result is reported as empty', function (): void {
    $org = Organization::factory()->create();
    Http::fake(['*' => hrcPage([hrcConfig('avc_P', $org->id, 'P', 'pending')])]);

    $this->withToken(hrcAdmin($org))->getJson(HRC_URL)->assertOk()->assertExactJson(['data' => ['status' => 'empty', 'items' => []]]);
});

// ─── Pagination ──────────────────────────────────────────────────────────────

test('pages are merged by following the cursor', function (): void {
    $org = Organization::factory()->create();
    Http::fake(fn (Request $request) => (hrcQuery($request)['cursor'] ?? null) === 'cur_1'
        ? hrcPage([hrcConfig('avc_2', $org->id)])
        : hrcPage([hrcConfig('avc_1', $org->id)], 'cur_1'));

    $ids = array_column($this->withToken(hrcAdmin($org))->getJson(HRC_URL)->json('data.items'), 'id');

    expect($ids)->toBe(['avc_1', 'avc_2']);
    Http::assertSentCount(2);
});

test('pagination stops after ten pages however long the upstream keeps going', function (): void {
    $org = Organization::factory()->create();
    Http::fake(fn () => hrcPage([hrcConfig('avc_'.uniqid(), $org->id)], 'cur_forever'));

    $this->withToken(hrcAdmin($org))->getJson(HRC_URL)->assertOk();

    Http::assertSentCount(10);
});

// ─── Failures ────────────────────────────────────────────────────────────────

test('an upstream failure degrades to a safe code and leaks neither the key nor the upstream text', function (int $status, mixed $body, string $code): void {
    $org = Organization::factory()->create();
    Http::fake(['*' => Http::response($body, $status)]);

    $response = $this->withToken(hrcAdmin($org))->getJson(HRC_URL)->assertOk();

    expect($response->json('data'))->toBe(['status' => 'provider_error', 'items' => [], 'code' => $code]);
    expect($response->getContent())->not->toContain('SECRETVALUE')->not->toContain('upstream-detail');
})->with([
    '503 echoing the key' => [503, 'upstream-detail hra_sk_live_SECRETVALUE', 'provider_unavailable'],
    '401' => [401, ['error' => 'upstream-detail'], 'provider_unauthorized'],
    '403 insufficient_scope' => [403, ['error' => ['code' => 'insufficient_scope', 'message' => 'upstream-detail']], 'provider_unauthorized'],
    '429' => [429, [], 'provider_rate_limited'],
    '400' => [400, [], 'provider_rejected'],
    'not json' => [200, 'upstream-detail', 'provider_bad_response'],
]);

test('a failure is never cached: the next call asks again', function (): void {
    $org = Organization::factory()->create();
    Http::fake(['*' => Http::sequence()->push([], 503)->push(['data' => [hrcConfig('avc_1', $org->id)], 'next_cursor' => null])]);
    $token = hrcAdmin($org);

    expect($this->withToken($token)->getJson(HRC_URL)->json('data.status'))->toBe('provider_error');
    expect($this->withToken($token)->getJson(HRC_URL)->json('data.status'))->toBe('ok');
    Http::assertSentCount(2);
});

// ─── Cache ───────────────────────────────────────────────────────────────────

test('the result is cached per organization for five minutes under the one key builder', function (): void {
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    Http::fake(fn (Request $request) => hrcPage([hrcConfig('avc_X', hrcQuery($request)['external_ref'] === 'org_'.$a->id ? $a->id : $b->id)]));
    $tokenA = hrcAdmin($a);

    $this->withToken($tokenA)->getJson(HRC_URL)->assertOk();
    $this->travel(4)->minutes();
    $this->withToken($tokenA)->getJson(HRC_URL)->assertOk();
    Http::assertSentCount(1);

    $key = AvatarProviderCatalogue::cacheKey('hr_avatar', 'avatar_config', $a->id);
    expect($key)->toEndWith(':org'.$a->id)
        ->and(Cache::get($key))->toMatchArray(['status' => 'ok'])
        ->and(Cache::has(AvatarProviderCatalogue::cacheKey('hr_avatar', 'avatar_config', $b->id)))->toBeFalse();

    $this->travel(2)->minutes();
    $this->withToken($tokenA)->getJson(HRC_URL)->assertOk();
    Http::assertSentCount(2);

    $this->withToken(hrcAdmin($b))->getJson(HRC_URL)->assertOk();
    Http::assertSentCount(3);
});

test('a fresh fetch skips the cached entry', function (): void {
    $org = Organization::factory()->create();
    Http::fake(['*' => hrcPage([hrcConfig('avc_1', $org->id)])]);

    AvatarProviderCatalogue::fetch('hr_avatar', 'avatar_config', false, $org->id);
    AvatarProviderCatalogue::fetch('hr_avatar', 'avatar_config', true, $org->id);

    Http::assertSentCount(2);
});

test('the cache key of every pre-existing catalogue is the string it always was', function (): void {
    $legacy = 'avatar-catalogue:v'.AvatarProviderCatalogue::CACHE_VERSION.':tavus:pal';

    expect(AvatarProviderCatalogue::cacheKey('tavus', 'pal'))->toBe($legacy)
        ->and(AvatarProviderCatalogue::cacheKey('tavus', 'pal', null))->toBe($legacy);
});

// ─── Access ──────────────────────────────────────────────────────────────────

test('operators and viewers are refused and nothing is sent', function (string $actor): void {
    $this->withToken(TemplateActors::token($actor, Organization::factory()->create()))->getJson(HRC_URL)->assertForbidden();

    Http::assertNothingSent();
})->with(['operator', 'viewer']);

test('the capability is absent while the integration is unavailable: 422 and nothing is sent', function (): void {
    config(['hr_avatar.secret_key' => '']);

    $this->withToken(hrcAdmin(Organization::factory()->create()))->getJson(HRC_URL)
        ->assertStatus(422)->assertJsonValidationErrors(['provider']);

    Http::assertNothingSent();
});

test('the catalogue class itself refuses to ask while the integration is unavailable', function (): void {
    config(['hr_avatar.secret_key' => '']);

    expect(AvatarProviderCatalogue::fetch('hr_avatar', 'avatar_config', false, 1))
        ->toBe(['status' => 'provider_error', 'items' => [], 'code' => 'provider_key_missing']);

    Http::assertNothingSent();
});

test('a superadmin with no organization gets an empty catalogue and nothing is sent', function (): void {
    $this->withToken(TemplateActors::token('bare', Organization::factory()->create()))->getJson(HRC_URL)
        ->assertOk()->assertExactJson(['data' => ['status' => 'empty', 'items' => []]]);

    Http::assertNothingSent();
});

test('avatar_config belongs to hr_avatar alone', function (): void {
    $token = hrcAdmin(Organization::factory()->create());

    $this->withToken($token)->getJson('/api/avatar-templates/catalogue?provider=tavus&resource=avatar_config')
        ->assertStatus(422)->assertJsonValidationErrors(['resource']);
    $this->withToken($token)->getJson('/api/avatar-templates/catalogue?provider=hr_avatar&resource=voice')
        ->assertStatus(422)->assertJsonValidationErrors(['resource']);

    Http::assertNothingSent();
});
