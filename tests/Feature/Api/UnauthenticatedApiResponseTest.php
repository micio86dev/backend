<?php

declare(strict_types=1);

/**
 * An unauthenticated API request must answer 401, regardless of what the client
 * put in its Accept header.
 *
 * This is not a nicety. Laravel's ApplicationBuilder::withMiddleware() installs
 * a DEFAULT guest-redirect callback — `fn () => route('login')` — and
 * Authenticate::unauthenticated() invokes it whenever `$request->expectsJson()`
 * is false. In an API-only application (CLAUDE.md: "API-only, no Blade UI")
 * there is no `login` route and there never will be, so that call throws
 * RouteNotFoundException INSIDE the middleware, before the
 * AuthenticationException is ever constructed. The exception handler's
 * `shouldRenderJsonWhen(api/*)` rule never gets a chance to run.
 *
 * The client-visible result is a 500 carrying a stack trace, for a request
 * whose only fault was omitting a header. Two concrete costs:
 *
 *   - Wrong semantics. 500 says "this server is broken"; 401 says "authenticate
 *     and try again". Well-behaved clients RETRY a 500 and do not retry a 401,
 *     so the wrong code turns a missing token into retry traffic.
 *   - It leaks internals — exception class and vendor paths — to an entirely
 *     unauthenticated caller.
 */

use App\Models\Organization;
use App\Models\User;

test('a protected endpoint answers 401 when the client sends no Accept header', function (): void {
    // No `Accept: application/json`, which is what curl, a misconfigured
    // gateway, or a browser address bar will do.
    $response = $this->get('/api/m2m/clients', ['Accept' => '*/*']);

    $response->assertStatus(401);
    $response->assertJson(['message' => 'Unauthenticated.']);
});

test('a protected endpoint answers 401 with an explicit JSON Accept header', function (): void {
    // The path that already worked — pinned so a fix for the case above cannot
    // regress it.
    $this->getJson('/api/m2m/clients')
        ->assertStatus(401)
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('the 401 body never leaks an exception class or a vendor path', function (): void {
    $body = $this->get('/api/m2m/clients', ['Accept' => '*/*'])->getContent();

    expect($body)->not->toContain('RouteNotFoundException');
    expect($body)->not->toContain('vendor/laravel');
    expect($body)->not->toContain('Route [login] not defined');
});

test('an authenticated request is unaffected', function (): void {
    // Guards against a fix that simply disables the auth middleware.
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    $token = auth('api')->login($user);

    $this->withToken($token)
        ->getJson('/api/m2m/clients')
        ->assertStatus(403); // authenticated, but not an admin — NOT 401, NOT 500
});
