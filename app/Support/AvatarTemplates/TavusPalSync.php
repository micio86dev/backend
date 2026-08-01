<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

use App\Models\AvatarTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes a template's persona-level knobs to its Tavus PAL (C14 PR5).
 *
 * Tavus splits its configuration across two API objects. The knobs with a
 * `palPath` — LLM model and temperature, TTS engine and voice, turn-taking,
 * interruptibility — live on the PERSONA, and sending them on a conversation
 * does nothing at all: no error, no effect, and an operator watching a setting
 * they configured make no difference.
 *
 * That is exactly the dead-knob problem this change refused to port from
 * avatar-tester's `voiceProvider`. Offering seventeen Tavus controls of which
 * nine silently do nothing would be the same defect, nine times over — hence
 * this class.
 *
 * NEVER THROWS. A template save must not fail because a cosmetic setting could
 * not be pushed to a third party: the operator's intent is already recorded in
 * our own database, and the sync can be retried by saving again. Failures come
 * back as a warning for the UI to surface, so the operator knows the knob has
 * not taken effect yet rather than believing it has.
 */
final class TavusPalSync
{
    private const BASE_URL = 'https://tavusapi.com/v2/pals';

    /** Bounded so a slow provider cannot hold an admin request open. */
    private const TIMEOUT_SECONDS = 10;

    /**
     * @return array{status: 'skipped'|'synced'|'warning', message?: string}
     */
    public function sync(AvatarTemplate $template): array
    {
        if ($template->provider !== 'tavus') {
            return ['status' => 'skipped'];
        }

        $layers = TemplatePayload::tavusPalLayers($template->config);

        if ($layers === []) {
            // Nothing to say must mean saying nothing: a PATCH carrying an
            // empty layers object would WIPE the persona's existing settings.
            return ['status' => 'skipped'];
        }

        $apiKey = (string) config('interview.tavus.api_key', '');

        if ($apiKey === '') {
            return [
                'status' => 'warning',
                'message' => 'tavus_key_missing',
            ];
        }

        $palId = $template->config['palId'] ?? null;

        if (! is_string($palId) || $palId === '') {
            return ['status' => 'warning', 'message' => 'pal_id_missing'];
        }

        try {
            // RFC-6902, replacing the WHOLE /layers node in one op rather than
            // one op per leaf. A `replace` on /layers/llm/model fails outright
            // when /layers/llm does not exist yet, and a persona that has never
            // been configured is precisely the common case.
            $response = Http::withHeaders(['x-api-key' => $apiKey])
                ->timeout(self::TIMEOUT_SECONDS)
                ->patch(self::BASE_URL.'/'.$palId, [
                    ['op' => 'add', 'path' => '/layers', 'value' => $layers],
                ]);

            // 304 means the PAL already holds these exact values, so Tavus made
            // no change. That is a successful no-op, not a failure.
            if ($response->successful() || $response->status() === 304) {
                return ['status' => 'synced'];
            }

            // The status, never the body. Tavus error text names the vendor and
            // can echo request content, and this string travels to a UI.
            Log::warning('Tavus PAL sync failed', [
                'template_id' => $template->id,
                'status' => $response->status(),
            ]);

            return ['status' => 'warning', 'message' => 'pal_sync_failed'];
        } catch (Throwable $e) {
            Log::warning('Tavus PAL sync errored', [
                'template_id' => $template->id,
                'exception' => $e::class,
            ]);

            return ['status' => 'warning', 'message' => 'pal_sync_unreachable'];
        }
    }
}
