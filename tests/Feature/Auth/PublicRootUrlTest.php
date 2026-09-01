<?php

declare(strict_types=1);

/**
 * Absolute URLs are built from `APP_URL`, never from the request's Host.
 *
 * This API is always reached through something else — the Nuxt apps proxy
 * `/api/*` in development, Railway's edge terminates TLS in production — so the
 * `Host` Laravel sees is the INTERNAL one. Left to its default, the URL
 * generator built links against it and a signed profile-photo URL came back as
 * `http://api:8000/storage/...`: the compose service name, which no browser can
 * resolve. The response was 200 and the signature valid; the image simply never
 * loaded, which is the kind of failure nobody thinks to look for in the API.
 */

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;

test('a generated URL uses APP_URL even when the request arrives on another host', function (): void {
    config(['app.url' => 'https://api.beai.test']);
    (new AppServiceProvider(app()))->boot();

    // The Host a proxy would present — the exact shape that produced the bug.
    $generated = URL::to('/storage/x.jpg');

    expect($generated)->toStartWith('https://api.beai.test/')
        ->and($generated)->not->toContain('api:8000');
});

test('a signed URL is generated and verified against the SAME root', function (): void {
    // The half that makes the fix load-bearing rather than cosmetic: a URL
    // signed against one root and verified against another does not match, so
    // forcing the root without forcing the scheme would trade a broken image
    // for a broken signature.
    config(['app.url' => 'https://api.beai.test']);
    (new AppServiceProvider(app()))->boot();

    $signed = URL::temporarySignedRoute('storage.local', now()->addMinutes(5), ['path' => 'x.jpg']);

    expect($signed)->toStartWith('https://api.beai.test/');
});

test('an unset or malformed APP_URL leaves the previous behaviour alone', function (): void {
    // Deliberately NOT guessed at. Falling back to the request root restores
    // exactly what every existing test was written against, which is wrong for
    // this deployment but is at least predictable — inventing a root would make
    // a misconfiguration look like it worked.
    config(['app.url' => 'not a url']);
    (new AppServiceProvider(app()))->boot();

    expect(URL::to('/x'))->toContain('/x');
});
