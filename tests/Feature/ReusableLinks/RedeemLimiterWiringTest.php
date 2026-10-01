<?php

declare(strict_types=1);

/**
 * RED - reusable-interview-links B3a.3: the named `reusable-link-redeem`
 * limiter (design AD-11, tasks C-T9).
 *
 * The redemption endpoint is PUBLIC and mints a credential, so it must never
 * exist unthrottled, not even for one commit. This file pins the limiter's
 * DEFINITION: the buckets it builds and the configuration that sizes them. The
 * request-level behaviour (429 shape, ordering, bucket independence from the
 * client IP) is exercised through the route in the redemption tests and in the
 * throttle matrix.
 *
 * The limiter is a NAMED one (never the numeric `throttle:N,1` form) because
 * Laravel's numeric form resolves its bucket key through `$request->user()` on
 * the default guard, whose token parser reads a `token` input and 500s on
 * `?token[]=` before the controller runs. A named limiter owns its key outright.
 *
 * It is invoked here with real `Request` objects through
 * `RateLimiter::limiter()`, the same entry point `ThrottleRequests` uses.
 */

use App\Models\ReusableInterviewLink;
use App\Services\ReusableLinkTokenGenerator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\Helpers\ReusableLinkFixtures as Fx;

/**
 * Resolve the limits the named limiter builds for a JSON body from an IP.
 *
 * @param  array<string, mixed>  $body
 * @return list<Limit>
 */
function redeemLimits(array $body, string $ip = '203.0.113.7'): array
{
    // Built the way the HTTP kernel builds it (`createFromBase`), because that
    // is what moves a JSON body into the bag `post()` reads. A bare
    // `Request::create()` would leave that bag empty.
    $request = Request::createFromBase(SymfonyRequest::create(
        '/api/reusable-links/redeem',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip],
        (string) json_encode($body),
    ));

    $limiter = RateLimiter::limiter('reusable-link-redeem');
    expect($limiter)->not->toBeNull();

    $limits = $limiter($request);

    return array_values(is_array($limits) ? $limits : [$limits]);
}

test('the redeem limiter is registered under its name', function (): void {
    expect(RateLimiter::limiter('reusable-link-redeem'))->toBeInstanceOf(Closure::class);
});

test('the limits default to 10 per minute per IP and 100 per hour per link', function (): void {
    expect(config('reusable_links.redeem.per_ip_per_minute'))->toBe(10)
        ->and(config('reusable_links.redeem.per_link_per_hour'))->toBe(100);
});

test('both limits are environment-overridable and documented in .env.example', function (): void {
    $config = (string) file_get_contents(config_path('reusable_links.php'));
    $example = (string) file_get_contents(base_path('.env.example'));

    foreach ([
        'REUSABLE_LINK_REDEEM_PER_IP_PER_MINUTE',
        'REUSABLE_LINK_REDEEM_PER_LINK_PER_HOUR',
    ] as $variable) {
        expect($config)->toContain("env('{$variable}'")
            ->and($example)->toContain($variable.'=');
    }
});

test('a well-formed token yields two limits: the IP per minute and the link per hour', function (): void {
    $token = ReusableLinkTokenGenerator::generate();

    $limits = redeemLimits(['link_token' => $token], ip: '203.0.113.7');

    expect($limits)->toHaveCount(2);

    [$perIp, $perLink] = $limits;

    expect($perIp->key)->toBe('ip:203.0.113.7')
        ->and($perIp->maxAttempts)->toBe(10)
        ->and($perIp->decaySeconds)->toBe(60)
        ->and($perLink->key)->toBe('link:'.ReusableLinkTokenGenerator::hash($token))
        ->and($perLink->maxAttempts)->toBe(100)
        ->and($perLink->decaySeconds)->toBe(3600);
});

