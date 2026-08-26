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
 * registry model (by `text_input_usd_per_million`), `max_tokens: 1`, an 8s
 * timeout. Cost per probe is a fraction of a cent.
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
    private const TIMEOUT_SECONDS = 8;

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

        try {
            $response = Http::withToken($apiKey)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($model->base_url.'chat/completions', [
                    'model' => $model->key,
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'hi']],
                ]);
        } catch (Throwable) {
            return 'unreachable';
        }

        return match (true) {
            $response->successful() => 'valid',
            in_array($response->status(), [401, 403], true) => 'invalid_key',
            $response->status() === 429 => 'rate_limited',
            default => 'unreachable',
        };
    }
}
