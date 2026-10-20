<?php

declare(strict_types=1);

/**
 * A paged catalogue fetch that stops at its safety cap while the vendor still has more pages is
 * INCOMPLETE: it is served, but never cached as if it were the whole inventory, and a warning says so
 * (hr-avatar-adapter A10; the HR-Avatar fetch has done this since A8). A list that ends exactly on the
 * cap is complete: cached, no warning. The response shape does not change in either case.
 */

use App\Support\AvatarTemplates\AvatarProviderCatalogue;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const TRUNC_MAX_PAGES = 20;

const TRUNC_PAGE_SIZE = 100;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    config([
        'interview.tavus.api_key' => 'SUPER_SECRET_TAVUS_KEY_12345',
        'interview.heygen.api_key' => 'SUPER_SECRET_HEYGEN_KEY_67890',
        'services.cartesia.api_key' => 'SUPER_SECRET_CARTESIA_KEY',
        'services.elevenlabs.api_key' => 'SUPER_SECRET_ELEVENLABS_KEY',
    ]);
});

/**
 * One scenario per paged fetch: `$page` is the response for the n-th request (1-based) when the vendor
 * keeps offering more after it ($more) or not.
 *
 * @return array<string, array{0: string, 1: string, 2: Closure(int, bool): array<string, mixed>}>
 */
dataset('pagedCatalogues', [
    'tavus voice' => ['tavus', 'voice', fn (int $n, bool $more): array => [
        'data' => array_map(
            fn (int $i): array => ['voice_id' => "v{$n}_{$i}", 'voice_name' => "Voice {$n} {$i}", 'status' => 'completed'],
            range(1, $more ? TRUNC_PAGE_SIZE : 1),
        ),
        'total_count' => 1_000_000,
    ]],
    'tavus replica' => ['tavus', 'replica', fn (int $n, bool $more): array => [
        'data' => array_map(
            fn (int $i): array => ['face_id' => "f{$n}_{$i}", 'face_name' => "Face {$n} {$i}", 'status' => 'completed'],
            range(1, $more ? TRUNC_PAGE_SIZE : 1),
        ),
        'total_count' => 1_000_000,
    ]],
    'tavus pal' => ['tavus', 'pal', fn (int $n, bool $more): array => [
        'data' => array_map(
            fn (int $i): array => ['pal_id' => "p{$n}_{$i}", 'pal_name' => "Pal {$n} {$i}"],
            range(1, $more ? TRUNC_PAGE_SIZE : 1),
        ),
        'total_count' => 1_000_000,
    ]],
    'heygen voice' => ['heygen', 'voice', fn (int $n, bool $more): array => [
        'code' => 1000,
        'data' => [
            'results' => array_map(fn (int $i): array => ['id' => "hv{$n}_{$i}", 'name' => "Voice {$n} {$i}"], range(1, 2)),
            'next' => $more ? 'https://api.liveavatar.com/v1/voices?page='.($n + 1) : null,
        ],
    ]],
    'heygen avatar' => ['heygen', 'avatar', fn (int $n, bool $more): array => [
        'code' => 1000,
        'data' => [
            'results' => array_map(fn (int $i): array => ['id' => "ha{$n}_{$i}", 'name' => "Avatar {$n} {$i}", 'status' => 'ACTIVE'], range(1, 2)),
            'next' => $more ? 'https://api.liveavatar.com/v1/avatars?page='.($n + 1) : null,
        ],
    ]],
    'cartesia voice' => ['cartesia', 'voice', fn (int $n, bool $more): array => [
        'data' => array_map(fn (int $i): array => ['id' => "c{$n}_{$i}", 'name' => "Voice {$n} {$i}", 'language' => 'en'], range(1, 2)),
        'has_more' => $more,
        'next_page' => $more ? "cur_{$n}" : null,
    ]],
    'elevenlabs voice' => ['elevenlabs', 'voice', fn (int $n, bool $more): array => [
        'voices' => array_map(fn (int $i): array => ['voice_id' => "e{$n}_{$i}", 'name' => "Voice {$n} {$i}"], range(1, 2)),
        'has_more' => $more,
        'next_page_token' => $more ? "tok_{$n}" : null,
    ]],
]);

/**
 * Fakes the vendor so that the n-th request of EACH list returns `$page(n, more)`, where `more` is true
 * until the list reaches `$lastPage`. The per-list counter is read from the request's own page marker.
 *
 * @param  Closure(int, bool): array<string, mixed>  $page
 */