test('malformed input still counts against the IP and never builds a link bucket', function (mixed $linkToken): void {
    $limits = redeemLimits(['link_token' => $linkToken], ip: '203.0.113.7');

    expect($limits)->toHaveCount(1)
        ->and($limits[0]->key)->toBe('ip:203.0.113.7')
        ->and($limits[0]->maxAttempts)->toBe(10)
        ->and($limits[0]->decaySeconds)->toBe(60);
})->with([
    'null' => [null],
    'an integer' => [123],
    'an array' => [['x']],
    'an empty string' => [''],
    'a 42-character body' => ['beai_rl_'.str_repeat('a', 42)],
    'a 44-character body' => ['beai_rl_'.str_repeat('a', 44)],
    'the wrong marker' => ['beai_rk_'.str_repeat('a', 43)],
    'a trailing newline' => [ReusableLinkTokenGenerator::MARKER.str_repeat('a', 43)."\n"],
]);

test('a request with no body still counts against the IP', function (): void {
    $request = Request::create('/api/reusable-links/redeem', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.4']);

    $limits = RateLimiter::limiter('reusable-link-redeem')($request);

    expect($limits)->toHaveCount(1)
        ->and($limits[0]->key)->toBe('ip:198.51.100.4');
});

test('the token is read only from link_token, never from a field named token', function (): void {
    $token = ReusableLinkTokenGenerator::generate();

    // A well-formed value under the wrong name must not open a link bucket.
    expect(redeemLimits(['token' => $token]))->toHaveCount(1);
});

test('no bucket key contains the raw token', function (): void {
    $token = ReusableLinkTokenGenerator::generate();

    foreach (redeemLimits(['link_token' => $token]) as $limit) {
        expect($limit->key)->not->toContain($token)
            ->and($limit->key)->not->toContain(substr($token, strlen(ReusableLinkTokenGenerator::MARKER)));
    }
});

test('two well-formed tokens get independent link buckets', function (): void {
    $first = redeemLimits(['link_token' => ReusableLinkTokenGenerator::generate()]);
    $second = redeemLimits(['link_token' => ReusableLinkTokenGenerator::generate()]);

    expect($first[1]->key)->not->toBe($second[1]->key)
        // ...while the IP bucket is shared by both.
        ->and($first[0]->key)->toBe($second[0]->key);
});

test('the same token from two IPs shares one link bucket and has two IP buckets', function (): void {
    $token = ReusableLinkTokenGenerator::generate();

    $a = redeemLimits(['link_token' => $token], ip: '203.0.113.1');
    $b = redeemLimits(['link_token' => $token], ip: '203.0.113.2');

    expect($a[1]->key)->toBe($b[1]->key)
        ->and($a[0]->key)->not->toBe($b[0]->key);
});

test('overriding the configuration changes both limits without a code change', function (): void {
    config([
        'reusable_links.redeem.per_ip_per_minute' => 2,
        'reusable_links.redeem.per_link_per_hour' => 3,
    ]);

    $limits = redeemLimits(['link_token' => ReusableLinkTokenGenerator::generate()]);

    expect($limits[0]->maxAttempts)->toBe(2)
        ->and($limits[1]->maxAttempts)->toBe(3);
});

// ─── Wired to the route ──────────────────────────────────────────────────────
//
// The tests above pin the limiter's DEFINITION. These two prove the route
// actually runs it: a registered limiter that no route uses protects nothing.
// The full matrix (ordering, header shapes, windows) lives with the throttle
// tests; here a lowered limit and one request past it is enough.

test('the route throttles per IP: past the limit it answers 429 with Retry-After and creates nothing', function (): void {
    config(['reusable_links.redeem.per_ip_per_minute' => 2]);
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    $this->postJson(Fx::REDEEM_URL, ['link_token' => $token])->assertOk();
    $this->postJson(Fx::REDEEM_URL, ['link_token' => $token])->assertOk();

    $response = $this->postJson(Fx::REDEEM_URL, ['link_token' => $token]);

    $response->assertStatus(429);
    expect($response->headers->get('Retry-After'))->not->toBeNull()
        ->and($response->json('message'))->toBeString()
        ->and(Fx::visitorsOf($link))->toHaveCount(2);
});

test('the route throttles per link whatever the client address: a new IP does not reset the link bucket', function (): void {
    config([
        'reusable_links.redeem.per_ip_per_minute' => 1000,
        'reusable_links.redeem.per_link_per_hour' => 2,
    ]);
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    foreach (['203.0.113.21', '203.0.113.22'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(Fx::REDEEM_URL, ['link_token' => $token])
            ->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.23'])
        ->postJson(Fx::REDEEM_URL, ['link_token' => $token])
        ->assertStatus(429);

    expect(Fx::visitorsOf($link))->toHaveCount(2);
});

// ─── The limiter reads the SAME source as the controller: the body ───────────
//
// The controller redeems `$request->post('link_token')` (the BODY only). If the
// limiter read `$request->input('link_token')` instead, which also merges the
// QUERY STRING, a token presented in a URL would open a per-link bucket for a
// request the controller treats as having no token at all: two parts of one
// endpoint disagreeing about what was presented. A token in a URL ends up in
// access logs, so it must also never be given any standing.

test('a well-formed token in the query string builds no link bucket', function (): void {
    $token = ReusableLinkTokenGenerator::generate();

    $request = Request::createFromBase(SymfonyRequest::create(
        '/api/reusable-links/redeem?link_token='.$token,
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.7'],
        '{}',
    ));

    $limits = RateLimiter::limiter('reusable-link-redeem')($request);

    expect($limits)->toHaveCount(1)
        ->and($limits[0]->key)->toBe('ip:203.0.113.7');
});

test('a token in the query string with an empty body opens no per-link bucket and gets the generic 404', function (): void {
    config([
        'reusable_links.redeem.per_ip_per_minute' => 1000,
        'reusable_links.redeem.per_link_per_hour' => 1,
    ]);
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    // Three requests, three clients, the one real token in the URL each time.
    // A link bucket of ONE per hour would answer the second with 429 if the
    // limiter had given the URL token any standing.
    foreach (['203.0.113.31', '203.0.113.32', '203.0.113.33'] as $ip) {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(Fx::REDEEM_URL.'?link_token='.$token);

        $response->assertNotFound();
        expect($response->getContent())->toBe(Fx::NOT_FOUND_BODY);
    }

    expect(Fx::visitorsOf($link))->toBe([])
        ->and(ReusableInterviewLink::withoutGlobalScopes()->findOrFail($link->id)->uses_count)->toBe(0);

    // ...and the same link is still redeemable from the body: its bucket was
    // never touched by the three URL attempts.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.34'])
        ->postJson(Fx::REDEEM_URL, ['link_token' => $token])
        ->assertOk();
});

test('the limiter buckets the token the controller redeems, not the one in the URL', function (): void {
    config([
        'reusable_links.redeem.per_ip_per_minute' => 1000,
        'reusable_links.redeem.per_link_per_hour' => 1,
    ]);
    ['link' => $link, 'token' => $realToken] = Fx::redeemable();
    $otherToken = ReusableLinkTokenGenerator::generate();

    // Body: an unknown token (what the controller looks up). URL: the real one.
    // The first request spends the UNKNOWN token's single hourly attempt...
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.41'])
        ->postJson(Fx::REDEEM_URL.'?link_token='.$realToken, ['link_token' => $otherToken])
        ->assertNotFound();

    // ...so the second request for it is throttled,
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
        ->postJson(Fx::REDEEM_URL.'?link_token='.$realToken, ['link_token' => $otherToken])
        ->assertStatus(429);

    // ...while the real token, which only ever appeared in a URL, has spent
    // nothing and redeems.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.43'])
        ->postJson(Fx::REDEEM_URL, ['link_token' => $realToken])
        ->assertOk();

    expect(Fx::visitorsOf($link))->toHaveCount(1);
});
