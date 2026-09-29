<?php

declare(strict_types=1);

namespace App\Services\AvatarPreview;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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

    private const TAVUS_PALS_URL = 'https://tavusapi.com/v2/pals';

    /** Versioned: bump when the cached tuple's shape changes. */
    private const PAL_CACHE_PREFIX = 'voice-preview:pal:v1:';

    private const PAL_CACHE_SECONDS = 300;

    private const PAL_TIMEOUT_SECONDS = 10;

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

        return $this->serve($vendor, $voiceId, $this->defaultModel($vendor), $language);
    }

    /**
     * Previews the voice of a Tavus persona (PAL): the server reads the persona,
     * keeps ONLY its `layers.tts` routing tuple, then synthesises like the
     * voice path. Nothing else of the persona is read, cached, logged or returned.
     *
     * @return array{audio: string, content_type: string}
     *
     * @throws VoicePreviewException
     */
    public function previewPersona(string $palId, string $language): array
    {
        $tts = $this->personaTts($palId);
        $engine = $tts['engine'];

        if ($tts['external_voice_id'] !== null && in_array($engine, ['cartesia', 'elevenlabs'], true)) {
            return $this->serve($engine, $tts['external_voice_id'], $tts['model'] ?? $this->defaultModel($engine), $language);
        }

        $reason = match (true) {
            $engine === 'azure' => 'pal_azure_engine',
            // An external engine with no voice, or a layer that names no engine and no voice at all.
            in_array($engine, ['cartesia', 'elevenlabs'], true) && ! $tts['has_native_voice'] => 'pal_no_voice_configured',
            ! $tts['has_engine_field'] && ! $tts['has_native_voice'] => 'pal_no_voice_configured',
            default => 'pal_uses_tavus_voice',
        };

        throw new VoicePreviewException(VoicePreviewException::UNAVAILABLE, $reason);
    }

    /**
     * @return array{audio: string, content_type: string}
     */
    private function serve(string $vendor, string $voiceId, ?string $model, string $language): array
    {
        $lang = $vendor === 'heygen' ? self::GENERIC : $language;

        $disk = Storage::disk();
        $path = 'voice-previews/'.hash('sha256', implode('|', [
            $vendor, $voiceId, $model ?? self::GENERIC, (string) config('avatar_preview.phrase_version'), $lang,
        ])).'.audio';

        $audio = $disk->exists($path) ? $disk->get($path) : null;

        if (! is_string($audio) || $audio === '') {
            $audio = $this->generate($vendor, $voiceId, $model, $language);
            $disk->put($path, $audio);
        }

        return ['audio' => $audio, 'content_type' => $this->contentType($audio)];
    }

    private function defaultModel(string $vendor): ?string
    {
        $model = config("avatar_preview.models.{$vendor}");

        return is_string($model) ? $model : null;
    }

    /**
     * The persona's TTS routing tuple, cached ~5 minutes per persona. Only this
     * tuple is kept: never the persona body, its prompt or any key.
     *
     * @return array{engine: ?string, external_voice_id: ?string, has_native_voice: bool, has_engine_field: bool, model: ?string}
     *
     * @throws VoicePreviewException
     */
    private function personaTts(string $palId): array
    {
        $cacheKey = self::PAL_CACHE_PREFIX.$palId;
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $key = $this->key('interview.tavus.api_key');

        try {
            $response = $this->request($key, 'x-api-key', self::PAL_TIMEOUT_SECONDS)->get(self::TAVUS_PALS_URL.'/'.$palId);
        } catch (ConnectionException) {
            Log::warning('VoicePreviewService: persona lookup unreachable');

            throw new VoicePreviewException(VoicePreviewException::PROVIDER_ERROR);
        }

        if (! $response->successful()) {
            Log::warning('VoicePreviewService: persona lookup refused', ['http_status' => $response->status()]);

            throw new VoicePreviewException($response->status() === 404
                ? VoicePreviewException::VOICE_NOT_FOUND
                : VoicePreviewException::PROVIDER_ERROR);
        }

        $string = static fn (mixed $value, string $pattern): ?string => is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;

        $tts = $response->json('layers.tts');
        $tts = is_array($tts) ? $tts : [];

        $tuple = [
            'engine' => $string($tts['tts_engine'] ?? null, '/^[a-z0-9-]{1,30}$/'),
            'external_voice_id' => $string($tts['external_voice_id'] ?? null, '/^[A-Za-z0-9_-]{1,80}$/'),
            'has_engine_field' => array_key_exists('tts_engine', $tts),
            'has_native_voice' => is_string($tts['voice_id'] ?? null) && $tts['voice_id'] !== '',
            'model' => $string($tts['tts_model_name'] ?? null, '/^[A-Za-z0-9_.-]{1,60}$/'),
        ];

        Cache::put($cacheKey, $tuple, self::PAL_CACHE_SECONDS);

        return $tuple;
    }

    /**
     * @throws VoicePreviewException
     */
    private function resolveVendor(string $provider, ?string $ttsEngine): string
    {
        $vendor = $provider === 'tavus' ? $ttsEngine : $provider;

        if (! in_array($vendor, ['cartesia', 'elevenlabs', 'heygen'], true)) {
            throw new VoicePreviewException(VoicePreviewException::UNAVAILABLE, $provider === 'tavus' ? 'tavus_stock_voice' : null);
        }

        return $vendor;
    }

    /**
     * @throws VoicePreviewException
     */
    private function generate(string $vendor, string $voiceId, ?string $model, string $language): string
    {
        $phrase = (string) config("avatar_preview.phrases.{$language}");
        $timeout = (int) config('avatar_preview.timeout_seconds', 20);

        try {
            $response = match ($vendor) {
                'cartesia' => $this->request($this->key('services.cartesia.api_key'), 'X-API-Key', $timeout)
                    ->withHeaders(['Cartesia-Version' => (string) config('avatar_preview.cartesia_version')])
                    ->post(self::CARTESIA_URL, [
                        'model_id' => $model,
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
                        'model_id' => $model,
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
