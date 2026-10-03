<?php

declare(strict_types=1);

namespace App\Services\AvatarPreview;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Serves Cartesia's own free CATALOGUE clip of a voice (cartesia-catalogue-sample-proxy).
 *
 * Cartesia's `preview_file_url` answers 401 without the platform key, so the browser can never
 * play it directly and must never be handed the key. The SERVER therefore resolves the voice,
 * downloads the clip and returns the bytes.
 *
 * SSRF: the caller supplies a voice id and nothing else. The download URL is read from Cartesia's
 * own answer for that voice and is used only when it is `https://files.cartesia.ai/...` exactly
 * (no other host, port or userinfo); redirects are never followed, so the key cannot be steered
 * to another host. The key is sent to `api.cartesia.ai` and `files.cartesia.ai` only.
 *
 * The clip is capped in size, must be `audio/*`, and is cached on the default disk (a marker in
 * the cache store bounds its age). Failures are the fixed `voice_preview_*` codes: no key, no
 * upstream URL and no upstream body ever reaches a response, a log line or the cache.
 */
final class CatalogueSampleService
{
    private const VOICE_URL = 'https://api.cartesia.ai/voices';

    private const FILE_HOST = 'files.cartesia.ai';

    private const CACHE_PREFIX = 'catalogue-sample:v1:';

    /**
     * @return array{audio: string, content_type: string}
     *
     * @throws VoicePreviewException
     */
    public function sample(string $voiceId): array
    {
        $disk = Storage::disk();
        $name = hash('sha256', 'cartesia|'.$voiceId);
        $path = 'catalogue-samples/'.$name.'.audio';
        $marker = self::CACHE_PREFIX.$name;

        if (Cache::has($marker) && $disk->exists($path)) {
            $audio = $disk->get($path);

            if (is_string($audio) && $audio !== '') {
                return ['audio' => $audio, 'content_type' => $this->contentType($audio)];
            }
        }

        $key = config('services.cartesia.api_key');

        if (! is_string($key) || $key === '') {
            throw new VoicePreviewException(VoicePreviewException::NOT_CONFIGURED);
        }

        $audio = $this->download($key, $this->resolveFileUrl($key, $voiceId));

        $disk->put($path, $audio);
        Cache::put($marker, true, (int) config('avatar_preview.catalogue_sample.cache_seconds'));

        return ['audio' => $audio, 'content_type' => $this->contentType($audio)];
    }

    /**
     * @throws VoicePreviewException
     */
    private function resolveFileUrl(string $key, string $voiceId): string
    {
        try {
            $response = Http::withHeaders($this->headers($key, 'X-API-Key'))
                ->timeout($this->timeout())
                ->get(self::VOICE_URL.'/'.$voiceId.'?'.'expand[]=preview_file_url');
        } catch (ConnectionException) {
            Log::warning('CatalogueSampleService: voice lookup unreachable');

            throw new VoicePreviewException(VoicePreviewException::PROVIDER_ERROR);
        }

        if (! $response->successful()) {
            Log::warning('CatalogueSampleService: voice lookup refused', ['http_status' => $response->status()]);

            throw new VoicePreviewException($response->status() === 404
                ? VoicePreviewException::VOICE_NOT_FOUND
                : VoicePreviewException::PROVIDER_ERROR);
        }

        $url = $response->json('preview_file_url');

        if (! is_string($url) || $url === '') {
            throw new VoicePreviewException(VoicePreviewException::UNAVAILABLE);
        }

        if (! $this->isCartesiaFileUrl($url)) {
            // Never log the URL itself: it is data from a third party.
            Log::warning('CatalogueSampleService: preview url refused (host not allowed)');

            throw new VoicePreviewException(VoicePreviewException::UNAVAILABLE);
        }

        return $url;
    }

    private function isCartesiaFileUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'])
            && strtolower($parts['host']) === self::FILE_HOST
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port']);
    }

    /**
     * @throws VoicePreviewException
     */
    private function download(string $key, string $url): string
    {
        $max = (int) config('avatar_preview.catalogue_sample.max_bytes');

        try {
            $response = Http::withHeaders($this->headers($key, 'Authorization', 'Bearer '.$key))
                ->timeout($this->timeout())
                ->withOptions(['allow_redirects' => false, 'stream' => true])
                ->get($url);

            $audio = $response->successful() ? $this->boundedBody($response, $max) : null;
        } catch (ConnectionException|RequestException|GuzzleException|\RuntimeException) {
            // Also covers the manual stream read in boundedBody(): it runs after the headers arrived, so a
            // transfer that dies mid-body surfaces as a Guzzle/Runtime exception, not an Illuminate one.
            // Programmer errors (TypeError, LogicException...) are deliberately NOT caught.
            Log::warning('CatalogueSampleService: file download unreachable');

            throw new VoicePreviewException(VoicePreviewException::PROVIDER_ERROR);
        }

        $isAudio = str_starts_with(strtolower((string) $response->header('Content-Type')), 'audio/');

        if (! $response->successful() || ! $isAudio || $audio === null || $audio === '') {
            Log::warning('CatalogueSampleService: unusable file download', [
                'http_status' => $response->status(),
                'is_audio' => $isAudio,
            ]);

            throw new VoicePreviewException(VoicePreviewException::PROVIDER_ERROR);
        }

        return $audio;
    }

    /** The body, or null when it is larger than `$max` (never reads far beyond the cap). */
    private function boundedBody(Response $response, int $max): ?string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        while (! $stream->eof()) {
            $body .= $stream->read(65536);

            if (strlen($body) > $max) {
                return null;
            }
        }

        return $body;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $key, string $header, ?string $value = null): array
    {
        return [
            $header => $value ?? $key,
            'Cartesia-Version' => (string) config('avatar_preview.cartesia_version'),
        ];
    }

    private function timeout(): int
    {
        return (int) config('avatar_preview.timeout_seconds', 20);
    }

    private function contentType(string $audio): string
    {
        return match (true) {
            str_starts_with($audio, 'RIFF') => 'audio/wav',
            str_starts_with($audio, 'OggS') => 'audio/ogg',
            default => 'audio/mpeg',
        };
    }
}
