<?php

declare(strict_types=1);

namespace App\Services\ConversationLlm;

/**
 * The one pure mapper feeding both provider wires
 * (pluggable-conversation-llm PR P3a, design D6).
 *
 * Pure: no HTTP, no facades, no logging. A readonly `LlmBinding` in, a plain
 * array fragment out — the caller merges it into the provider's real wire
 * body.
 */
final class ManagedLlmPayload
{
    /**
     * @return array{llm: array{model: string, base_url: string, api_key: string}}
     */
    public static function forTavusLayers(LlmBinding $binding): array
    {
        return [
            'llm' => [
                'model' => $binding->modelKey,
                'base_url' => $binding->baseUrl,
                'api_key' => $binding->apiKey,
            ],
        ];
    }

    /**
     * @return array{llm_configuration_id: string}
     */
    public static function forHeygenSessionToken(LlmBinding $binding): array
    {
        return ['llm_configuration_id' => (string) $binding->heygenConfigurationId];
    }
}
