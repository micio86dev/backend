<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

/**
 * Validates the REFERENCES inside a template's config — the avatar and voice
 * ids and the way they combine — against the provider's own inventory.
 *
 * `ConfigValidator` checks shape (types, ranges, required keys) and never
 * leaves the process. This checks meaning: does the avatar exist and can it
 * start a session, does the voice exist, is a third-party TTS voice paired
 * with its engine. A template that fails these saves happily and then fails
 * for a CANDIDATE at interview start, which is the failure this exists to move
 * to the operator who caused it.
 *
 * Shared by template save (`AvatarTemplateController`) and the interview
 * pre-flight, so both refuse the same things for the same reasons.
 *
 * FAILS OPEN on the provider's availability. When the catalogue cannot be read
 * (key missing, outage, rate limit) or is empty, existence is not verified and
 * no error is reported: a provider incident must not stop an operator from
 * saving a template, and the pre-flight will look again at interview start.
 * A reference is refused only when the catalogue was READ SUCCESSFULLY and the
 * id is not in it — and only after one uncached re-read, so an item created
 * within the catalogue's 24h cache window is not refused.
 *
 * Codes (per config key):
 *  - `avatar_not_found`, `voice_not_found`, `pal_not_found` — id absent from the provider.
 *  - `tts_engine_required`                   — external voice id with no third-party engine.
 *  - `tts_voice_required`                    — third-party engine with no voice id.
 *  - `tts_voice_not_found`                   — voice id absent from that vendor's catalogue.
 */
final class TemplateReferenceValidator
{
    /** Engines whose voice ids come from a catalogue we can query. */
    private const CATALOGUED_ENGINES = ['cartesia', 'elevenlabs'];

    /** Engines that need an external voice id, catalogued or not. */
    private const EXTERNAL_ENGINES = ['cartesia', 'elevenlabs', 'azure'];

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{key: string, code: string}>
     */
    public static function validate(string $provider, array $config): array
    {
        // The shared kill switch (`interview.preflight.verify_references`): the
        // only check here that depends on a provider's inventory being right.
        if (! config('interview.preflight.verify_references', true)) {
            return [];
        }

        return match ($provider) {
            'heygen' => array_values(array_filter([
                self::exists('heygen', 'avatar', $config['avatarId'] ?? null, 'avatarId', 'avatar_not_found'),
                self::exists('heygen', 'voice', $config['voiceId'] ?? null, 'voiceId', 'voice_not_found'),
            ])),
            'tavus' => self::tavus($config),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{key: string, code: string}>
     */
    private static function tavus(array $config): array
    {
        $errors = [];

        $face = self::exists('tavus', 'replica', $config['faceId'] ?? null, 'faceId', 'avatar_not_found');
        if ($face !== null) {
            $errors[] = $face;
        }

        // The persona. A voice or face id stored here is refused by Tavus at
        // conversation creation, which used to reach the candidate as a 500.
        $pal = self::exists('tavus', 'pal', $config['palId'] ?? null, 'palId', 'pal_not_found');
        if ($pal !== null) {
            $errors[] = $pal;
        }

        $engine = is_string($config['ttsEngine'] ?? null) ? $config['ttsEngine'] : null;
        $voiceId = is_string($config['ttsExternalVoiceId'] ?? null) ? trim($config['ttsExternalVoiceId']) : '';

        if (in_array($engine, self::EXTERNAL_ENGINES, true)) {
            if ($voiceId === '') {
                $errors[] = ['key' => 'ttsExternalVoiceId', 'code' => 'tts_voice_required'];
            } elseif (in_array($engine, self::CATALOGUED_ENGINES, true)) {
                $voice = self::exists($engine, 'voice', $voiceId, 'ttsExternalVoiceId', 'tts_voice_not_found');
                if ($voice !== null) {
                    $errors[] = $voice;
                }
            }
        } elseif ($voiceId !== '') {
            // No engine, or Tavus's own: the external voice id would be sent
            // and silently ignored, the dead-knob defect this codebase refuses.
            $errors[] = ['key' => 'ttsEngine', 'code' => 'tts_engine_required'];
        }

        return $errors;
    }

    /**
     * @return array{key: string, code: string}|null
     */
    private static function exists(string $provider, string $resource, mixed $id, string $key, string $code): ?array
    {
        if (! is_string($id) || trim($id) === '') {
            // Absent is `ConfigValidator`'s `required`, not a reference error.
            return null;
        }

        $catalogue = AvatarProviderCatalogue::fetch($provider, $resource);

        if ($catalogue['status'] !== 'ok') {
            return null;
        }

        if (self::contains($catalogue['items'], $id)) {
            return null;
        }

        $fresh = AvatarProviderCatalogue::fetch($provider, $resource, fresh: true);

        if ($fresh['status'] !== 'ok' || self::contains($fresh['items'], $id)) {
            return null;
        }

        return ['key' => $key, 'code' => $code];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private static function contains(array $items, string $id): bool
    {
        foreach ($items as $item) {
            if (($item['id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }
}
