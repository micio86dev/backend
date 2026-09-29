<?php

declare(strict_types=1);

namespace App\Services\AvatarPreview;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Produces a short audio sample of one vendor voice (avatar-voice-preview).
 *
 * The sentence is ALWAYS `config('avatar_preview.phrases')`; callers pass a
 * language, never text. Generated audio is cached on disk per
 * (vendor, voice, model, phrase version, language), so a repeated request costs
 * nothing at the provider.
 *
 * VENDOR RESOLUTION: `cartesia` and `elevenlabs` synthesise the configured
 * phrase; `heygen` (LiveAvatar) returns its own GENERIC, non-Italian sample for
 * the voice; `tavus` is previewable only when its voice is routed through an
 * external Cartesia/ElevenLabs id (`tts_engine`) — Tavus stock voices have no
 * preview at all. The preview therefore proves the VENDOR voice, not how Tavus
 * or LiveAvatar render it.
 *
 * Provider keys come from the same config the voice catalogue uses and never
 * leave this class; no provider response body is ever logged or surfaced.
 */
final class VoicePreviewService
{
    private const CARTESIA_URL = 'https://api.cartesia.ai/tts/bytes';

    private const ELEVENLABS_URL = 'https://api.elevenlabs.io/v1/text-to-speech';

    private const HEYGEN_URL = 'https://api.liveavatar.com/v1/voices';

    /** Stands in for model and language where the vendor sample is generic. */
    private const GENERIC = 'generic';

    /**
     * @return array{audio: string, content_type: string}
     *
     * @throws VoicePreviewException
     */
    public function preview(string $provider, string $voiceId, ?string $ttsEngine, string $language): array
    {
        $vendor = $this->resolveVendor($provider, $ttsEngine);
        $model = $vendor === 'heygen' ? self::GENERIC : (string) config("avatar_preview.models.{$vendor}");
        $lang = $vendor === 'heygen' ? self::GENERIC : $language;

        $disk = Storage::disk(config('avatar_preview.disk'));
        $path = 'voice-previews/'.hash('sha256', implode('|', [
            $vendor, $voiceId, $model, (string) config('avatar_preview.phrase_version'), $lang,
        ])).'.audio';

        $audio = $disk->exists($path) ? $disk->get($path) : null;

        if (! is_string($audio) || $audio === '') {
            $audio = $this->generate($vendor, $voiceId, $language);
            $disk->put($path, $audio);
        }

        return ['audio' => $audio, 'content_type' => $this->contentType($audio)];
    }

    /**
     * @throws VoicePreviewException
     */
    private function resolveVendor(string $provider, ?string $ttsEngine): string
    {
        $vendor = $provider === 'tavus' ? $ttsEngine : $provider;

        if (! in_array($vendor, ['cartesia', 'elevenlabs', 'heygen'], true)) {
            throw new VoicePreviewException(VoicePreviewException::UNAVAILABLE);
        }

        return $vendor;
    }

    /**
     * @throws VoicePreviewException
     */
    private function generate(string $vendor, string $voiceId, string $language): string
    {
        $phrase = (string) config("avatar_preview.phrases.{$language}");
        $timeout = (int) config('avatar_preview.timeout_seconds', 20);

        try {
            $response = match ($vendor) {
                'cartesia' => $this->request($this->key('services.cartesia.api_key'), 'X-API-Key', $timeout)
                    ->withHeaders(['Cartesia-Version' => (string) config('avatar_preview.cartesia_version')])
                    ->post(self::CARTESIA_URL, [
                        'model_id' => config('avatar_preview.models.cartesia'),
                        'transcript' => $phrase,
                        'voice' => ['mode' => 'id', 'id' => $voiceId],
                        'language' => $language,
                        'output_format' => config('avatar_preview.output_formats.cartesia'),
                    ]),
                'elevenlabs' => $this->request($this->key('services.elevenlabs.api_key'), 'xi-api-key', $timeout)
                    ->withHeaders(['Accept' => 'audio/mpeg'])
                    ->post(self::ELEVENLABS_URL.'/'.$voiceId.'?'.http_build_query([
                        'output_format' => config('avatar_preview.output_formats.elevenlabs'),
                    ]), [
                        'text' => $phrase,
                        'model_id' => config('avatar_preview.models.elevenlabs'),
                        'language_code' => $language,
                    ]),
                default => $this->request($this->key('interview.heygen.api_key'), 'X-API-KEY', $timeout)
                    ->get(self::HEYGEN_URL.'/'.$voiceId.'/preview'),
            };
        } catch (ConnectionException) {
            Log::warning('VoicePreviewService: provider unreachable', ['vendor' => $vendor]);

            throw new VoicePreviewException(VoicePreviewException::PROVIDER_ERROR);
        }

        if (! $response->successful()) {
            Log::warning('VoicePreviewService: provider refused', ['vendor' => $vendor, 'http_status' => $response->status()]);

            throw new VoicePreviewException($response->status() === 404
                ? VoicePreviewException::VOICE_NOT_FOUND
                : VoicePreviewException::PROVIDER_ERROR);
        }

        $audio = $vendor === 'heygen' ? $this->decodeHeygen($response) : $response->body();

        if ($audio === '' || strlen($audio) > (int) config('avatar_preview.max_bytes')) {
            Log::warning('VoicePreviewService: unusable audio', ['vendor' => $vendor, 'bytes' => strlen($audio)]);

            throw new VoicePreviewException(VoicePreviewException::PROVIDER_ERROR);
        }

        return $audio;
    }

    /**
     * @throws VoicePreviewException
     */
    private function key(string $configKey): string
    {
        $key = config($configKey);

        if (! is_string($key) || $key === '') {
            throw new VoicePreviewException(VoicePreviewException::NOT_CONFIGURED);
        }

        return $key;
    }

    private function request(string $key, string $header, int $timeout): PendingRequest
    {
        return Http::withHeaders([$header => $key])->timeout($timeout);
    }

    /**
     * LiveAvatar answers `{code, data: {audio_base64}}`; a `data:` URI prefix
     * is tolerated.
     *
     * @throws VoicePreviewException
     */
    private function decodeHeygen(Response $response): string
    {
        $encoded = $response->json('data.audio_base64');

        if (! is_string($encoded)) {
            throw new VoicePreviewException(VoicePreviewException::PROVIDER_ERROR);
        }

        $decoded = base64_decode(preg_replace('#^data:[^,]*,#', '', $encoded) ?? '', true);

        if ($decoded === false) {
            throw new VoicePreviewException(VoicePreviewException::PROVIDER_ERROR);
        }

        return $decoded;
    }

    /**
     * Sniffed from the bytes rather than assumed: LiveAvatar does not document
     * the container of its sample.
     */
    private function contentType(string $audio): string
    {
        return match (true) {
            str_starts_with($audio, 'RIFF') => 'audio/wav',
            str_starts_with($audio, 'OggS') => 'audio/ogg',
            default => 'audio/mpeg',
        };
    }
}
