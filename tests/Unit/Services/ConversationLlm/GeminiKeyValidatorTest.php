<?php

declare(strict_types=1);

/**
 * GeminiKeyValidator (pluggable-conversation-llm PR P2, design D9,
 * non-negotiable #11).
 *
 * `POST {base_url}chat/completions`, Bearer auth, the cheapest AVAILABLE
 * registry model, `max_tokens: 1`, 8s timeout. Returns a STABLE code — never
 * Google's prose, because it travels to a UI and would be an oracle.
 *
 * REQ: conversation-llm "Credential validation returns a stable code, never
 *      the vendor's prose, and cannot become a key-testing oracle"
 */

use App\Models\LlmModel;
use App\Services\ConversationLlm\GeminiKeyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function seedCheapestGeminiModel(): LlmModel
{
    return LlmModel::create([
        'key' => 'gemini-3-flash-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
        'text_input_usd_per_million' => '0.075000',
        'text_output_usd_per_million' => '0.300000',
    ]);
}

test('a 200 response classifies as valid', function (): void {
    seedCheapestGeminiModel();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['choices' => []], 200),
    ]);

    $code = app(GeminiKeyValidator::class)->validate('sk-real-key');

    expect($code)->toBe('valid');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-real-key')
            && $request['max_tokens'] === 1
            && $request['model'] === 'gemini-3-flash-preview';
    });
});

test('a 401 classifies as invalid_key', function (): void {
    seedCheapestGeminiModel();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Google says the key is bad'], 401),
    ]);

    expect(app(GeminiKeyValidator::class)->validate('sk-bad-key'))->toBe('invalid_key');
});

test('a 403 also classifies as invalid_key', function (): void {
    seedCheapestGeminiModel();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 403),
    ]);

    expect(app(GeminiKeyValidator::class)->validate('sk-bad-key'))->toBe('invalid_key');
});

test('a 429 classifies as rate_limited', function (): void {
    seedCheapestGeminiModel();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 429),
    ]);

    expect(app(GeminiKeyValidator::class)->validate('sk-real-key'))->toBe('rate_limited');
});

test('a 500 classifies as unreachable', function (): void {
    seedCheapestGeminiModel();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 500),
    ]);

    expect(app(GeminiKeyValidator::class)->validate('sk-real-key'))->toBe('unreachable');
});

test('a timeout classifies as unreachable', function (): void {
    seedCheapestGeminiModel();
    Http::fake(function (): void {
        throw new ConnectionException('Connection timed out');
    });

    expect(app(GeminiKeyValidator::class)->validate('sk-real-key'))->toBe('unreachable');
});

test('the vendor response body never surfaces in the returned code', function (): void {
    seedCheapestGeminiModel();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => 'a very specific Google message'], 401),
    ]);

    $code = app(GeminiKeyValidator::class)->validate('sk-bad-key');

    expect($code)->not->toContain('Google');
    expect($code)->toBe('invalid_key');
});

test('the request selects the cheapest available registry model', function (): void {
    LlmModel::create([
        'key' => 'gemini-3.1-pro-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3.1 Pro Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
        'text_input_usd_per_million' => '2.000000',
        'text_output_usd_per_million' => '12.000000',
    ]);
    seedCheapestGeminiModel();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 200),
    ]);

    app(GeminiKeyValidator::class)->validate('sk-real-key');

    Http::assertSent(fn (Request $request): bool => $request['model'] === 'gemini-3-flash-preview');
});

// ---------------------------------------------------------------------------
// Single-retry-on-transport-failure (measured 2026-08-26 against the live
// endpoint — see the class docblock for the raw latency table).
// ---------------------------------------------------------------------------

test('a transport failure followed by a success classifies as valid (one retry)', function (): void {
    seedCheapestGeminiModel();

    // Http::fake's recorder middleware only records a REQUEST/RESPONSE pair
    // on the success path (it hooks the promise's `.then()`), so a thrown
    // ConnectionException never reaches assertSentCount(). Count attempts
    // ourselves instead.
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw new ConnectionException('Connection timed out');
        }

        return Http::response(['choices' => []], 200);
    });

    expect(app(GeminiKeyValidator::class)->validate('sk-real-key'))->toBe('valid');

    expect($attempts)->toBe(2);
});

