<?php

declare(strict_types=1);

use App\Support\AvatarTemplates\ConfigValidator;
use App\Support\AvatarTemplates\ProviderFieldSpecs;

// The engine-support rule is a HeyGen rule: LiveAvatar's Cartesia settings object has no stability /
// similarity / style / speaker-boost. It must never reach another provider that happens to spell a
// field the same way (native review R3-001 on feature/heygen-third-party-voices).

test('the Cartesia-unsupported knobs are listed for heygen only', function (): void {
    expect(ProviderFieldSpecs::unsupportedKnobs('heygen', 'cartesia'))
        ->toBe(['voiceStability', 'voiceSimilarityBoost', 'voiceStyle', 'voiceUseSpeakerBoost'])
        ->and(ProviderFieldSpecs::unsupportedKnobs('tavus', 'cartesia'))->toBe([])
        ->and(ProviderFieldSpecs::unsupportedKnobs('heygen', 'elevenlabs'))->toBe([])
        ->and(ProviderFieldSpecs::unsupportedKnobs('heygen', 'none'))->toBe([])
        ->and(ProviderFieldSpecs::unsupportedKnobs('unknown-provider', 'cartesia'))->toBe([]);
});

test('a tavus cartesia template is never refused for a heygen-only engine rule', function (): void {
    $errors = ConfigValidator::validate('tavus', ['ttsEngine' => 'cartesia', 'ttsExternalVoiceId' => 'voice-id']);

    expect(array_column($errors, 'code'))->not->toContain('tts_setting_unsupported');
});

test('a heygen cartesia template still refuses an ElevenLabs-only knob', function (): void {
    $errors = ConfigValidator::validate('heygen', [
        'avatarId' => 'a',
        'ttsEngine' => 'cartesia',
        'ttsExternalVoiceId' => 'voice-id',
        'voiceStability' => 0.5,
    ], true);

    expect($errors)->toContain(['key' => 'voiceStability', 'code' => 'tts_setting_unsupported']);
});

test('the Cartesia-unsupported knobs tell the form which engine replaces them, so it can hide them', function (): void {
    $byKey = [];

    foreach (ProviderFieldSpecs::for('heygen') as $field) {
        $byKey[$field->key] = $field->toArray();
    }

    foreach (['voiceStability', 'voiceSimilarityBoost', 'voiceStyle', 'voiceUseSpeakerBoost'] as $key) {
        expect($byKey[$key])->toMatchArray(['superseded_by_key' => 'ttsEngine', 'superseded_by_values' => ['cartesia']]);
    }

    // Speed is a Cartesia setting and the native voice id keeps its own rule: neither is hidden by the Cartesia knob rule.
    expect($byKey['voiceSpeed'])->not->toHaveKey('superseded_by_key')
        ->and($byKey['voiceId']['superseded_by_values'])->toBe(['cartesia', 'elevenlabs']);
});

test('a hidden knob that still arrives keeps the engine-specific refusal, not the native-voice one', function (): void {
    $errors = ConfigValidator::validate('heygen', [
        'avatarId' => 'a',
        'ttsEngine' => 'cartesia',
        'ttsExternalVoiceId' => 'voice-id',
        'voiceStyle' => 0.2,
    ], true);

    expect($errors)->toBe([['key' => 'voiceStyle', 'code' => 'tts_setting_unsupported']]);
});
