<?php

declare(strict_types=1);

namespace App\Actions\AvatarTemplates;

use App\Services\ConversationLlm\HeygenVoiceRegistrar;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
use App\Support\AvatarTemplates\TemplateReferenceValidator;
use Illuminate\Validation\ValidationException;

/**
 * Makes a PLATFORM HeyGen template's external voice usable before the template
 * is written (heygen-third-party-voices H2).
 *
 * Runs AFTER the config is valid and BEFORE the template row and its audit row
 * are written, so a refusal here leaves nothing behind: no template, and no
 * ledger row either (the registrar only records a bind LiveAvatar confirmed).
 * The voice is first checked against the vendor catalogue STRICTLY, because
 * LiveAvatar accepts any provider voice id with HTTP 200 and a bad id that got
 * bound would be kept forever.
 *
 * Every refusal is a 422 on `config.ttsExternalVoiceId` with a stable code
 * (`tts_voice_not_found`, `tts_voice_unverifiable`, or one of
 * `HeygenVoiceRegistrar::FAILURES`), never a 500 and never a silently wrong
 * template. Binding the same voice again is free: the registrar's ledger
 * answers without calling LiveAvatar.
 */
final class BindHeygenTemplateVoice
{
    public function __construct(private readonly HeygenVoiceRegistrar $registrar) {}

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ValidationException
     */
    public function run(string $provider, array $config): void
    {
        $engine = $config['ttsEngine'] ?? null;
        $voiceId = $config['ttsExternalVoiceId'] ?? null;

        if ($provider !== 'heygen' || ! in_array($engine, ProviderFieldSpecs::HEYGEN_EXTERNAL_ENGINES, true) || ! is_string($voiceId)) {
            return;
        }

        $voiceId = trim($voiceId);

        $problem = TemplateReferenceValidator::externalVoiceProblem($engine, $voiceId);

        if ($problem !== null) {
            $this->refuse($problem);
        }

        $result = $this->registrar->ensureVoice($engine, $voiceId);

        if ($result['status'] === 'failed') {
            $this->refuse($result['code']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $code): never
    {
        throw ValidationException::withMessages(['config.ttsExternalVoiceId' => $code]);
    }
}
