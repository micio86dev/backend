<?php

declare(strict_types=1);

namespace App\Services\ConversationLlm;

use App\Models\LlmModel;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Validates a Gemini API key against the live vendor endpoint
 * (pluggable-conversation-llm PR P2, design D9).
 *
 * `POST {base_url}chat/completions`, Bearer auth, the CHEAPEST available
 * registry model (by `text_input_usd_per_million`), `max_tokens: 1`. Cost per
 * probe is a fraction of a cent.
 *
 * Timeout and retry count are config-driven (`config/conversation_llm.php`,
 * `timeout_seconds` / `validation_retries`) — NOT literals. The retry applies
 * ONLY to a transport failure (timeout / connection exception); a deterministic
 * vendor response (any 4xx, including the 400 Google returns for a refused
 * key, or a 429) is never retried — see the `validate()` loop.
 *
 * The original 8s timeout was a plan estimate, never measured. Measured
 * 2026-08-26 against the live endpoint (`POST
 * https://generativelanguage.googleapis.com/v1beta/openai/chat/completions`,
 * `model: gemini-3-flash-preview`, `max_tokens: 1`) with a VALID key:
 *
 *   | run | outcome            | latency        |
 *   |-----|--------------------|----------------|
 *   | 1   | 200                | 4717 ms        |
 *   | 2   | 200                | 3906 ms        |
 *   | 3   | 200                | 6997 ms        |
 *   | 4   | 200                | 859 ms         |
 *   | 5   | 200                | 4129 ms        |
 *   | 6   | ConnectionException| 45061 ms (hung, never returned) |
 *
 * Median latency is ~4s and successes reach ~7s, so an 8s timeout misclassified
 * a large share of genuinely VALID keys as `unreachable` (3 runs through the
 * validator itself at 8s: unreachable / valid / unreachable). The default was
 * raised to 15s — comfortably above the 7.0s worst observed success, still
 * well short of the 45s hung tail — plus exactly ONE retry on the
 * transport-failure path, since raising the timeout alone would only make the
 * caller wait longer before hitting the same hung-connection failure mode.
 * Do NOT lower `timeout_seconds` back toward 8 without a fresh measurement.
 *
 * Returns one of four STABLE codes — `valid` | `invalid_key` | `rate_limited`
 * | `unreachable` — NEVER the vendor's own response text. This value travels
 * to a UI and is stored in `llm_credentials.validation_error`; echoing
 * Google's prose would make it both an i18n problem and, combined with the
 * absence of any "test without saving" endpoint, an oracle for probing keys
 * that belong to someone else.
 */
final class GeminiKeyValidator
{
    public function validate(string $apiKey): string
    {
        $model = LlmModel::where('is_available', true)
            ->whereNotNull('text_input_usd_per_million')
            ->orderBy('text_input_usd_per_million')
            ->first();

        if ($model === null) {
            // No priced model to probe with — refuse to guess. This branch is
            // unreachable in `managed` mode once the registry is seeded, and
            // is a tested guard against a future empty/misconfigured registry.
            return 'unreachable';
        }

        $timeoutSeconds = (int) config('conversation_llm.timeout_seconds', 15);
        $retries = max(0, (int) config('conversation_llm.validation_retries', 1));
        $maxAttempts = 1 + $retries;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::withToken($apiKey)
                    ->timeout($timeoutSeconds)
                    ->post($model->base_url.'chat/completions', [
                        'model' => $model->key,
                        'max_tokens' => 1,
                        'messages' => [['role' => 'user', 'content' => 'hi']],
                    ]);
            } catch (Throwable) {
                // Transport failure (timeout / connection exception): retry
                // once, per the measured evidence above. A deterministic
                // vendor response never reaches this branch, so a 4xx or a
                // 429 is NEVER retried.
                if ($attempt < $maxAttempts) {
                    continue;
                }

                return 'unreachable';
            }

            return match (true) {
                $response->successful() => 'valid',
                $response->status() === 429 => 'rate_limited',
                // A 4xx means the vendor ANSWERED and refused us, so it is
                // never `unreachable` — the one code that promises the
                // operator an automatic retry.
                //
                // 400 is in here because Google's OpenAI-compat surface
                // answers an auth failure with 400 and NEVER 401/403.
                // Measured against the live endpoint 2026-09-02:
                //   no Authorization header -> 400 "Missing or invalid
                //       Authorization header."
                //   bogus key               -> 400 "Please pass a valid API key"
                // The old 401/403 branch was therefore unreachable for this
                // vendor and every refused key fell through to `unreachable`,
                // telling the operator we could not reach a provider that had
                // answered in ~160ms, and promising a retry that can never
                // succeed.
                //
                // Classified on STATUS, not on the vendor's prose: matching
                // "API key" in a message is a rule Google can invalidate with
                // a copy edit, and the bug would come back with no test
                // failing. The range subsumes 401/403 too, so a proxy or a
                // future vendor that does use them still lands correctly.
                $response->status() >= 400 && $response->status() < 500 => 'invalid_key',
                // 5xx is the one server-side answer worth retrying.
                default => 'unreachable',
            };
        }

        // Unreachable in practice: the loop above always returns inside its
        // body (either a vendor response is classified, or the final
        // transport-failure attempt returns 'unreachable'). Kept only to
        // satisfy static analysis that every path returns a string.
        return 'unreachable';
    }
}
