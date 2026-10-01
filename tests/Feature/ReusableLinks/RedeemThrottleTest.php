<?php

declare(strict_types=1);

/**
 * The throttle matrix of POST /api/reusable-links/redeem
 * (reusable-interview-links, B3b.1; design AD-11).
 *
 * The endpoint is PUBLIC and every success creates a participant and mints a
 * credential, so the two limits on it are the only cost control a leaked link
 * has until an admin disables it: 10 attempts per minute per client IP and 100
 * per hour per link. This file proves what the limits DO, through real requests:
 *
 *   - every attempt counts, whatever its outcome, so a limited request creates
 *     nothing and moves no counter;
 *   - the per-link bucket binds whatever the client address is, which is the
 *     brake that still works when a proxy collapses every caller onto one IP (the
 *     trusted-proxy risk, release gate R.2) or when a client rotates
 *     `X-Forwarded-For`;
 *   - a real, a disabled and an unknown token are throttled with the SAME
 *     response, header counters included, so a 429 is not an existence oracle;
 *   - the order is IP bucket, then format, then link bucket, then the database:
 *     a throttled request never reads the link table.
 *
 * The limiter DEFINITION (the buckets it builds and the configuration that sizes
 * them) is pinned in `RedeemLimiterWiringTest`.
 *
 * Time is frozen so a window can be crossed with `travel()` and so two series of
 * requests can be compared header for header.
 *
 * REQ: Redemption Is Rate Limited Per IP And Per Link
 *      (sdd/reusable-interview-links/spec/reusable-interview-links-redemption)
 */

use App\Models\ReusableInterviewLink;
use App\Services\ReusableLinkTokenGenerator;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Helpers\ReusableLinkFixtures as Fx;

beforeEach(function (): void {
    $this->freezeTime();
});

/**
 * The n-th address of a pool far larger than any limit under test, so a series
 * of requests "from rotating addresses" never meets a per-IP bucket twice.
 */
function redeemThrottleIp(int $n): string
{
    return '10.20.'.intdiv($n, 250).'.'.(($n % 250) + 1);
}

/**
 * One redemption attempt from `$ip`, with `$body` as its JSON body.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function redeemThrottleFrom(string $ip, array $body, string $url = Fx::REDEEM_URL)
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson($url, $body);
}

/**
 * Every response header but the date, name and value, in a stable order. The
 * `Date` header is stamped from the wall clock, which `freezeTime()` does not
 * reach; everything else a caller can read is compared.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, string>
 */
function redeemThrottleHeaders(TestResponse $response): array
{
    $headers = [];

    foreach ($response->headers->all() as $name => $values) {
        if (strtolower((string) $name) !== 'date') {
            $headers[strtolower((string) $name)] = implode(', ', (array) $values);
        }
    }

    ksort($headers);

    return $headers;
}

/**
 * The uses counter of a link, read past the tenant scope.
 */
function redeemThrottleUses(ReusableInterviewLink $link): int
{
    return ReusableInterviewLink::withoutGlobalScopes()->findOrFail($link->id)->uses_count;
}

// ─── Per IP ──────────────────────────────────────────────────────────────────

test('the 11th request in a minute from one IP is throttled, creates nothing, and the IP is served again after the window', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    foreach (range(1, 10) as $attempt) {
        redeemThrottleFrom('203.0.113.7', Fx::redeemBody($token))->assertOk();
    }

    $throttled = redeemThrottleFrom('203.0.113.7', Fx::redeemBody($token));

    $throttled->assertStatus(429);
    expect((int) $throttled->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and($throttled->json('message'))->toBeString()
        // A throttled request carrying a VALID token created nothing and
        // counted nothing.
        ->and(Fx::visitorsOf($link))->toHaveCount(10)
        ->and(redeemThrottleUses($link))->toBe(10);

    // Another client is unaffected while this one is held.
    redeemThrottleFrom('203.0.113.8', Fx::redeemBody($token))->assertOk();

    $this->travel(61)->seconds();

    redeemThrottleFrom('203.0.113.7', Fx::redeemBody($token))->assertOk();
    expect(redeemThrottleUses($link))->toBe(12);
});

