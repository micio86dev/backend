<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Normalizes Tavus's and HeyGen/LiveAvatar's own inventory into one shared
 * catalogue shape (avatar-template-catalogue PR1, design D1/D3/D4).
 *
 * `fetch(provider, resource)` is the single entry point — mirrors
 * `ProviderFieldSpecs::for(string $provider)`'s existing "one method,
 * provider/resource as data" shape rather than four named endpoints (D1).
 *
 * Field names below are confirmed against each provider's own published API
 * schema (Tavus `openapi.yaml`, LiveAvatar `openapi.json`) and this
 * project's own live-queried findings recorded in `proposal.md` — never
 * guessed. In particular:
 * - Neither Tavus resource carries a `language` field at all — the
 *   normalizer never infers one; it is always `null` (D4).
 * - Neither provider's VOICE resource carries any preview media field
 *   today; only Tavus's replica (`thumbnail_image_url`/
 *   `thumbnail_video_url`) and HeyGen's avatar (`preview_url`) do.
 *
 * Security: the platform API key
 * (`config('interview.tavus.api_key')` / `config('interview.heygen.api_key')`)
 * is used ONLY to authenticate the outbound request. No response from this
 * class — success or failure — ever carries any part of a provider's raw
 * response body, so the key can never leak through it (same discipline as
 * `TavusProvider`/`HeygenProvider`'s `throwRedacted()`, applied here by
 * never surfacing provider response content at all rather than by
 * redacting it after the fact).
 */
final class AvatarProviderCatalogue
{
    private const TAVUS_BASE_URL = 'https://tavusapi.com/v2';

    private const HEYGEN_BASE_URL = 'https://api.liveavatar.com/v1';

    /**
     * @return array{status: string, items: list<array<string, mixed>>}
     */
    public static function fetch(string $provider, string $resource): array
    {
        $cacheKey = "avatar-catalogue:{$provider}:{$resource}";

        try {
            /** @var array{status: string, items: list<array<string, mixed>>} */
            return Cache::remember(
                $cacheKey,
                now()->addDay(),
                fn (): array => self::fetchLive($provider, $resource),
            );
        } catch (Throwable $e) {
            // D3: a provider failure (non-2xx, timeout, connection error, or
            // an unrecognized provider/resource pair reaching the match
            // below with no arm) is NEVER cached — the next call retries
            // rather than replaying a stale failure for the rest of the 24h
            // TTL. Only the exception CLASS is logged, never its message —
            // some exception types (e.g. a thrown HTTP response) echo
            // response content in their message, and that could carry the
            // provider's own error body.
            Log::warning('AvatarProviderCatalogue: provider fetch failed', [
                'provider' => $provider,
                'resource' => $resource,
                'exception' => $e::class,
            ]);

            return ['status' => 'unavailable', 'items' => []];
        }
    }

    /**
     * @return array{status: string, items: list<array<string, mixed>>}
     */
    private static function fetchLive(string $provider, string $resource): array
    {
        // The `default` arm throws rather than returning an empty list
        // directly: it is caught by fetch()'s try/catch above and degraded
        // to the same 'unavailable' shape a live provider failure gets,
        // rather than being silently indistinguishable from "the provider
        // returned zero items". The HTTP surface
        // (AvatarTemplateController::catalogue()) already rejects an
        // unknown provider/resource with 422 before ever reaching here —
        // this is a defensive floor for any other caller, not the primary
        // validation path.
        $items = match ("{$provider}:{$resource}") {
            'tavus:voice' => self::tavusVoices(),
            'tavus:replica' => self::tavusReplicas(),
            'heygen:voice' => self::heygenVoices(),
            'heygen:avatar' => self::heygenAvatars(),
            default => throw new RuntimeException("Unknown avatar catalogue provider/resource pair: {$provider}/{$resource}"),
        };

        return ['status' => 'ok', 'items' => $items];
    }

