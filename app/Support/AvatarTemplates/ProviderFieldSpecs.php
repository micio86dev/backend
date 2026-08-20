<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

/**
 * What each provider lets an operator configure (C14).
 *
 * Ported from the avatar-tester field specs, with three deliberate changes.
 *
 * `voiceProvider` is NOT here. avatar-tester collects it and never sends it —
 * its own notes say so. Porting a dead knob gives an operator a control that
 * does nothing, which is worse than offering no control at all: they set it,
 * see no change, and reasonably conclude the whole feature is broken.
 *
 * Labels are i18n KEYS, never literals. The backoffice is mandatorily
 * bilingual, and an English string baked in here would sit untranslatable in an
 * Italian operator's form while nothing failed.
 *
 * Durations are capped at each provider's real plan ceiling rather than at a
 * shared round number: HeyGen refuses above 1200s and Tavus above 3600s, so a
 * common maximum would let an operator save a value one provider rejects at
 * session start — an error that surfaces to a candidate, not to the person who
 * caused it.
 */
final class ProviderFieldSpecs
{
    /** HeyGen's hard session ceiling, in seconds. */
    public const HEYGEN_MAX_SECONDS = 1200;

    /** Tavus's hard call ceiling, in seconds. */
    public const TAVUS_MAX_SECONDS = 3600;

    /** @return list<FieldSpec> */
    public static function for(string $provider): array
    {
        return match ($provider) {
            'heygen' => self::heygen(),
            'tavus' => self::tavus(),
            // Not an exception: this is reached from a request path where the
            // provider string has already been validated, and turning a bad
            // value into a 500 on a surface whose job is to report bad values
            // politely helps nobody.
            default => [],
        };
    }

    /** @return list<FieldSpec> */
    private static function heygen(): array
    {
        $l = fn (string $k): string => "avatar_templates.field.{$k}";
        $h = fn (string $k): string => "avatar_templates.hint.{$k}";

        return [
            // No language control. The avatar's spoken language follows the
            // PROJECT (avatar-language-follows-project, D1/D5): `avatar_templates`
            // is scoped by organization with no project_id, so one template would
            // have to serve every project in it. Leaving the control would let an
            // operator pick a language and hear no difference — the exact failure
            // the comments in TemplatePayload already warn about.
            new FieldSpec('avatarId', FieldType::Text, $l('avatarId'), required: true, hintKey: $h('avatarId')),
            new FieldSpec('voiceId', FieldType::Text, $l('voiceId'), required: true, hintKey: $h('voiceId')),
            new FieldSpec('interactivityType', FieldType::Select, $l('interactivityType'), options: ['CONVERSATIONAL', 'PUSH_TO_TALK'], hintKey: $h('interactivityType')),
            new FieldSpec('maxSessionDurationSec', FieldType::Number, $l('maxSessionDurationSec'), min: 30, max: self::HEYGEN_MAX_SECONDS, hintKey: $h('maxSessionDurationSec')),
            new FieldSpec('videoQuality', FieldType::Select, $l('videoQuality'), options: ['very_high', 'high', 'medium', 'low'], hintKey: $h('videoQuality')),
            new FieldSpec('videoEncoding', FieldType::Select, $l('videoEncoding'), options: ['H264', 'VP8'], hintKey: $h('videoEncoding')),
            new FieldSpec('voiceSpeed', FieldType::Number, $l('voiceSpeed'), min: 0.8, max: 1.2, step: 0.01, hintKey: $h('voiceSpeed')),
            new FieldSpec('voiceStability', FieldType::Number, $l('voiceStability'), min: 0, max: 1, step: 0.01, hintKey: $h('voiceStability')),
            new FieldSpec('voiceSimilarityBoost', FieldType::Number, $l('voiceSimilarityBoost'), min: 0, max: 1, step: 0.01, hintKey: $h('voiceSimilarityBoost')),
            new FieldSpec('voiceStyle', FieldType::Number, $l('voiceStyle'), min: 0, max: 1, step: 0.01, hintKey: $h('voiceStyle')),
            new FieldSpec('voiceUseSpeakerBoost', FieldType::Checkbox, $l('voiceUseSpeakerBoost'), hintKey: $h('voiceUseSpeakerBoost')),
        ];
    }