test('every outcome counts against the IP: unknown, malformed and refused attempts spend the same ten', function (): void {
    ['token' => $token] = Fx::redeemable();
    $bodies = [
        Fx::redeemBody(ReusableLinkTokenGenerator::generate()),
        Fx::redeemBody('not-a-token'),
        Fx::identity(),
        Fx::redeemBody(['x']),
        Fx::redeemBody(null),
    ];

    foreach (range(1, 10) as $attempt) {
        redeemThrottleFrom('203.0.113.9', $bodies[$attempt % count($bodies)])->assertNotFound();
    }

    // The tenth failure spent the last attempt: the next request is throttled
    // even though it is the one valid token.
    redeemThrottleFrom('203.0.113.9', Fx::redeemBody($token))->assertStatus(429);
});

test('the per-IP limit is configuration-driven', function (): void {
    config(['reusable_links.redeem.per_ip_per_minute' => 2]);
    ['token' => $token] = Fx::redeemable();

    redeemThrottleFrom('203.0.113.10', Fx::redeemBody($token))->assertOk();
    redeemThrottleFrom('203.0.113.10', Fx::redeemBody($token))->assertOk();
    redeemThrottleFrom('203.0.113.10', Fx::redeemBody($token))->assertStatus(429);
});

// ─── Per link ────────────────────────────────────────────────────────────────

test('the 101st redemption of one link in an hour is throttled whichever address it comes from, and leaves no trace', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    foreach (range(1, 100) as $attempt) {
        redeemThrottleFrom(redeemThrottleIp($attempt), Fx::redeemBody($token))->assertOk();
    }

    $throttled = redeemThrottleFrom(redeemThrottleIp(101), Fx::redeemBody($token));

    $throttled->assertStatus(429);
    expect($throttled->headers->get('Retry-After'))->not->toBeNull()
        ->and(Fx::visitorsOf($link))->toHaveCount(100)
        ->and(redeemThrottleUses($link))->toBe(100);

    // The hour is the window: after it the link redeems again.
    $this->travel(3601)->seconds();

    redeemThrottleFrom(redeemThrottleIp(102), Fx::redeemBody($token))->assertOk();
    expect(redeemThrottleUses($link))->toBe(101);
});

test('the per-link limit holds independently of the client IP', function (string $variant): void {
    // Default limits throughout (100 per hour per link). The per-IP limit is
    // raised where one address makes every request, so that ONLY the per-link
    // bucket can bind: the property under test is that it does, whatever the
    // address looks like. No proxy trust is configured in the application
    // (release gate R.2), so behind a proxy that does not forward the client
    // address every caller is ONE address: this is the brake that remains.
    if ($variant !== 'varying REMOTE_ADDR') {
        config(['reusable_links.redeem.per_ip_per_minute' => 100000]);
    }

    ['link' => $link, 'token' => $token] = Fx::redeemable();

    $attempt = function (int $n) use ($variant, $token): TestResponse {
        $client = match ($variant) {
            // Every request from a different address.
            'varying REMOTE_ADDR' => test()->withServerVariables(['REMOTE_ADDR' => redeemThrottleIp($n)]),
            // Every request from the same address.
            'constant REMOTE_ADDR' => test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.50']),
            // One real peer address; the client claims a different origin each
            // time through the forwarding headers.
            'rotating X-Forwarded-For with a constant REMOTE_ADDR' => test()
                ->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
                ->withHeaders(['X-Forwarded-For' => redeemThrottleIp($n).', 198.51.100.1', 'X-Real-IP' => redeemThrottleIp($n)]),
        };

        return $client->postJson(Fx::REDEEM_URL, Fx::redeemBody($token));
    };

    foreach (range(1, 100) as $n) {
        $attempt($n)->assertOk();
    }

    $attempt(101)->assertStatus(429);

    expect(Fx::visitorsOf($link))->toHaveCount(100)
        ->and(redeemThrottleUses($link))->toBe(100);
})->with([
    'varying REMOTE_ADDR',
    'constant REMOTE_ADDR',
    'rotating X-Forwarded-For with a constant REMOTE_ADDR',
]);

test('a link bucket is per token: unknown well-formed tokens are independent of each other and of a real link', function (): void {
    config([
        'reusable_links.redeem.per_ip_per_minute' => 1000,
        'reusable_links.redeem.per_link_per_hour' => 2,
    ]);
    ['token' => $real] = Fx::redeemable();
    $first = ReusableLinkTokenGenerator::generate();
    $second = ReusableLinkTokenGenerator::generate();

    redeemThrottleFrom('203.0.113.60', Fx::redeemBody($first))->assertNotFound();
    redeemThrottleFrom('203.0.113.61', Fx::redeemBody($first))->assertNotFound();
    redeemThrottleFrom('203.0.113.62', Fx::redeemBody($first))->assertStatus(429);

    // The first token being spent does not touch the others.
    redeemThrottleFrom('203.0.113.63', Fx::redeemBody($second))->assertNotFound();
    redeemThrottleFrom('203.0.113.64', Fx::redeemBody($real))->assertOk();
});

