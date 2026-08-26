<?php

declare(strict_types=1);

namespace App\Services\ConversationLlm;

use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Owns the lifecycle of a template's HeyGen `llm_configuration` and the
 * underlying vendor secret (pluggable-conversation-llm PR P5, design D8).
 *
 * Mirrors `TavusPalSync`'s contract VERBATIM — same return shape, same
 * "NEVER THROWS" doctrine — so the backoffice's existing warning banner
 * renders both providers with no new UI surface. Unlike `TavusPalSync`
 * (which lives in the DB-write-free `Support/AvatarTemplates/` namespace,
 * design C-E), this class lives in `Services/ConversationLlm/` and DOES
 * persist: `heygen_llm_configuration_id` on the template row, and
 * `heygen_secret_id` on the credential row, ARE the orphan ledger — there is
 * no second registry to drift from them (design D8).
 *
 * @wire-source live HeyGen API smoke-check, 2026-08-26. `POST /v1/secrets`
 * with `{secret_type:'OPENAI_API_KEY', secret_value, secret_name}` → HTTP
 * 200, envelope `{code, data:{id, secret_name}, message}` — the id is at
 * `data.id`, NOT top level. `secret_name` is NOT unique: two POSTs with the
 * identical name both succeed and return DIFFERENT ids, so this class MUST
 * NEVER look a secret up by name — the stored `heygen_secret_id` is the only
 * reliable handle. Secrets are IMMUTABLE (`PATCH`/`PUT /v1/secrets/{id}` →
 * 405 on the real API), so rotation is delete-then-recreate, never an
 * update. `POST /v1/llm-configurations` with `{display_name, model_name,
 * base_url, secret_id}` echoes `{id, base_url, display_name, model_name,
 * secret_id}` under `data`. These management endpoints live at
 * `api.heygen.com`, a DIFFERENT domain from the LiveAvatar session API
 * (`api.liveavatar.com`) `HeygenProvider` calls for `/contexts` and
 * `/sessions/token`.
 */
final class HeygenLlmRegistrar
{
    private const SECRETS_URL = 'https://api.heygen.com/v1/secrets';

    private const CONFIGURATIONS_URL = 'https://api.heygen.com/v1/llm-configurations';

    /** Bounded so a slow provider cannot hold an admin request open. */
    private const TIMEOUT_SECONDS = 10;

    /**
     * The credential's HeyGen secret id, creating it on first use.
     *
     * MEMOIZED via the stored `heygen_secret_id` — a second call for the
     * same credential issues NO second `POST`. This matters precisely
     * because `secret_name` is NOT unique on the vendor side: a duplicate
     * POST would silently create an orphan, discoverable later only by name
     * PREFIX (design D8's accepted disclosure).
     *
     * Returns `null` (never throws) when the platform HeyGen key is
     * unconfigured, the vendor call fails, or the response is malformed.
     */
    public function ensureSecret(LlmCredential $credential): ?string
    {
        if ($credential->heygen_secret_id !== null) {
            return $credential->heygen_secret_id;
        }

        $apiKey = (string) config('interview.heygen.api_key', '');

        if ($apiKey === '') {
            return null;
        }

        try {
            $response = Http::withHeaders(['X-API-KEY' => $apiKey])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::SECRETS_URL, [
                    'secret_type' => 'OPENAI_API_KEY',
                    'secret_value' => $credential->api_key,
                    'secret_name' => $this->secretName($credential),
                ]);
        } catch (Throwable $e) {
            Log::warning('HeyGen secret registration errored', [
                'credential_id' => $credential->id,
                'exception' => $e::class,
            ]);

            return null;
        }

        if (! $response->successful()) {
            // The status only, never the body — a vendor error can echo
            // request content, and `secret_value` carries the plaintext key.
            Log::warning('HeyGen secret registration failed', [
                'credential_id' => $credential->id,
                'status' => $response->status(),
            ]);

            return null;
        }

        $secretId = $response->json('data.id');

        if (! is_string($secretId) || $secretId === '') {
            Log::warning('HeyGen secret registration returned a malformed id', [
                'credential_id' => $credential->id,
            ]);

            return null;
        }

        $credential->forceFill(['heygen_secret_id' => $secretId])->saveQuietly();