    /** @return list<FieldSpec> */
    private static function tavus(): array
    {
        $l = fn (string $k): string => "avatar_templates.field.{$k}";
        $h = fn (string $k): string => "avatar_templates.hint.{$k}";

        return [
            // No language control. The avatar's spoken language follows the
            // PROJECT (avatar-language-follows-project, D1/D5): `avatar_templates`
            // is scoped by organization with no project_id, so one template would
            // have to serve every project in it. Leaving the control would let an
            // operator pick a language and hear no difference — the exact failure
            // the comments in TemplatePayload already warn about.
            // Conversation-level: sent when the conversation is created.
            new FieldSpec('faceId', FieldType::Text, $l('faceId'), required: true, hintKey: $h('faceId')),
            new FieldSpec('palId', FieldType::Text, $l('palId'), required: true, hintKey: $h('palId')),
            new FieldSpec('audioOnly', FieldType::Checkbox, $l('audioOnly'), hintKey: $h('audioOnly')),
            new FieldSpec('maxCallDurationSec', FieldType::Number, $l('maxCallDurationSec'), min: 30, max: self::TAVUS_MAX_SECONDS, hintKey: $h('maxCallDurationSec')),
            new FieldSpec('participantAbsentTimeoutSec', FieldType::Number, $l('participantAbsentTimeoutSec'), min: 10, max: self::TAVUS_MAX_SECONDS, hintKey: $h('participantAbsentTimeoutSec')),
            new FieldSpec('enableRecording', FieldType::Checkbox, $l('enableRecording'), hintKey: $h('enableRecording')),
            new FieldSpec('enableClosedCaptions', FieldType::Checkbox, $l('enableClosedCaptions'), hintKey: $h('enableClosedCaptions')),

            // Persona-level: applied by creating or patching a Tavus PAL. Sent
            // on a conversation they are silently ignored — no error, no
            // effect, and an operator watching a setting do nothing.
            new FieldSpec('llmModel', FieldType::Select, $l('llmModel'), options: ['tavus-gemma-4', 'tavus-gemma-4-thinking', 'tavus-gpt-5.6-sol', 'tavus-gpt-5.6-terra', 'tavus-gemini-2.5-flash'], palPath: 'layers/llm/model', hintKey: $h('llmModel')),
            new FieldSpec('llmTemperature', FieldType::Number, $l('llmTemperature'), min: 0, max: 2, step: 0.01, palPath: 'layers/llm/extra_body/temperature', hintKey: $h('llmTemperature')),
            new FieldSpec('llmSpeculativeInference', FieldType::Checkbox, $l('llmSpeculativeInference'), palPath: 'layers/llm/speculative_inference', hintKey: $h('llmSpeculativeInference')),
            new FieldSpec('ttsEngine', FieldType::Select, $l('ttsEngine'), options: ['tavus-auto', 'cartesia', 'elevenlabs', 'azure'], palPath: 'layers/tts/tts_engine', hintKey: $h('ttsEngine')),
            new FieldSpec('ttsExternalVoiceId', FieldType::Text, $l('ttsExternalVoiceId'), palPath: 'layers/tts/external_voice_id', hintKey: $h('ttsExternalVoiceId')),
            new FieldSpec('turnTakingPatience', FieldType::Select, $l('turnTakingPatience'), options: ['low', 'medium', 'high'], palPath: 'layers/conversational_flow/turn_taking_patience', hintKey: $h('turnTakingPatience')),
            new FieldSpec('interruptibility', FieldType::Select, $l('interruptibility'), options: ['low', 'medium', 'high'], palPath: 'layers/conversational_flow/pal_interruptibility', hintKey: $h('interruptibility')),
            new FieldSpec('voiceIsolation', FieldType::Select, $l('voiceIsolation'), options: ['near', 'off'], palPath: 'layers/conversational_flow/voice_isolation', hintKey: $h('voiceIsolation')),
            new FieldSpec('idleEngagement', FieldType::Select, $l('idleEngagement'), options: ['off', 'patient', 'eager'], palPath: 'layers/conversational_flow/idle_engagement', hintKey: $h('idleEngagement')),
        ];
    }
}
