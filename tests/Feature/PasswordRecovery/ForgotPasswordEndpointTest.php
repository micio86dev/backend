<?php

declare(strict_types=1);

/**
 * `POST /api/auth/forgot-password` — self-service password reset, request leg
 * (self-service-password-reset AD-2, AD-3, AD-7).
 *
 * THE WHOLE ENDPOINT IS AN ANTI-ENUMERATION PROBLEM
 * -------------------------------------------------
 * It is unauthenticated, takes an email address, and has a side effect on
 * ANOTHER PERSON'S inbox. Two things must therefore be indistinguishable to
 * the caller: the RESPONSE (byte-for-byte, for an existing, an unknown and a
 * DEACTIVATED address) and the WORK DONE IN THE REQUEST. The second is the one
 * that is easy to get wrong: the existing-user branch would otherwise perform a
 * token write plus a live Resend round trip — hundreds of milliseconds — while
 * the unknown-email branch returns immediately. That is not a statistical
 * side channel, it is a stopwatch.
 *
 * The fix is structural, not cosmetic: the controller dispatches a queued job
 * and returns, so token creation, broker throttling and the send all happen
 * off-request for EVERY address. These tests assert the dispatch, not a
 * duration — a timing assertion in CI measures the CI box, not the design.
 *
 * REQ: password-recovery self-service request endpoint
 */

use App\Jobs\SendPasswordResetLinkJob;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function forgotPasswordUser(array $attributes = []): User
{
    $org = Organization::factory()->create();

    return User::factory()->create(array_merge([
        'organization_id' => $org->id,
        'email' => 'known@example.com',
    ], $attributes));
}

beforeEach(function (): void {
    Queue::fake();
    config()->set('services.backoffice_origin', 'https://backoffice.example.com');
});

test('a known address gets 202 and the send is dispatched to the queue, never performed in the request', function (): void {
    forgotPasswordUser();

    $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com'])
        ->assertStatus(202);

    Queue::assertPushed(SendPasswordResetLinkJob::class);
});

test('an unknown address gets a BYTE-IDENTICAL response to a known one', function (): void {
    forgotPasswordUser();

    $known = $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com']);
    $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com']);

    // Byte-compared, not merely status-compared: a differing field, a
    // differing key order, or a differing header is an enumeration oracle
    // just as surely as a differing status code is.
    expect($unknown->getStatusCode())->toBe($known->getStatusCode());
    expect($unknown->getContent())->toBe($known->getContent());
});

test('a DEACTIVATED user gets a byte-identical response too — the case most likely to be forgotten', function (): void {
    forgotPasswordUser();
    forgotPasswordUser(['email' => 'deactivated@example.com', 'deactivated_at' => now()]);

    $known = $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com']);
    $deactivated = $this->postJson('/api/auth/forgot-password', ['email' => 'deactivated@example.com']);

    expect($deactivated->getStatusCode())->toBe($known->getStatusCode());
    expect($deactivated->getContent())->toBe($known->getContent());
});

test('the request is dispatched for an unknown address too — the queue is not a second oracle', function (): void {
    // Branching on existence HERE would restore the timing signal the job
    // exists to remove, and would leak through queue metrics besides. The
    // decision to send belongs in the job, off-request.
    $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])
        ->assertStatus(202);

    Queue::assertPushed(SendPasswordResetLinkJob::class);
});

test('the response body names no account and echoes no state', function (): void {
    forgotPasswordUser();

    $body = $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com'])->getContent();

    // The submitted address is NOT echoed back: reflecting it costs nothing to
    // omit and turns any log or proxy that captures responses into a list of
    // probed addresses.
    //
    // "exists" is deliberately NOT in this list — the body's whole job is to
    // say "IF an account exists", a conditional that commits to nothing. What
    // must never appear is a word that resolves that condition.
    foreach (['known@example.com', 'not found', 'no such', 'deactivated', 'user_id'] as $leak) {
        expect(strtolower($body))->not->toContain(strtolower($leak));
    }
});

test('a malformed email is a 422 validation failure — about the FORMAT, never about existence', function (): void {
    $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    // `exists:users,email` would turn the validator itself into the oracle.
    Queue::assertNothingPushed();
});

test('a missing email is a 422 validation failure', function (): void {
    $this->postJson('/api/auth/forgot-password', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

test('the endpoint is public — it must work for someone who cannot log in', function (): void {
    forgotPasswordUser();

    // The entire point is that the caller has no session. A 401 here would
    // make the feature unreachable for exactly the person it is for.
    $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com'])
        ->assertStatus(202);
});

test('the endpoint is rate limited, and the limit does not depend on whether the address exists', function (): void {
    forgotPasswordUser();

    // `throttle:6,1`, matching /profile/password's existing precedent — an
    // unauthenticated endpoint with a side effect on another person's inbox
    // is a mail-bomb and a cost primitive without it.
    for ($i = 0; $i < 6; $i++) {
        $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com'])
            ->assertStatus(202);
    }

    $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com'])
        ->assertStatus(429);

    // Same limiter, same key (the caller's IP) — an attacker must not be able
    // to tell existing from unknown by watching which one throttles first.
    $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])
        ->assertStatus(429);
});