test('malformed input never touches a link bucket', function (): void {
    config([
        'reusable_links.redeem.per_ip_per_minute' => 1000,
        'reusable_links.redeem.per_link_per_hour' => 1,
    ]);
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    // With a link limit of ONE, a single malformed value landing in any link
    // bucket would be visible as a 429 on the repeats or on the real token.
    $malformed = [
        Fx::identity(),
        Fx::redeemBody(null),
        Fx::redeemBody(''),
        Fx::redeemBody(123),
        Fx::redeemBody(['x']),
        Fx::redeemBody('beai_rl_'.str_repeat('a', 42)),
        Fx::redeemBody($token."\n"),
        Fx::redeemBody('beai_live_'.str_repeat('a', 43)),
    ];

    foreach ($malformed as $n => $body) {
        redeemThrottleFrom(redeemThrottleIp($n), $body)->assertNotFound();
        redeemThrottleFrom(redeemThrottleIp($n), $body)->assertNotFound();
    }

    redeemThrottleFrom(redeemThrottleIp(50), Fx::redeemBody($token))->assertOk();
    expect(Fx::visitorsOf($link))->toHaveCount(1);
});

test('an array-shaped link_token is a 404 while the IP has attempts left and a 429 after, never a 500', function (): void {
    config(['reusable_links.redeem.per_ip_per_minute' => 3]);

    foreach (range(1, 3) as $attempt) {
        // Both the hostile `token` input the default guard's parser reads and
        // the contract field are arrays.
        redeemThrottleFrom('203.0.113.70', Fx::redeemBody(['x']) + ['token' => ['y']], Fx::REDEEM_URL.'?token[]=z')
            ->assertNotFound();
    }

    redeemThrottleFrom('203.0.113.70', Fx::redeemBody(['x']) + ['token' => ['y']], Fx::REDEEM_URL.'?token[]=z')
        ->assertStatus(429);
});

// ─── No existence oracle ─────────────────────────────────────────────────────

test('a real, a disabled and an unknown token are throttled with the same response, counters included', function (): void {
    ['link' => $link, 'token' => $real] = Fx::redeemable();
    ['token' => $disabled] = Fx::redeemable(linkAttributes: ['disabled_at' => now()]);
    $unknown = ReusableLinkTokenGenerator::generate();

    /** @var array<string, list<TestResponse<Response>>> $series */
    $series = ['real' => [], 'disabled' => [], 'unknown' => []];

    // 101 attempts at each token, every one from an address that has not been
    // used before (a disjoint block per token), so only the link bucket can run
    // out and the per-IP counter reads the same at the same step of each series.
    $block = 0;
    foreach (['real' => $real, 'disabled' => $disabled, 'unknown' => $unknown] as $label => $token) {
        foreach (range(1, 101) as $n) {
            $series[$label][] = redeemThrottleFrom(redeemThrottleIp($block * 200 + $n), Fx::redeemBody($token));
        }
        $block++;
    }

    // The 101st attempt is the first one limited, for all three...
    foreach ($series as $label => $responses) {
        foreach (array_slice($responses, 0, 100) as $n => $response) {
            expect($response->getStatusCode())->not->toBe(429, "$label attempt ".($n + 1));
        }
        expect($responses[100]->getStatusCode())->toBe(429, $label);
    }

    // ...with the same body and the same headers, byte for byte.
    $reference = $series['unknown'][100];
    foreach (['real', 'disabled'] as $label) {
        expect($series[$label][100]->getContent())->toBe($reference->getContent(), $label)
            ->and(redeemThrottleHeaders($series[$label][100]))->toBe(redeemThrottleHeaders($reference), $label);
    }

    // The counters a caller can read along the way do not tell the tokens apart
    // either: the remaining-attempts sequence is the same for all three. (The
    // header reports whichever of the two buckets has fewer attempts left: the
    // IP's nine until the link's count drops below it, then the link's.)
    $remaining = fn (array $responses): array => array_map(
        fn (TestResponse $response): ?string => $response->headers->get('X-RateLimit-Remaining'),
        $responses,
    );
    expect($remaining($series['real']))->toBe($remaining($series['unknown']))
        ->and($remaining($series['disabled']))->toBe($remaining($series['unknown']))
        ->and($remaining($series['unknown'])[0])->toBe('9')
        ->and($remaining($series['unknown'])[91])->toBe('8')
        ->and($remaining($series['unknown'])[99])->toBe('0');

    // And the real link was redeemed exactly up to its limit, no further.
    expect(redeemThrottleUses($link))->toBe(100);
});

