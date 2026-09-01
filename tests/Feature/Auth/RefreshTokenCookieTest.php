<?php

declare(strict_types=1);

/**
 * The refresh cookie's `Secure` / `SameSite` pair.
 *
 * It used to be `SameSite=None; Secure` unconditionally, and the docblock
 * explaining why carried a good rule — no `secure` override knob, "because a
 * knob is what gets flipped in production". That rule is kept: nothing here is
 * configurable.
 *
 * What was wrong is that local development could not work AT ALL. `Secure`
 * means the browser sends the cookie only over HTTPS, local dev is
 * `http://localhost`, so the cookie was never sent and every page reload
 * dropped the operator back to the login screen.
 *
 * The pair is now derived from the request. These tests exist because the
 * downgrade must be impossible to reach anywhere but local development, and
 * "impossible" is a claim that needs asserting rather than commenting.
 */

use App\Support\Auth\RefreshTokenCookie;
use App\Support\Auth\RefreshTokenIssue;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

function refreshCookieIssue(): RefreshTokenIssue
{
    return new RefreshTokenIssue(
        familyId: 'fam-1',
        secret: 'sekrit',
        absoluteExpiresAt: now()->addDays(14)->getTimestamp(),
    );
}

function refreshCookieUnder(string $environment, string $url): Cookie
{
    app()['env'] = $environment;
    app()->instance('request', Request::create($url));

    return RefreshTokenCookie::build(refreshCookieIssue());
}

test('production over HTTPS is Secure and SameSite=None — unchanged', function (): void {
    $cookie = refreshCookieUnder('production', 'https://api.beai.test/api/auth/refresh');

    expect($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe(Cookie::SAMESITE_NONE);
});

test('production over PLAIN HTTP still demands Secure — the control fails CLOSED', function (): void {
    // The case that matters. A deployment accidentally served over HTTP keeps
    // `Secure` and therefore stops working, rather than quietly shipping a
    // session credential that any network hop between the browser and the
    // server can read. Broken beats leaked.
    $cookie = refreshCookieUnder('production', 'http://api.beai.test/api/auth/refresh');

    expect($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe(Cookie::SAMESITE_NONE);
});

test('staging over plain HTTP also keeps Secure — only `local` may downgrade', function (): void {
    $cookie = refreshCookieUnder('staging', 'http://api.staging.test/api/auth/refresh');

    expect($cookie->isSecure())->toBeTrue();
});

test('local over plain HTTP drops Secure and uses SameSite=Lax', function (): void {
    // The whole point of the change. The two move TOGETHER: a browser discards
    // a `SameSite=None` cookie that is not also `Secure`, so leaving `None`
    // here would produce no cookie at all — the same broken login loop by a
    // different route.
    $cookie = refreshCookieUnder('local', 'http://localhost:8000/api/auth/refresh');

    expect($cookie->isSecure())->toBeFalse()
        ->and($cookie->getSameSite())->toBe(Cookie::SAMESITE_LAX);
});

test('local over HTTPS keeps the production pair', function (): void {
    // A developer running local TLS gets the real thing, not the downgrade.
    $cookie = refreshCookieUnder('local', 'https://localhost:8000/api/auth/refresh');

    expect($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe(Cookie::SAMESITE_NONE);
});

test('the cookie stays httpOnly, host-only and narrowly pathed in every case', function (): void {
    // The properties the downgrade must NOT touch. `HttpOnly` is what keeps the
    // credential out of reach of scripts, and the narrow `Path` is what stops
    // it being attached to every request in the app.
    foreach ([['production', 'https://api.beai.test/x'], ['local', 'http://localhost:8000/x']] as [$env, $url]) {
        $cookie = refreshCookieUnder($env, $url);

        expect($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->getDomain())->toBeEmpty()
            ->and($cookie->getPath())->toBe('/api/auth/refresh');
    }
});

test('clearing the cookie follows the same rules', function (): void {
    // Otherwise a logout in local dev would emit a `Secure` deletion the
    // browser ignores, leaving the credential in place.
    app()['env'] = 'local';
    app()->instance('request', Request::create('http://localhost:8000/api/auth/refresh'));

    $cookie = RefreshTokenCookie::clear();

    expect($cookie->isSecure())->toBeFalse()
        ->and($cookie->getValue())->toBe('');
});
