<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

/**
 * Turns a template config into the body fragment each provider expects (C14).
 *
 * Pure functions, deliberately. "Does the avatar id an operator typed end up in
 * the request body" should be one assertion, not an integration test with a
 * fake HTTP server.
 *
 * Two rules run through everything here.
 *
 * UNSET MEANS ABSENT, never null. A null tells the provider "use null"; an
 * absent key tells it "use your default". Those are different requests, and
 * only one of them is what an operator who left a field blank meant.
 *
 * AN EMPTY CONFIG PRODUCES AN EMPTY PAYLOAD. That is what every organization
 * gets on the day this ships, so the provider request has to be byte-identical
 * to the one sent before the feature existed.
 */
final class TemplatePayload
{
    /**
     * HeyGen's session body fragment.
     *
     * `$boundVoiceId` is the LiveAvatar voice id the ledger holds for the
     * template's external voice (`HeygenVoiceRegistrar::boundVoiceId`); it is
     * passed IN, never stored on the template, so the config has one source of
     * truth (the vendor voice id) and the bound id cannot drift from it. It is
     * ignored unless `ttsEngine` names an external engine.
     *
     * For an external engine `avatar_persona.voice_id` is that bound id and
     * `voice_settings` is LiveAvatar's provider-DISCRIMINATED object (`provider`
     * cartesia | elevenLabs, plus the knobs that engine has and a pinned
     * model). For everything else the body is unchanged to the byte.
     *
     * Never carries `llm_configuration_id`: the conversation-LLM binding is a
     * top-level `/sessions/token` field owned by `ManagedLlmPayload`, and nested
     * under `avatar_persona` (where this fragment lives) LiveAvatar silently
     * ignores it (proven live, 2026-10-08).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function heygen(array $config, ?string $boundVoiceId = null): array
    {
        $payload = [];

        self::put($payload, 'avatar_id', $config['avatarId'] ?? null);

        $engine = $config['ttsEngine'] ?? null;

        if (is_string($engine) && in_array($engine, ProviderFieldSpecs::HEYGEN_EXTERNAL_ENGINES, true)) {
            return self::heygenExternalVoice($payload, $config, $engine, $boundVoiceId);
        }

        // Nesting matters. HeyGen accepts flat keys and silently ignores them —
        // the worst failure available, because the operator sees a saved
        // setting and hears no difference.
        self::put($payload, 'avatar_persona.voice_id', $config['voiceId'] ?? null);

        // NO language. The avatar's spoken language follows the PROJECT
        // (avatar-language-follows-project, D1). `avatar_templates` is scoped by
        // organization with no project_id, so one active template would have to
        // serve every project in that organization — while `project.language` is
        // per project. An org running one Italian and one English project cannot
        // express that through a template, and CLAUDE.md binds UI, TTS and
        // evaluation to the project language.
        //
        // Cutting it HERE is what makes the guarantee hold. Removing the entry
        // from HeygenProvider's TOKEN_FIELD_ALLOWLIST is defence in depth only:
        // that allowlist is union'd with `interview.heygen.extra_token_fields`,
        // so an env change with no deploy could re-open the field.

        self::put($payload, 'interactivity_type', $config['interactivityType'] ?? null);

        // Clamped as well as validated. The field spec caps this at save time,
        // but a config written before the cap existed — or straight to the
        // database — would otherwise be rejected by HeyGen at session start, in
        // front of a candidate rather than in front of the operator who set it.
        $duration = $config['maxSessionDurationSec'] ?? null;

        if (is_int($duration)) {
            self::put($payload, 'max_session_duration', min($duration, ProviderFieldSpecs::HEYGEN_MAX_SECONDS));
        }

        self::put($payload, 'video_settings.quality', $config['videoQuality'] ?? null);
        self::put($payload, 'video_settings.encoding', $config['videoEncoding'] ?? null);

        self::put($payload, 'voice_settings.speed', $config['voiceSpeed'] ?? null);
        self::put($payload, 'voice_settings.stability', $config['voiceStability'] ?? null);
        self::put($payload, 'voice_settings.similarity_boost', $config['voiceSimilarityBoost'] ?? null);
        self::put($payload, 'voice_settings.style', $config['voiceStyle'] ?? null);
        self::put($payload, 'voice_settings.use_speaker_boost', $config['voiceUseSpeakerBoost'] ?? null);

        return $payload;
    }

    /**
     * The rest of a HeyGen body for an external voice. No native `voiceId`
     * (it is superseded and refused beside an engine), no `language` (the
     * project's, see `heygen()`), and none of the flat knobs: LiveAvatar's
     * `voice_settings` is a union discriminated by `provider`, and flat keys
     * without it are exactly what it ignores.
     *
     * @wire-source https://docs.liveavatar.com/openapi.json
     * `AvatarPersonaSchema.voice_settings` (`CartesiaVoiceSettings`:
     * provider/speed/model; `ElevenLabsVoiceSettings`: provider/speed/stability/
     * similarity_boost/style/use_speaker_boost/model). Documented, NOT yet
     * exercised on a live session.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function heygenExternalVoice(array $payload, array $config, string $engine, ?string $boundVoiceId): array
    {
        self::put($payload, 'avatar_persona.voice_id', $boundVoiceId);

        self::put($payload, 'interactivity_type', $config['interactivityType'] ?? null);

        $duration = $config['maxSessionDurationSec'] ?? null;

        if (is_int($duration)) {
            self::put($payload, 'max_session_duration', min($duration, ProviderFieldSpecs::HEYGEN_MAX_SECONDS));
        }

        self::put($payload, 'video_settings.quality', $config['videoQuality'] ?? null);
        self::put($payload, 'video_settings.encoding', $config['videoEncoding'] ?? null);

        self::put($payload, 'voice_settings.provider', $engine === 'elevenlabs' ? 'elevenLabs' : 'cartesia');
        self::put($payload, 'voice_settings.speed', $config['voiceSpeed'] ?? null);

        if ($engine === 'elevenlabs') {
            self::put($payload, 'voice_settings.stability', $config['voiceStability'] ?? null);
            self::put($payload, 'voice_settings.similarity_boost', $config['voiceSimilarityBoost'] ?? null);
            self::put($payload, 'voice_settings.style', $config['voiceStyle'] ?? null);
            self::put($payload, 'voice_settings.use_speaker_boost', $config['voiceUseSpeakerBoost'] ?? null);
        }

        self::put($payload, 'voice_settings.model', $config['ttsModelName'] ?? ProviderFieldSpecs::HEYGEN_TTS_DEFAULT_MODEL[$engine]);

        return $payload;
    }

    /**
     * Tavus's conversation body fragment.
     *
     * Persona-level knobs are deliberately absent — they go to the PAL. Sent on
     * a conversation they are silently ignored, leaving an operator watching a
     * setting do nothing.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function tavus(array $config): array
    {
        $payload = [];

        self::put($payload, 'replica_id', $config['faceId'] ?? null);
        self::put($payload, 'persona_id', $config['palId'] ?? null);

        // The one knob both providers share, and even here the value spaces
        // differ: Tavus wants the language spelled out. Sending 'it' is
        // accepted and ignored, so the avatar answers in English to an Italian
        // candidate — a failure nobody would attribute to a language mapping.
        // NO language for Tavus either — same reason as HeyGen above. The
        // spelled-out vocabulary that used to live here moved to
        // App\Support\Provider\TavusLanguage, which the provider's platform
        // default now uses with the project's locale.

        self::put($payload, 'audio_only', $config['audioOnly'] ?? null);
        self::put($payload, 'properties.max_call_duration', $config['maxCallDurationSec'] ?? null);
        self::put($payload, 'properties.participant_absent_timeout', $config['participantAbsentTimeoutSec'] ?? null);
        self::put($payload, 'properties.enable_recording', $config['enableRecording'] ?? null);
        self::put($payload, 'properties.enable_closed_captions', $config['enableClosedCaptions'] ?? null);

        return $payload;
    }

    /**
     * The Tavus PAL `layers` object, built from the field spec's own palPath.
     *
     * Driven by the spec rather than a second hand-written map, so adding a
     * persona knob is a one-line change in one file. A parallel map here would
     * be the third place the same information lives.
     *
     * Returns [] when nothing is set: sent as an empty object, a PATCH would
     * wipe the persona's existing layers. Nothing to say must mean saying
     * nothing.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function tavusPalLayers(array $config): array
    {
        $layers = [];

        foreach (ProviderFieldSpecs::for('tavus') as $field) {
            if ($field->palPath === null) {
                continue;
            }

            $value = $config[$field->key] ?? null;

            if ($value === null) {
                continue;
            }

            // palPath is rooted at `layers/…`; the caller owns the wrapper key.
            $path = str_replace('/', '.', $field->palPath);
            $path = str_starts_with($path, 'layers.') ? substr($path, 7) : $path;

            self::put($layers, $path, $value);
        }

        // A model is ALWAYS sent for the engines that take one. The PATCH
        // replaces the whole `/layers` node, so omitting it silently reverts the
        // persona to Tavus's own default model, which may not speak Italian.
        $engine = $layers['tts']['tts_engine'] ?? null;

        if (is_string($engine) && ! isset($layers['tts']['tts_model_name'])
            && isset(ProviderFieldSpecs::TAVUS_TTS_DEFAULT_MODEL[$engine])) {
            $layers['tts']['tts_model_name'] = ProviderFieldSpecs::TAVUS_TTS_DEFAULT_MODEL[$engine];
        }

        return $layers;
    }

    /**
     * Writes a dotted path, skipping nulls entirely.
     *
     * data_set() would happily create the whole nested structure for a null,
     * which is exactly the "sent as null instead of omitted" bug this avoids.
     *
     * @param  array<string, mixed>  $target
     */
    private static function put(array &$target, string $path, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $keys = explode('.', $path);
        $cursor = &$target;

        foreach ($keys as $index => $key) {
            if ($index === count($keys) - 1) {
                $cursor[$key] = $value;

                break;
            }

            if (! isset($cursor[$key]) || ! is_array($cursor[$key])) {
                $cursor[$key] = [];
            }

            $cursor = &$cursor[$key];
        }
    }
}
