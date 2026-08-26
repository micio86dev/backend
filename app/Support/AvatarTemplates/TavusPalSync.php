<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

use App\Models\AvatarTemplate;
use App\Services\ConversationLlm\LlmBindingResolver;
use App\Services\ConversationLlm\ManagedLlmPayload;
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

    public function __construct(private readonly LlmBindingResolver $bindingResolver) {}

    /**
     * @return array{status: 'skipped'|'synced'|'warning', message?: string}
     */
    public function sync(AvatarTemplate $template): array
    {
        if ($template->provider !== 'tavus') {
            return ['status' => 'skipped'];
        }

        $layers = TemplatePayload::tavusPalLayers($template->config);

        // The managed-mode binding (pluggable-conversation-llm PR P4, design
        // D7). `array_replace_recursive`, NOT `array_merge`: `llmTemperature`
        // writes `layers.llm.extra_body.temperature` and the binding writes
        // `layers.llm.{model,base_url,api_key}` — both inside the SAME `llm`
        // node. A shallow merge replaces the whole node and drops one side,
        // the identical trap `HeygenProvider.php:227` already solves for
        // `avatar_persona`. `resolve()` never throws — an unbound template
        // (the common case) leaves `$layers` untouched.
        //
        // @wire-source live Tavus API smoke-check, 2026-08-26: Tavus does NOT
        // retain a previously-submitted `layers.llm.api_key` across PATCHes —
        // omitting it returns HTTP 400, not a silent no-op. The key is
        // therefore re-read from the binding and re-sent on EVERY sync call,
        // never assumed to still be on file from a prior PATCH.
        $binding = $this->bindingResolver->resolve($template);

        if ($binding !== null) {
            $layers = array_replace_recursive($layers, ManagedLlmPayload::forTavusLayers($binding));
        }

        if ($layers === []) {
            // Nothing to say must mean saying nothing: a PATCH carrying an
            // empty layers object would WIPE the persona's existing settings.
            // MUST run AFTER the binding merge above — a template whose ONLY
            // LLM configuration is the binding produces `[]` from
            // `tavusPalLayers()` alone, and checking this guard first would
            // skip it forever, silently never syncing the binding it exists
            // to push (design D7's rationale — a certainty, not a risk).
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