        return $secretId;
    }

    /**
     * Create, update, or tear down a template's HeyGen `llm_configuration`
     * to match its current binding.
     *
     * @return array{status: 'skipped'|'synced'|'warning', message?: string}
     */
    public function ensureConfiguration(AvatarTemplate $template): array
    {
        if ($template->provider !== 'heygen') {
            return ['status' => 'skipped'];
        }

        if ($template->llm_model_id === null || $template->llm_credential_id === null) {
            // Unbound is always legal, and the common case. Any stale
            // configuration from a PRIOR binding must not linger — that
            // would be a live orphan billing whoever's credential it still
            // references.
            $this->forget($template);

            return ['status' => 'skipped'];
        }

        $apiKey = (string) config('interview.heygen.api_key', '');

        if ($apiKey === '') {
            return ['status' => 'warning', 'message' => 'llm_provider_unreachable'];
        }

        try {
            $model = LlmModel::find($template->llm_model_id);
            $credential = LlmCredential::withoutGlobalScopes()->find($template->llm_credential_id);
        } catch (Throwable) {
            return ['status' => 'warning', 'message' => 'llm_credential_missing'];
        }

        // Defense in depth, mirroring `LlmBindingResolver::resolve()` — I3
        // already refuses a cross-org credential at save time, but this
        // class must not trust that a row it is handed still satisfies it.
        if ($model === null || $credential === null || $credential->organization_id !== $template->organization_id) {
            return ['status' => 'warning', 'message' => 'llm_credential_missing'];
        }

        $secretId = $this->ensureSecret($credential);

        if ($secretId === null) {
            return ['status' => 'warning', 'message' => 'llm_secret_failed'];
        }

        $body = [
            'display_name' => $this->configurationDisplayName($template),
            'model_name' => $model->key,
            'base_url' => $model->base_url,
            'secret_id' => $secretId,
        ];

        return $this->createOrUpdateConfiguration($template, $apiKey, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: 'skipped'|'synced'|'warning', message?: string}
     */
    private function createOrUpdateConfiguration(AvatarTemplate $template, string $apiKey, array $body): array
    {
        $existingId = $template->heygen_llm_configuration_id;

        try {
            if ($existingId !== null) {
                $response = Http::withHeaders(['X-API-KEY' => $apiKey])
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->patch(self::CONFIGURATIONS_URL.'/'.$existingId, $body);

                if ($response->status() === 404) {
                    // Someone deleted it in HeyGen's own dashboard. Clear the
                    // stale ledger id and retry EXACTLY ONCE as a create —
                    // never loop, a persistently-404ing account issue must
                    // surface as a warning, not hang the admin request.
                    $template->forceFill(['heygen_llm_configuration_id' => null])->saveQuietly();

                    $response = Http::withHeaders(['X-API-KEY' => $apiKey])
                        ->timeout(self::TIMEOUT_SECONDS)
                        ->post(self::CONFIGURATIONS_URL, $body);
                }
            } else {
                $response = Http::withHeaders(['X-API-KEY' => $apiKey])
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->post(self::CONFIGURATIONS_URL, $body);
            }
        } catch (Throwable $e) {
            Log::warning('HeyGen LLM configuration sync errored', [
                'template_id' => $template->id,
                'exception' => $e::class,
            ]);

            return ['status' => 'warning', 'message' => 'llm_provider_unreachable'];
        }

        if (! $response->successful()) {
            Log::warning('HeyGen LLM configuration sync failed', [
                'template_id' => $template->id,
                'status' => $response->status(),
            ]);

            return ['status' => 'warning', 'message' => 'llm_config_failed'];
        }

        $configurationId = $response->json('data.id');

        if (! is_string($configurationId) || $configurationId === '') {
            Log::warning('HeyGen LLM configuration sync returned a malformed id', [
                'template_id' => $template->id,
            ]);

            return ['status' => 'warning', 'message' => 'llm_config_failed'];
        }

        $template->forceFill(['heygen_llm_configuration_id' => $configurationId])->saveQuietly();

        return ['status' => 'synced'];
    }

    /**
     * Delete the template's HeyGen `llm_configuration` and clear the stored
     * ledger id. Called on unbind (`ensureConfiguration()` above) and on
     * `destroy()` (the controller).
     *
     * NEVER THROWS, and clears the column even when the vendor call fails —
     * an unreachable HeyGen account must not block an operator from
     * deleting or unbinding their OWN template; the id is a ledger of what
     * WE think exists, and once we've stopped referencing it, holding onto
     * a possibly-already-gone id serves no purpose.
     */
    public function forget(AvatarTemplate $template): void
    {
        $configurationId = $template->heygen_llm_configuration_id;

        if ($configurationId === null) {
            return;
        }

        $apiKey = (string) config('interview.heygen.api_key', '');

        if ($apiKey !== '') {
            try {
                $response = Http::withHeaders(['X-API-KEY' => $apiKey])
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->delete(self::CONFIGURATIONS_URL.'/'.$configurationId);

                if (! $response->successful() && $response->status() !== 404) {
                    Log::warning('HeyGen LLM configuration delete failed', [
                        'template_id' => $template->id,
                        'status' => $response->status(),
                    ]);
                }
            } catch (Throwable $e) {
                Log::warning('HeyGen LLM configuration delete errored', [
                    'template_id' => $template->id,
                    'exception' => $e::class,
                ]);
            }
        }

        $template->forceFill(['heygen_llm_configuration_id' => null])->saveQuietly();
    }

    /**
     * Delete the credential's HeyGen secret and clear the stored id. Called
     * by `rotateSecret()` below, and by the controller on a credential
     * `destroy()` once nothing references it (D2's 409 gate).
     *
     * NEVER THROWS, and clears the column even when the vendor call fails —
     * same rationale as `forget()`.
     */
    public function forgetSecret(LlmCredential $credential): void
    {
        $secretId = $credential->heygen_secret_id;

        if ($secretId === null) {
            return;
        }

        $apiKey = (string) config('interview.heygen.api_key', '');

        if ($apiKey !== '') {
            try {
                $response = Http::withHeaders(['X-API-KEY' => $apiKey])
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->delete(self::SECRETS_URL.'/'.$secretId);

                if (! $response->successful() && $response->status() !== 404) {
                    Log::warning('HeyGen secret delete failed', [
                        'credential_id' => $credential->id,
                        'status' => $response->status(),
                    ]);
                }
            } catch (Throwable $e) {
                Log::warning('HeyGen secret delete errored', [
                    'credential_id' => $credential->id,
                    'exception' => $e::class,
                ]);
            }
        }

        $credential->forceFill(['heygen_secret_id' => null])->saveQuietly();
    }

    /**
     * Rotate a credential's HeyGen secret: delete-then-recreate (secrets are
     * IMMUTABLE on the vendor side — `PATCH`/`PUT /v1/secrets/{id}` both
     * return 405 on the real API), then re-point every configuration bound
     * to it via the `(organization_id, llm_credential_id)` index.
     *
     * @return array{status: 'skipped'|'synced'|'warning', message?: string}
     */
    public function rotateSecret(LlmCredential $credential): array
    {
        $this->forgetSecret($credential);

        $secretId = $this->ensureSecret($credential);

        if ($secretId === null) {
            return ['status' => 'warning', 'message' => 'llm_secret_failed'];
        }

        $templates = AvatarTemplate::withoutGlobalScopes()
            ->where('organization_id', $credential->organization_id)
            ->where('llm_credential_id', $credential->id)
            ->where('provider', 'heygen')
            ->get();

        $anyFailed = false;

        foreach ($templates as $template) {
            $result = $this->ensureConfiguration($template);

            if ($result['status'] === 'warning') {
                $anyFailed = true;
            }
        }

        return $anyFailed
            ? ['status' => 'warning', 'message' => 'llm_config_failed']
            : ['status' => 'synced'];
    }

    /**
     * `secret_name` is NOT unique on the vendor side (Phase 0.3 live
     * evidence), so this namespacing is a HUMAN-readable label for BEAI's
     * own HeyGen dashboard, never a lookup key — `heygen_secret_id` is the
     * only reliable handle (see class docblock).
     */
    private function secretName(LlmCredential $credential): string
    {
        return sprintf('beai-org%d-cred%d', $credential->organization_id, $credential->id);
    }

    private function configurationDisplayName(AvatarTemplate $template): string
    {
        return sprintf('beai-org%d-template%d', $template->organization_id, $template->id);
    }
}