    /**
     * @wire-source Tavus `openapi.yaml` — `GET /v2/voices?source=system`
     * returns `{data: [{voice_id, voice_name, status, description?, tags?,
     * created_at}], total_count}`. No `language` field exists on this
     * resource at all.
     *
     * @return list<array<string, mixed>>
     */
    private static function tavusVoices(): array
    {
        $rows = self::tavusGet('/voices', ['source' => 'system']);

        return array_map(
            fn (array $row): array => self::entry(
                id: self::stringOrEmpty($row['voice_id'] ?? null),
                label: self::stringOrEmpty($row['voice_name'] ?? null),
            ),
            $rows,
        );
    }

    /**
     * @wire-source Tavus `openapi.yaml` / this project's `proposal.md`
     * (live-queried) — `GET /v2/replicas?verbose=true` returns `{data:
     * [{replica_id, replica_name, replica_type, tags, thumbnail_image_url,
     * thumbnail_video_url, default_voice_id}], total_count}`.
     *
     * @return list<array<string, mixed>>
     */
    private static function tavusReplicas(): array
    {
        $rows = self::tavusGet('/replicas', ['verbose' => 'true']);

        return array_map(
            fn (array $row): array => self::entry(
                id: self::stringOrEmpty($row['replica_id'] ?? null),
                label: self::stringOrEmpty($row['replica_name'] ?? null),
                previewImageUrl: self::stringOrNull($row['thumbnail_image_url'] ?? null),
                previewAudioUrl: self::stringOrNull($row['thumbnail_video_url'] ?? null),
            ),
            $rows,
        );
    }

    /**
     * @wire-source LiveAvatar `openapi.json` — `GET /v1/voices` returns
     * `{code, data: {count, results: [{id, name, description?, language,
     * gender, created_at, updated_at, tags}]}, message}`. No preview media
     * field exists on this resource today.
     *
     * @return list<array<string, mixed>>
     */
    private static function heygenVoices(): array
    {
        $rows = self::heygenGet('/voices');

        return array_map(
            fn (array $row): array => self::entry(
                id: self::stringOrEmpty($row['id'] ?? null),
                label: self::stringOrEmpty($row['name'] ?? null),
                // D4: read verbatim, never guessed/defaulted — a `null`
                // entry (an absent key, or a genuinely null value) stays
                // `null` here exactly as it does for Tavus.
                language: self::stringOrNull($row['language'] ?? null),
            ),
            $rows,
        );
    }

    /**
     * @wire-source LiveAvatar `openapi.json` — `GET /v1/avatars` returns
     * `{code, data: {count, results: [{id, name, preview_url?, space_id,
     * type, status, availability, default_voice?, ...}]}, message}`. No
     * `language` field exists on this resource.
     *
     * @return list<array<string, mixed>>
     */
    private static function heygenAvatars(): array
    {
        $rows = self::heygenGet('/avatars');

        return array_map(
            fn (array $row): array => self::entry(
                id: self::stringOrEmpty($row['id'] ?? null),
                label: self::stringOrEmpty($row['name'] ?? null),
                previewImageUrl: self::stringOrNull($row['preview_url'] ?? null),
            ),
            $rows,
        );
    }

    /**
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private static function tavusGet(string $path, array $query): array
    {
        $apiKey = (string) config('interview.tavus.api_key', '');

        $response = Http::withHeaders(['x-api-key' => $apiKey])
            ->get(self::TAVUS_BASE_URL.$path, $query);

        if (! $response->successful()) {
            throw new RuntimeException('Tavus catalogue fetch failed');
        }

        $rows = $response->json('data');

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function heygenGet(string $path): array
    {
        $apiKey = (string) config('interview.heygen.api_key', '');

        $response = Http::withHeaders(['X-API-KEY' => $apiKey])
            ->get(self::HEYGEN_BASE_URL.$path);

        if (! $response->successful()) {
            throw new RuntimeException('HeyGen catalogue fetch failed');
        }

        $rows = $response->json('data.results');

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(
        string $id,
        string $label,
        ?string $language = null,
        ?string $previewImageUrl = null,
        ?string $previewAudioUrl = null,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'language' => $language,
            'preview_image_url' => $previewImageUrl,
            'preview_audio_url' => $previewAudioUrl,
        ];
    }

    private static function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
