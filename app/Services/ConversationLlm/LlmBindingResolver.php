<?php

declare(strict_types=1);

namespace App\Services\ConversationLlm;

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
}
