<?php

declare(strict_types=1);

/**
 * `LlmBinding::__debugInfo()` redacts the API key under `var_dump()`/`dd()`
 * (pluggable-conversation-llm PR P3a, design D6).
 *
 * `__debugInfo()` covers `var_dump()` and Symfony VarDumper (dd()/dump()).
 * It does NOT cover `var_export()` or `print_r()` — see D6's stated
 * residual. No test is named for that guarantee; it does not exist. The
 * real boundary for `var_export()` is the arch test banning it outright.
 *
 * REQ: conversation-llm — LlmBinding containment (design D6)
 */

use App\Services\ConversationLlm\LlmBinding;

test('var_dump redacts the api key', function (): void {
    $binding = new LlmBinding(
        modelKey: 'gemini-3-flash-preview',
        baseUrl: 'https://generativelanguage.googleapis.com/v1beta/openai/',
        apiKey: 'sk-super-secret-key',
        heygenConfigurationId: null,
    );

    ob_start();
    var_dump($binding);
    $output = ob_get_clean();

    expect($output)->not->toContain('sk-super-secret-key');
    expect($output)->toContain('[redacted]');
});

test('dd/dump output (via __debugInfo) redacts the api key', function (): void {
    $binding = new LlmBinding(
        modelKey: 'gemini-3-flash-preview',
        baseUrl: 'https://generativelanguage.googleapis.com/v1beta/openai/',
        apiKey: 'sk-super-secret-key',
        heygenConfigurationId: 'hg-config-1',
    );

    $debugInfo = $binding->__debugInfo();

    expect($debugInfo['apiKey'])->toBe('[redacted]');
    expect(json_encode($debugInfo))->not->toContain('sk-super-secret-key');
});
