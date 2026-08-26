<?php

declare(strict_types=1);

namespace App\Services\ConversationLlm;

/**
 * A resolved, ready-to-wire conversation-LLM binding
 * (pluggable-conversation-llm PR P3a, design D6).
 *
 * A readonly DTO with a redacting `__debugInfo()` is the smallest object
 * that cannot hand a plaintext key to a caller's `toArray()` or lazy
 * relation by accident — an Eloquent model could. Never passed to
 * `app/Http/Resources/`, `app/Http/Controllers/`, or `Log::` (enforced by
 * `LlmBindingContainmentArchTest`).
 */
final readonly class LlmBinding
{
    public function __construct(
        public string $modelKey,
        public string $baseUrl,
        #[\SensitiveParameter] public string $apiKey,
        public ?string $heygenConfigurationId,
    ) {}

    /**
     * dd() and Symfony VarDumper honour this. var_dump() does too.
     *
     * DOES NOT cover the var-export or print_r builtins — that mechanism
     * ignores __debugInfo() entirely and dumps private/protected properties
     * as well, so visibility is not a mitigation either. The real boundary
     * for those is `VarExportBanArchTest` banning the function outright
     * (design D6's stated residual).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'modelKey' => $this->modelKey,
            'baseUrl' => $this->baseUrl,
            'apiKey' => '[redacted]',
            'heygenConfigurationId' => $this->heygenConfigurationId,
        ];
    }
}
