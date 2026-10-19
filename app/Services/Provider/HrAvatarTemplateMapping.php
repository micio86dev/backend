<?php

declare(strict_types=1);

namespace App\Services\Provider;

/**
 * The one table that turns a BEAI template config (Tavus or HeyGen) into the members HR-Avatar's avatar-config
 * create request accepts: `config`, `persona` and `voice`. Reference: HR-Avatar `TavusFieldSpecs` and
 * `LiveAvatarFieldSpecs`. Every BEAI key is mapped (`KEYS`), turned into the voice (`VOICE_KEYS`) or reported as
 * dropped (`DROPPED`); a key in none of them is also reported, never sent, so nothing is lost silently.
 *
 * Deliberately NOT mapped:
 *   - `maxSessionDurationSec` / `maxCallDurationSec`: HR-Avatar sets the duration per conversation
 *     (`max_duration_seconds`), not per config.
 *   - `participantAbsentTimeoutSec`: Tavus `participant_absent_timeout` (never joined). HR-Avatar's
 *     `participant_left_timeout` is a different Tavus property (the participant left), so mapping it would change behaviour.
 *   - `videoEncoding`, `audioOnly`, `enableRecording`, `enableClosedCaptions`: HR-Avatar does not send them.
 *   - The Tavus `azure` engine: HR-Avatar speaks with `provider`, `cartesia` or `elevenlabs` only.
 */
final class HrAvatarTemplateMapping
{
    public const EXTERNAL_ENGINES = ['cartesia', 'elevenlabs'];

    /**
     * BEAI key => [HR-Avatar member, HR-Avatar key, voice engines that accept it (null: any or none)].
     * A setting that needs an external voice is dropped when the voice that ends up bound is not one of those.
     *
     * @var array<string, array<string, array{0: 'config'|'persona', 1: string, 2: list<string>|null}>>
     */
    public const KEYS = [
        'tavus' => [
            'faceId' => ['config', 'replica_id', null],
            'palId' => ['config', 'persona_id', null],
            'ttsModelName' => ['config', 'tts_model_name', self::EXTERNAL_ENGINES],
            'llmTemperature' => ['persona', 'llm_temperature', null],
            'llmSpeculativeInference' => ['persona', 'llm_speculative_inference', null],
            'turnTakingPatience' => ['persona', 'turn_taking_patience', null],
            'interruptibility' => ['persona', 'interruptibility', null],
            'voiceIsolation' => ['persona', 'voice_isolation', null],
            'idleEngagement' => ['persona', 'idle_engagement', null],
        ],
        'heygen' => [
            'avatarId' => ['config', 'avatarId', null],
            'interactivityType' => ['config', 'interactivityType', null],
            'videoQuality' => ['config', 'videoQuality', null],
            'voiceSpeed' => ['config', 'voiceSpeed', self::EXTERNAL_ENGINES],
            'voiceStability' => ['config', 'voiceStability', ['elevenlabs']],
            'voiceSimilarityBoost' => ['config', 'voiceSimilarityBoost', ['elevenlabs']],
            'voiceStyle' => ['config', 'voiceStyle', ['elevenlabs']],
            'voiceUseSpeakerBoost' => ['config', 'voiceUseSpeakerBoost', ['elevenlabs']],
            'ttsModelName' => ['config', 'ttsModelName', self::EXTERNAL_ENGINES],
        ],
    ];

    /**
     * Keys that become the config's `voice` binding: an external engine with its voice id, else (HeyGen) the stock `voiceId`.
     * A stock `voiceId` that an external engine replaces (or leaves unbound) is reported as dropped, like any other key not sent.
     *
     * @var array<string, list<string>>
     */
    public const VOICE_KEYS = [
        'tavus' => ['ttsEngine', 'ttsExternalVoiceId'],
        'heygen' => ['voiceId', 'ttsEngine', 'ttsExternalVoiceId'],
    ];

    /**
     * Keys HR-Avatar has no counterpart for.
     *
     * @var array<string, list<string>>
     */
    public const DROPPED = [
        'tavus' => ['audioOnly', 'maxCallDurationSec', 'participantAbsentTimeoutSec', 'enableRecording', 'enableClosedCaptions'],
        'heygen' => ['maxSessionDurationSec', 'videoEncoding'],
    ];

    /**
     * @param  array<string, mixed>  $templateConfig
     * @return array{config: array<string, mixed>, persona: array<string, mixed>, voice: array{engine: string, voice_id: string}|null, dropped: list<string>, voice_missing: bool}
     */
    public static function map(string $provider, array $templateConfig): array
    {
        $engine = $templateConfig['ttsEngine'] ?? null;
        $externalId = self::text($templateConfig['ttsExternalVoiceId'] ?? null);
        $stockId = $provider === 'heygen' ? self::text($templateConfig['voiceId'] ?? null) : null;

        $voice = null;
        $voiceMissing = false;
        $dropped = [];

        if (in_array($engine, self::EXTERNAL_ENGINES, true)) {
            $voice = $externalId === null ? null : ['engine' => $engine, 'voice_id' => $externalId];
            $voiceMissing = $externalId === null;

            if ($stockId !== null) {
                $dropped[] = 'voiceId';
            }
        } elseif ($stockId !== null) {
            $voice = ['engine' => 'provider', 'voice_id' => $stockId];
        }

        if ($provider === 'tavus' && $engine === 'azure') {
            $dropped = $externalId === null ? ['ttsEngine'] : ['ttsEngine', 'ttsExternalVoiceId'];
        }

        $members = ['config' => [], 'persona' => []];

        foreach ($templateConfig as $key => $value) {
            $key = (string) $key;

            if ($value === null || in_array($key, self::VOICE_KEYS[$provider] ?? [], true)) {
                continue;
            }

            $entry = self::KEYS[$provider][$key] ?? null;

            if ($entry === null || ($entry[2] !== null && ! in_array($voice['engine'] ?? null, $entry[2], true))) {
                $dropped[] = $key;

                continue;
            }

            $members[$entry[0]][$entry[1]] = $value;
        }

        // Sorted: the stored config is jsonb, which does not keep the order the operator typed.
        sort($dropped);

        return [...$members, 'voice' => $voice, 'dropped' => $dropped, 'voice_missing' => $voiceMissing];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