test('two consecutive transport failures classify as unreachable (retries exactly once)', function (): void {
    seedCheapestGeminiModel();

    $attempts = 0;
    Http::fake(function () use (&$attempts): void {
        $attempts++;

        throw new ConnectionException('Connection timed out');
    });

    expect(app(GeminiKeyValidator::class)->validate('sk-real-key'))->toBe('unreachable');

    expect($attempts)->toBe(2);
});

test('a 401 classifies as invalid_key without a second HTTP attempt', function (): void {
    seedCheapestGeminiModel();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => 'bad key'], 401),
    ]);

    expect(app(GeminiKeyValidator::class)->validate('sk-bad-key'))->toBe('invalid_key');

    Http::assertSentCount(1);
});

test('a 429 classifies as rate_limited without a second HTTP attempt', function (): void {
    seedCheapestGeminiModel();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 429),
    ]);

    expect(app(GeminiKeyValidator::class)->validate('sk-real-key'))->toBe('rate_limited');

    Http::assertSentCount(1);
});

test('the timeout is read from config, not hardcoded', function (): void {
    config(['conversation_llm.timeout_seconds' => 3]);
    seedCheapestGeminiModel();

    $seenTimeout = null;
    Http::fake(function (Request $request, array $options) use (&$seenTimeout) {
        $seenTimeout = $options['timeout'] ?? null;

        return Http::response(['choices' => []], 200);
    });

    app(GeminiKeyValidator::class)->validate('sk-real-key');

    expect($seenTimeout)->toBe(3);
});

test('the retry count is read from config, not hardcoded', function (): void {
    config(['conversation_llm.validation_retries' => 0]);
    seedCheapestGeminiModel();

    $attempts = 0;
    Http::fake(function () use (&$attempts): void {
        $attempts++;

        throw new ConnectionException('Connection timed out');
    });

    expect(app(GeminiKeyValidator::class)->validate('sk-real-key'))->toBe('unreachable');

    // With validation_retries = 0, only the initial attempt is made — no retry.
    expect($attempts)->toBe(1);
});

/**
 * Google's OpenAI-compat surface answers an auth failure with 400, NEVER
 * 401/403. Measured against the live endpoint 2026-09-02 from inside the api
 * container:
 *
 *   no Authorization header -> 400 {"error":{"code":400,
 *       "message":"Missing or invalid Authorization header.",
 *       "status":"INVALID_ARGUMENT"}}
 *   bogus key               -> 400 {"error":{"code":400,
 *       "message":"Please pass a valid API key",
 *       "status":"INVALID_ARGUMENT"}}
 *
 * So the 401/403 branch was unreachable for this vendor and every refused key
 * fell through `default` to `unreachable` — telling the operator "we could not
 * reach the provider, it will be retried automatically" when the provider had
 * answered in ~160ms and retrying can never help.
 *
 * Classified on STATUS, deliberately not on the vendor's prose: matching
 * "API key" in a message is a rule Google can silently invalidate with a copy
 * edit, and this bug would return with no test failing.
 */
test('a 400 classifies as invalid_key, not unreachable', function (): void {
    seedCheapestGeminiModel();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 400,
                'message' => 'Please pass a valid API key',
                'status' => 'INVALID_ARGUMENT',
            ],
        ], 400),
    ]);

    expect(app(GeminiKeyValidator::class)->validate('bogus'))->toBe('invalid_key');
});

/**
 * A 5xx is the one server-side answer worth retrying, so it stays
 * `unreachable` — the code that promises an automatic retry — while a 4xx
 * never does.
 */
test('a 500 stays unreachable', function (): void {
    seedCheapestGeminiModel();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => 'boom'], 500),
    ]);

    expect(app(GeminiKeyValidator::class)->validate('sk-real-key'))->toBe('unreachable');
});

/** A 429 keeps its own code — it is neither a bad key nor an outage. */
test('a 429 still classifies as rate_limited', function (): void {
    seedCheapestGeminiModel();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => 'slow down'], 429),
    ]);

    expect(app(GeminiKeyValidator::class)->validate('sk-real-key'))->toBe('rate_limited');
});
