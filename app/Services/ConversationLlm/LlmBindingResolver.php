<?php

declare(strict_types=1);

namespace App\Services\ConversationLlm;

use App\Enums\LlmBindingStatus;
use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use Throwable;

/**
 * Resolves a template's binding into a ready-to-wire `LlmBinding`
 * (pluggable-conversation-llm PR P3a, design D6).
 *
 * NEVER throws. Returns `null` for unbound and for a revoked/missing
 * credential — an interview must not fail to start because a cost preference
 * could not be read, the same doctrine `ActiveTemplateResolver` already states
 * for its own null return.
 *
 * The third null case — a cross-org credential — is GONE. Credentials became
 * platform rows (RATIFIED 2026-09-14) and there is no other org to be from.
 */
final class LlmBindingResolver
{
    public function resolve(AvatarTemplate $template): ?LlmBinding
    {
        if ($template->llm_model_id === null || $template->llm_credential_id === null) {
            return null;
        }

        try {
            $model = LlmModel::find($template->llm_model_id);
            $credential = LlmCredential::find($template->llm_credential_id);

            if ($model === null || $credential === null) {
                return null;
            }

            // NO cross-org comparison any more, and its absence IS the change
            // rather than an omission. One key serves every tenant now, so
            // `$credential->organization_id !== $template->organization_id`
            // would refuse the only arrangement that exists — and the column
            // it read no longer exists either.
            //
            // `withoutGlobalScopes()` went with it: it was there to defeat the
            // tenant scope so the comparison could be made EXPLICITLY rather
            // than implicitly, and `LlmCredential` carries no global scope to
            // defeat now.

            return new LlmBinding(
                modelKey: $model->key,
                baseUrl: $model->base_url,
                apiKey: $credential->api_key,
                heygenConfigurationId: $template->heygen_llm_configuration_id,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The tri-state billing decision (design D0):
     * `applied ⇔ binding present ∧ credential resolvable ∧ llm_sync_status
     * === 'synced'`. Decided from PERSISTED state only — never HTTP — so
     * this stays pure DB, matching `resolve()`'s own contract.
     *
     * NULL `llm_sync_status` is NOT `'synced'`: every path that never pushed
     * — a portability import (D13), a seeder-written row, a save whose PATCH
     * timed out — fails CLOSED into `Degraded`, never `Applied`. Only
     * `Applied` is billable.
     */
    public function resolveStatus(AvatarTemplate $template): LlmBindingStatus
    {
        $binding = $this->resolve($template);

        if ($binding === null) {
            return LlmBindingStatus::Unbound;
        }

        return $template->llm_sync_status === 'synced'
            ? LlmBindingStatus::Applied
            : LlmBindingStatus::Degraded;
    }
}
