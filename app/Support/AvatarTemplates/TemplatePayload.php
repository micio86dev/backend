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
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function heygen(array $config): array
    {
        $payload = [];

        self::put($payload, 'avatar_id', $config['avatarId'] ?? null);

        // Nesting matters. HeyGen accepts flat keys and silently ignores them —
        // the worst failure available, because the operator sees a saved
        // setting and hears no difference.
        self::put($payload, 'avatar_persona.voice_id', $config['voiceId'] ?? null);
        self::put($payload, 'avatar_persona.language', $config['language'] ?? null);

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
        $language = $config['language'] ?? null;

        if (is_string($language)) {
            self::put($payload, 'language', match ($language) {
                'it' => 'italian',
                'en' => 'english',
                default => $language,
            });
        }

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
