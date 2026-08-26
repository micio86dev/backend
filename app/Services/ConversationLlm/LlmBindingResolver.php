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
 * NEVER throws. Returns `null` for unbound, for a revoked/missing
 * credential, and for a cross-org credential (defense in depth against data
 * corruption, since I3 already refuses this at save time) — an interview
 * must not fail to start because a cost preference could not be read, the
 * same doctrine `ActiveTemplateResolver` already states for its own null
 * return.
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
            $credential = LlmCredential::withoutGlobalScopes()->find($template->llm_credential_id);

            if ($model === null || $credential === null) {
                return null;
            }

            if ($credential->organization_id !== $template->organization_id) {
                return null;
            }

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
