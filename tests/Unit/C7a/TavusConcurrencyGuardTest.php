<?php

declare(strict_types=1);

/**
 * TavusConcurrencyGuard unit tests (PR6 — design D8).
 *
 * Ported from legacy-demo IN HALF: retry-with-backoff only. The blind
 * account-wide reap (`endActiveTavusConversations()`, start.ts:280-298) is
 * explicitly, permanently rejected — BEAI is multi-tenant, and ending every
 * active conversation on the account to make room for this one would
 * terminate another tenant's in-flight interview. See the class docblock and
 * the grep-based negative test below.
 *
 * @wire-source legacy-demo/src/pages/api/interview/start.ts:270-361
 *
 * REQ: Tavus Concurrency Self-Heal Before Surfacing 429 (delta spec, interview-session)
 */

use App\Services\Provider\TavusConcurrencyGuard;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

const TAVUS_GUARD_TEST_URL = 'https://tavusapi.com/v2/conversations';

function tavusGuardCall(): Response
{
    return Http::withHeaders(['x-api-key' => 'SECRET_KEY'])->post(TAVUS_GUARD_TEST_URL);
}

beforeEach(function (): void {
    config([
        'interview.tavus.concurrency_retries' => 3,
        'interview.tavus.concurrency_backoff_ms' => 0, // fast tests — backoff timing is not under test here
    ]);
});

test('retries a concurrency-limited create call and returns the successful retry', function (): void {
    Http::fake([
        TAVUS_GUARD_TEST_URL => Http::sequence()
            ->push(['error' => 'Maximum concurrent conversations reached'], 400)
            ->push(['error' => 'Maximum concurrent conversations reached'], 400)
            ->push(['conversation_id' => 'c1', 'conversation_url' => 'https://tavus.io/c1'], 200),
    ]);

    $guard = new TavusConcurrencyGuard;
    $response = $guard->attempt(fn () => tavusGuardCall(), 'SECRET_KEY');

    Http::assertSentCount(3);
    expect($response->successful())->toBeTrue();
});

test('gives up after the configured retry count and returns the last concurrency-limited response', function (): void {
    Http::fake([
        TAVUS_GUARD_TEST_URL => Http::response(['error' => 'Maximum concurrent conversations reached'], 400),
    ]);

    $guard = new TavusConcurrencyGuard;
    $response = $guard->attempt(fn () => tavusGuardCall(), 'SECRET_KEY');

    // 1 initial attempt + 3 configured retries = 4 total calls.
    Http::assertSentCount(4);
    expect($response->successful())->toBeFalse();
});

test('concurrency_retries=0 disables retrying entirely — a single attempt, no retry', function (): void {
    config(['interview.tavus.concurrency_retries' => 0]);

    Http::fake([
        TAVUS_GUARD_TEST_URL => Http::response(['error' => 'Maximum concurrent conversations reached'], 400),
    ]);

    $guard = new TavusConcurrencyGuard;
    $guard->attempt(fn () => tavusGuardCall(), 'SECRET_KEY');

    Http::assertSentCount(1);
});

test('does not retry a non-concurrency 4xx rejection', function (): void {
    Http::fake([
        TAVUS_GUARD_TEST_URL => Http::response(['error' => 'invalid persona_id'], 400),
    ]);

    $guard = new TavusConcurrencyGuard;
    $guard->attempt(fn () => tavusGuardCall(), 'SECRET_KEY');

    Http::assertSentCount(1);
});

test('does not retry a 5xx rejection — 5xx is never a concurrency-limit case', function (): void {
    Http::fake([
        TAVUS_GUARD_TEST_URL => Http::response(['error' => 'internal error'], 500),
    ]);

    $guard = new TavusConcurrencyGuard;
    $guard->attempt(fn () => tavusGuardCall(), 'SECRET_KEY');

    Http::assertSentCount(1);
});

test('does not retry a successful response', function (): void {
    Http::fake([
        TAVUS_GUARD_TEST_URL => Http::response(['conversation_id' => 'c1', 'conversation_url' => 'https://tavus.io/c1'], 200),
    ]);

    $guard = new TavusConcurrencyGuard;
    $guard->attempt(fn () => tavusGuardCall(), 'SECRET_KEY');

    Http::assertSentCount(1);
});

test('isConcurrencyLimited() matches the demo message pattern, case-insensitively', function (): void {
    Http::fake([
        TAVUS_GUARD_TEST_URL => Http::sequence()
            ->push(['message' => 'MAXIMUM CONCURRENT CONVERSATIONS reached'], 400)
            ->push(['message' => 'invalid field'], 400),
    ]);

    $guard = new TavusConcurrencyGuard;

    expect($guard->isConcurrencyLimited(tavusGuardCall(), ''))->toBeTrue();
    expect($guard->isConcurrencyLimited(tavusGuardCall(), ''))->toBeFalse();
});

/**
 * Strips comments/docblocks from PHP source before scanning it, so this
 * negative test checks EXECUTABLE code only — not the class docblocks that
 * legitimately name the rejected demo function/endpoint to explain why it
 * must never be restored.
 */
function tavusGuardStripComments(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

test('no executable code anywhere calls a "list active conversations" endpoint — the reap is explicitly rejected (design D8)', function (): void {
    $files = [
        base_path('app/Services/Provider/TavusProvider.php'),
        base_path('app/Services/Provider/TavusConcurrencyGuard.php'),
    ];

    foreach ($files as $file) {
        $code = tavusGuardStripComments((string) file_get_contents($file));

        // No GET call exists at all in either file: the reap requires
        // `GET /v2/conversations?status=active`, and neither the create nor
        // the teardown call is a GET.
        expect($code)->not->toContain('->get(');
        expect($code)->not->toContain('status=active');
        expect($code)->not->toContain('endActiveTavusConversations');
    }
});
