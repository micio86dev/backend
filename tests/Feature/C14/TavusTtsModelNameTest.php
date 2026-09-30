<?php

declare(strict_types=1);

/**
 * `ttsModelName` — the Tavus persona's TTS model.
 *
 * The PAL PATCH replaces the WHOLE `/layers` node, so a model BEAI does not send
 * falls back to Tavus's own default, which may not speak Italian (Tavus docs:
 * Cartesia `sonic-3` supports it, `sonic-2` does not). BEAI therefore always
 * sends one for the engines that take a model, and validates that the model
 * belongs to the chosen engine.
 */

use App\Models\Organization;
use App\Support\AvatarTemplates\ConfigValidator;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
use App\Support\AvatarTemplates\TemplatePayload;

/** @return array<string, mixed> */
function ttsModelSpec(): array
{
    foreach (ProviderFieldSpecs::for('tavus') as $field) {
        if ($field->key === 'ttsModelName') {
            return $field->toArray() + ['pal_path' => $field->palPath];
        }
    }

    throw new RuntimeException('ttsModelName spec missing');
}

test('the spec is a select at the documented PAL path, dependent on the engine', function (): void {
    $spec = ttsModelSpec();

    expect($spec['type'])->toBe('select')
        ->and($spec['pal_path'])->toBe('layers/tts/tts_model_name')
        ->and($spec['label_key'])->toBe('avatar_templates.field.ttsModelName')
        ->and($spec['hint_key'])->toBe('avatar_templates.hint.ttsModelName')
        ->and($spec['options_depend_on'])->toBe('ttsEngine')
        ->and($spec['options_by_value'])->toBe([
            'cartesia' => ['sonic-3', 'sonic-3.5', 'sonic-3.6'],
            'elevenlabs' => ['eleven_multilingual_v2', 'eleven_turbo_v2_5', 'eleven_flash_v2_5'],
        ])
        ->and($spec['options'])->toBe(['sonic-3', 'sonic-3.5', 'sonic-3.6', 'eleven_multilingual_v2', 'eleven_turbo_v2_5', 'eleven_flash_v2_5']);
});

// ─── layers ──────────────────────────────────────────────────────────────

test('cartesia without a model defaults to sonic-3', function (): void {
    expect(TemplatePayload::tavusPalLayers(['ttsEngine' => 'cartesia'])['tts'])
        ->toBe(['tts_engine' => 'cartesia', 'tts_model_name' => 'sonic-3']);
});

test('elevenlabs without a model defaults to eleven_multilingual_v2', function (): void {
    expect(TemplatePayload::tavusPalLayers(['ttsEngine' => 'elevenlabs', 'ttsExternalVoiceId' => 'v'])['tts'])
        ->toBe(['tts_engine' => 'elevenlabs', 'external_voice_id' => 'v', 'tts_model_name' => 'eleven_multilingual_v2']);
});

test('an explicit model wins over the default', function (): void {
    expect(TemplatePayload::tavusPalLayers(['ttsEngine' => 'cartesia', 'ttsModelName' => 'sonic-3.6'])['tts']['tts_model_name'])
        ->toBe('sonic-3.6');
});

test('engines that take no model, and no engine at all, send none', function (?string $engine): void {
    $config = $engine === null ? ['llmTemperature' => 0.5] : ['ttsEngine' => $engine];

    expect(TemplatePayload::tavusPalLayers($config)['tts'] ?? [])->not->toHaveKey('tts_model_name');
})->with(['azure', 'tavus-auto', null]);

// ─── validation ──────────────────────────────────────────────────────────

test('a model matching its engine is valid', function (string $engine, string $model): void {
    expect(ConfigValidator::validate('tavus', ['faceId' => 'f', 'palId' => 'p', 'ttsEngine' => $engine, 'ttsModelName' => $model]))->toBe([]);
})->with([
    ['cartesia', 'sonic-3'],
    ['cartesia', 'sonic-3.6'],
    ['elevenlabs', 'eleven_flash_v2_5'],
]);

test('a model that belongs to another engine, or to none, is refused with one code', function (?string $engine, string $model): void {
    $config = ['faceId' => 'f', 'palId' => 'p', 'ttsModelName' => $model] + ($engine === null ? [] : ['ttsEngine' => $engine]);

    expect(ConfigValidator::validate('tavus', $config))->toBe([['key' => 'ttsModelName', 'code' => 'tts_model_engine_mismatch']]);
})->with([
    'cartesia + eleven' => ['cartesia', 'eleven_flash_v2_5'],
    'elevenlabs + sonic' => ['elevenlabs', 'sonic-3'],
    'azure' => ['azure', 'sonic-3'],
    'tavus-auto' => ['tavus-auto', 'eleven_multilingual_v2'],
    'no engine' => [null, 'sonic-3'],
]);

test('a model outside the documented set is an enum error, not a mismatch', function (): void {
    expect(ConfigValidator::validate('tavus', ['faceId' => 'f', 'palId' => 'p', 'ttsEngine' => 'cartesia', 'ttsModelName' => 'sonic-2']))
        ->toBe([['key' => 'ttsModelName', 'code' => 'enum']]);
});

test('an unset model is always valid', function (): void {
    expect(ConfigValidator::validate('tavus', ['faceId' => 'f', 'palId' => 'p', 'ttsEngine' => 'cartesia', 'ttsModelName' => null]))->toBe([]);
});

test('the save endpoint refuses a mismatch with a 422 keyed on the field', function (): void {
    $org = Organization::factory()->create();

    $this->withToken(authTokenForRole($org, 'platform'))
        ->postJson('/api/avatar-templates', [
            'name' => 'Model mismatch', 'provider' => 'tavus',
            'config' => ['faceId' => 'f', 'palId' => 'p', 'ttsEngine' => 'cartesia', 'ttsModelName' => 'eleven_turbo_v2_5'],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['config.ttsModelName' => ['tts_model_engine_mismatch']]);
});
