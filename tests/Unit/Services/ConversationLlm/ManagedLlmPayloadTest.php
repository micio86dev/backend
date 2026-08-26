<?php

declare(strict_types=1);

/**
 * `ManagedLlmPayload` — one pure mapper feeding both wires
 * (pluggable-conversation-llm PR P3a, design D6).
 *
 * Pure: no HTTP, no facades, no `Log::`. Both shapes are asserted exactly,
 * because P4/P5 merge these fragments verbatim into a provider body.
 */

use App\Services\ConversationLlm\LlmBinding;
use App\Services\ConversationLlm\ManagedLlmPayload;

function managedLlmPayloadBinding(): LlmBinding
{
    return new LlmBinding(
        modelKey: 'gemini-3-flash-preview',
        baseUrl: 'https://generativelanguage.googleapis.com/v1beta/openai/',
        apiKey: 'sk-real-key',
        heygenConfigurationId: 'hg-config-42',
    );
}

test('forTavusLayers shapes the layers.llm fragment', function (): void {
    $layers = ManagedLlmPayload::forTavusLayers(managedLlmPayloadBinding());

    expect($layers)->toBe([
        'llm' => [
            'model' => 'gemini-3-flash-preview',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'api_key' => 'sk-real-key',
        ],
    ]);
});

test('forHeygenSessionToken shapes the llm_configuration_id fragment', function (): void {
    $fragment = ManagedLlmPayload::forHeygenSessionToken(managedLlmPayloadBinding());

    expect($fragment)->toBe(['llm_configuration_id' => 'hg-config-42']);
});