// ─── Order ───────────────────────────────────────────────────────────────────

test('the IP limiter answers before any format check: the third garbage request from one IP is 429', function (): void {
    config(['reusable_links.redeem.per_ip_per_minute' => 2]);

    redeemThrottleFrom('203.0.113.80', Fx::redeemBody('garbage'))->assertNotFound();
    redeemThrottleFrom('203.0.113.80', Fx::redeemBody('garbage'))->assertNotFound();

    // Same bytes as the two before it, and still not a 404: the limiter ran
    // first.
    redeemThrottleFrom('203.0.113.80', Fx::redeemBody('garbage'))->assertStatus(429);
});

test('the link limiter answers before any database read: the third request for one unknown token from varying IPs is 429 with no link-table query', function (): void {
    config([
        'reusable_links.redeem.per_ip_per_minute' => 1000,
        'reusable_links.redeem.per_link_per_hour' => 2,
    ]);
    $unknown = ReusableLinkTokenGenerator::generate();

    redeemThrottleFrom('203.0.113.90', Fx::redeemBody($unknown))->assertNotFound();
    redeemThrottleFrom('203.0.113.91', Fx::redeemBody($unknown))->assertNotFound();

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    redeemThrottleFrom('203.0.113.92', Fx::redeemBody($unknown))->assertStatus(429);

    expect($queries)->toBe([]);
});

test('the IP limiter also answers before any database read', function (): void {
    config(['reusable_links.redeem.per_ip_per_minute' => 1]);
    ['token' => $token] = Fx::redeemable();

    redeemThrottleFrom('203.0.113.95', Fx::redeemBody($token))->assertOk();

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    redeemThrottleFrom('203.0.113.95', Fx::redeemBody($token))->assertStatus(429);

    expect($queries)->toBe([]);
});

// ─── An invalid identity is an attempt like any other ────────────────────────

test('an invalid identity spends an IP attempt like any other outcome: five refused and five redeemed, then the eleventh is throttled', function (): void {
    ['token' => $token] = Fx::redeemable();

    foreach (range(1, 5) as $attempt) {
        redeemThrottleFrom('203.0.113.120', ['link_token' => $token, 'email' => 'ada@example.test'])->assertUnprocessable();
    }

    foreach (range(1, 5) as $attempt) {
        redeemThrottleFrom('203.0.113.120', Fx::redeemBody($token))->assertOk();
    }

    redeemThrottleFrom('203.0.113.120', Fx::redeemBody($token))->assertStatus(429);
});

test('an invalid identity beside a well-formed token spends the per-link bucket: a hundred refused, then the valid one is throttled', function (): void {
    config(['reusable_links.redeem.per_ip_per_minute' => 100000]);
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    foreach (range(1, 100) as $attempt) {
        redeemThrottleFrom(redeemThrottleIp($attempt), ['link_token' => $token])->assertUnprocessable();
    }

    redeemThrottleFrom(redeemThrottleIp(101), Fx::redeemBody($token))->assertStatus(429);

    expect(Fx::visitorsOf($link))->toBe([])
        ->and(redeemThrottleUses($link))->toBe(0);
});

test('an invalid identity beside a malformed token spends only the IP bucket', function (): void {
    config([
        'reusable_links.redeem.per_ip_per_minute' => 3,
        'reusable_links.redeem.per_link_per_hour' => 1,
    ]);
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    foreach (range(1, 3) as $attempt) {
        redeemThrottleFrom('203.0.113.130', ['link_token' => 'not-a-token'])->assertUnprocessable();
    }

    // The IP bucket was spent...
    redeemThrottleFrom('203.0.113.130', ['link_token' => 'not-a-token'])->assertStatus(429);

    // ...and no link bucket was touched: with a link limit of ONE, the real
    // token still redeems from another address.
    redeemThrottleFrom('203.0.113.131', Fx::redeemBody($token))->assertOk();
    expect(Fx::visitorsOf($link))->toHaveCount(1);
});