function truncFake(Closure $page, int $lastPage): void
{
    $served = [];

    Http::fake(function (Request $request) use ($page, $lastPage, &$served) {
        // Each distinct list (path + filters, paging markers stripped) counts its own requests.
        $parts = parse_url($request->url());
        parse_str($parts['query'] ?? '', $query);
        unset($query['page'], $query['starting_after'], $query['next_page_token']);
        ksort($query);
        $list = ($parts['host'] ?? '').($parts['path'] ?? '').'?'.http_build_query($query);
        $served[$list] = ($served[$list] ?? 0) + 1;
        $n = $served[$list];

        return Http::response($page($n, $n < $lastPage));
    });
}

test('a paged fetch that hits its page cap with more pages available is served but not cached, and warns', function (string $provider, string $resource, Closure $page): void {
    truncFake($page, TRUNC_MAX_PAGES + 5);
    Log::spy();

    $result = AvatarProviderCatalogue::fetch($provider, $resource);

    expect($result['status'])->toBe('ok')
        ->and($result['items'])->not->toBeEmpty()
        ->and(array_keys($result))->toBe(['status', 'items'])
        ->and(Cache::has(AvatarProviderCatalogue::cacheKey($provider, $resource)))->toBeFalse();

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($provider): bool {
        $payload = $message.json_encode($context);

        return str_contains($message, 'truncated')
            && ($context['provider'] ?? null) === $provider
            && ($context['max_pages'] ?? null) === TRUNC_MAX_PAGES
            && ! str_contains($payload, 'SUPER_SECRET');
    })->atLeast()->once();
})->with('pagedCatalogues');

test('a truncated result is asked for again on the next call instead of being replayed from the cache', function (string $provider, string $resource, Closure $page): void {
    truncFake($page, TRUNC_MAX_PAGES + 5);

    AvatarProviderCatalogue::fetch($provider, $resource);
    $afterFirst = count(Http::recorded());
    AvatarProviderCatalogue::fetch($provider, $resource);

    expect(count(Http::recorded()))->toBeGreaterThan($afterFirst);
})->with('pagedCatalogues');

test('a list that ends exactly on the page cap is complete: cached and no warning', function (string $provider, string $resource, Closure $page): void {
    truncFake($page, TRUNC_MAX_PAGES);
    Log::spy();

    $result = AvatarProviderCatalogue::fetch($provider, $resource);

    expect($result['status'])->toBe('ok')
        ->and(Cache::has(AvatarProviderCatalogue::cacheKey($provider, $resource)))->toBeTrue();

    Log::shouldNotHaveReceived('warning');
})->with('pagedCatalogues');

test('a secondary list that failed leaves the catalogue incomplete: served and not cached', function (string $provider, string $resource, string $failingUrl, Closure $page): void {
    Http::fake([
        $failingUrl => Http::response(['error' => 'boom'], 500),
        '*' => fn () => Http::response($page(1, false)),
    ]);

    $result = AvatarProviderCatalogue::fetch($provider, $resource);

    expect($result['status'])->toBe('ok')
        ->and(Cache::has(AvatarProviderCatalogue::cacheKey($provider, $resource)))->toBeFalse();
})->with([
    'heygen private voices' => ['heygen', 'voice', 'api.liveavatar.com/v1/voices?voice_type=private*', fn (int $n, bool $more): array => ['code' => 1000, 'data' => ['results' => [['id' => 'hv', 'name' => 'Voice']], 'next' => null]]],
    'heygen own avatars' => ['heygen', 'avatar', 'api.liveavatar.com/v1/avatars?*', fn (int $n, bool $more): array => ['code' => 1000, 'data' => ['results' => [['id' => 'ha', 'name' => 'Avatar', 'status' => 'ACTIVE']], 'next' => null]]],
    'tavus persona_type=user' => ['tavus', 'pal', 'tavusapi.com/v2/pals?persona_type=user*', fn (int $n, bool $more): array => ['data' => [['pal_id' => 'p1', 'pal_name' => 'Pal']], 'total_count' => 1]],
]);

test('an ElevenLabs voice whose accent label is not a string is still listed', function (): void {
    Http::fake(['api.elevenlabs.io/*' => Http::response([
        'voices' => [['voice_id' => 'e1', 'name' => 'Odd', 'labels' => ['accent' => ['italian']]]],
        'has_more' => false,
    ])]);

    $result = AvatarProviderCatalogue::fetch('elevenlabs', 'voice');

    expect($result['status'])->toBe('ok')
        ->and($result['items'][0]['id'])->toBe('e1')
        ->and($result['items'][0]['italian'])->toBeNull();
});
