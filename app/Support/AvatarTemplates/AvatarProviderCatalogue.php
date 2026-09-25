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
 * VOICE-ONLY providers: `cartesia` and `elevenlabs` (resource `voice`) list a
 * third-party TTS vendor's own voices. They are not avatar providers — a Tavus
 * template uses one by setting `ttsEngine` to the vendor and
 * `ttsExternalVoiceId` to the `id` returned here. Keys come from
 * `config('services.cartesia.api_key')` / `config('services.elevenlabs.api_key')`
 * (env `CARTESIA_API_KEY` / `ELEVENLABS_API_KEY`) and never leave this class.
 *
 * ITALIAN DETECTION (`italian` on every voice entry): `native` when the
 * provider itself says the voice is Italian — its own language tag is `it`
 * (Cartesia `language`, LiveAvatar `language`, ElevenLabs `labels.language` or
 * the FIRST `verified_languages` entry), or the ElevenLabs `accent` label is
 * `italian`. `multilingual` when an ElevenLabs voice merely lists Italian among
 * secondary verified languages: it can speak Italian but is not an Italian
 * voice. `null` otherwise, including every Tavus voice (no language data at
 * all). Voice lists are returned native-first, then multilingual, then the
 * rest, each group by name. Nothing is inferred from a voice's name.
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

    private const CARTESIA_BASE_URL = 'https://api.cartesia.ai';

    /** Cartesia requires an explicit API version header. */
    private const CARTESIA_VERSION = '2025-04-16';

    private const ELEVENLABS_BASE_URL = 'https://api.elevenlabs.io';

    private const PAGE_SIZE = 100;

    /** Safety bound on pagination so a misbehaving vendor cannot loop us. */
    private const MAX_PAGES = 20;

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
            'cartesia:voice' => self::cartesiaVoices(),
            'elevenlabs:voice' => self::elevenlabsVoices(),
            default => throw new RuntimeException("Unknown avatar catalogue provider/resource pair: {$provider}/{$resource}"),
        };

        if ($resource === 'voice') {
            $items = self::italianFirst($items);
        }

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
                provider: 'tavus',
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
                provider: 'tavus',
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
                provider: 'heygen',
                id: self::stringOrEmpty($row['id'] ?? null),
                label: self::stringOrEmpty($row['name'] ?? null),
                // D4: read verbatim, never guessed/defaulted — a `null`
                // entry (an absent key, or a genuinely null value) stays
                // `null` here exactly as it does for Tavus.
                language: self::stringOrNull($row['language'] ?? null),
                locale: self::stringOrNull($row['language'] ?? null),
                italian: self::isItalianCode($row['language'] ?? null) ? 'native' : null,
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
                provider: 'heygen',
                id: self::stringOrEmpty($row['id'] ?? null),
                label: self::stringOrEmpty($row['name'] ?? null),
                previewImageUrl: self::stringOrNull($row['preview_url'] ?? null),
            ),
            $rows,
        );
    }

    /**
     * @wire-source Cartesia `GET /voices` — `{data: [{id, name, description,
     * language, gender, is_public, ...}], has_more, next_page}`, paginated
     * with `limit` / `starting_after`. Older API versions answer a bare list;
     * both are accepted. Cartesia carries no accent field, so `accent` is
     * always null. UNVERIFIED against a live account.
     *
     * @return list<array<string, mixed>>
     */
    private static function cartesiaVoices(): array
    {
        $apiKey = (string) config('services.cartesia.api_key', '');

        if ($apiKey === '') {
            throw new RuntimeException('Cartesia key missing');
        }

        $rows = [];
        $startingAfter = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['limit' => self::PAGE_SIZE] + ($startingAfter === null ? [] : ['starting_after' => $startingAfter]);

            $response = Http::withHeaders(['X-API-Key' => $apiKey, 'Cartesia-Version' => self::CARTESIA_VERSION])
                ->timeout(15)
                ->get(self::CARTESIA_BASE_URL.'/voices', $query);

            if (! $response->successful()) {
                throw new RuntimeException('Cartesia catalogue fetch failed');
            }

            $body = $response->json();
            $batch = is_array($body) && array_is_list($body) ? $body : ($body['data'] ?? []);
            $rows = array_merge($rows, is_array($batch) ? array_values($batch) : []);

            $next = is_array($body) && ! array_is_list($body) ? ($body['next_page'] ?? null) : null;

            if (! is_array($body) || array_is_list($body) || ($body['has_more'] ?? false) !== true || ! is_string($next) || $next === '') {
                break;
            }

            $startingAfter = $next;
        }

        return array_map(
            function (array $row): array {
                $language = self::stringOrNull($row['language'] ?? null);

                return self::entry(
                    provider: 'cartesia',
                    id: self::stringOrEmpty($row['id'] ?? null),
                    label: self::stringOrEmpty($row['name'] ?? null),
                    language: $language,
                    locale: $language,
                    italian: self::isItalianCode($language) ? 'native' : null,
                );
            },
            array_values(array_filter($rows, 'is_array')),
        );
    }

    /**
     * @wire-source ElevenLabs `GET /v2/voices` — `{voices: [{voice_id, name,
     * category, preview_url, labels: {accent, gender, language, ...},
     * verified_languages: [{language, locale, accent, model_id,
     * preview_url}]}], has_more, total_count, next_page_token}`. UNVERIFIED
     * against a live account.
     *
     * @return list<array<string, mixed>>
     */
    private static function elevenlabsVoices(): array
    {
        $apiKey = (string) config('services.elevenlabs.api_key', '');

        if ($apiKey === '') {
            throw new RuntimeException('ElevenLabs key missing');
        }

        $rows = [];
        $token = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['page_size' => self::PAGE_SIZE] + ($token === null ? [] : ['next_page_token' => $token]);

            $response = Http::withHeaders(['xi-api-key' => $apiKey])
                ->timeout(15)
                ->get(self::ELEVENLABS_BASE_URL.'/v2/voices', $query);

            if (! $response->successful()) {
                throw new RuntimeException('ElevenLabs catalogue fetch failed');
            }

            $batch = $response->json('voices');
            $rows = array_merge($rows, is_array($batch) ? array_values($batch) : []);

            $next = $response->json('next_page_token');

            if ($response->json('has_more') !== true || ! is_string($next) || $next === '') {
                break;
            }

            $token = $next;
        }

        return array_map(self::elevenlabsEntry(...), array_values(array_filter($rows, 'is_array')));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function elevenlabsEntry(array $row): array
    {
        $labels = is_array($row['labels'] ?? null) ? $row['labels'] : [];

        /** @var list<array<string, mixed>> $verified */
        $verified = array_values(array_filter(
            is_array($row['verified_languages'] ?? null) ? $row['verified_languages'] : [],
            'is_array',
        ));

        $primary = $verified[0] ?? null;
        $labelLanguage = self::normalizeLanguage($labels['language'] ?? null);
        $language = $labelLanguage ?? self::normalizeLanguage($primary['language'] ?? null);

        // The verified entry describing the voice's OWN language, when there is one.
        $own = null;
        foreach ($verified as $entry) {
            if (self::normalizeLanguage($entry['language'] ?? null) === $language) {
                $own = $entry;
                break;
            }
        }
        $own ??= $primary;

        $accent = self::stringOrNull($own['accent'] ?? null) ?? self::stringOrNull($labels['accent'] ?? null);

        $italian = null;
        if ($language === 'it' || mb_strtolower((string) ($labels['accent'] ?? '')) === 'italian') {
            $italian = 'native';
        } else {
            foreach ($verified as $entry) {
                if (self::normalizeLanguage($entry['language'] ?? null) === 'it') {
                    $italian = 'multilingual';
                    break;
                }
            }
        }

        return self::entry(
            provider: 'elevenlabs',
            id: self::stringOrEmpty($row['voice_id'] ?? null),
            label: self::stringOrEmpty($row['name'] ?? null),
            language: $language,
            previewAudioUrl: self::stringOrNull($own['preview_url'] ?? null) ?? self::stringOrNull($row['preview_url'] ?? null),
            locale: self::stringOrNull($own['locale'] ?? null),
            accent: $accent,
            italian: $italian,
        );
    }

    /**
     * Native Italian first, then multilingual, then everything else; each
     * group ordered by name. Stable and deterministic for the picker.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function italianFirst(array $items): array
    {
        $rank = static fn (array $item): int => match ($item['italian'] ?? null) {
            'native' => 0,
            'multilingual' => 1,
            default => 2,
        };

        usort($items, static fn (array $a, array $b): int => [$rank($a), mb_strtolower((string) $a['name'])]
            <=> [$rank($b), mb_strtolower((string) $b['name'])]);

        return $items;
    }

    private static function normalizeLanguage(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = mb_strtolower(trim($value));

        return $value === 'italian' ? 'it' : $value;
    }

    private static function isItalianCode(mixed $value): bool
    {
        return self::normalizeLanguage($value) === 'it';
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
     * The one catalogue item shape. `label` and `name` carry the same value:
     * `label` predates the voice providers, `name` is the field the voice
     * picker reads.
     *
     * @return array<string, mixed>
     */
    private static function entry(
        string $provider,
        string $id,
        string $label,
        ?string $language = null,
        ?string $previewImageUrl = null,
        ?string $previewAudioUrl = null,
        ?string $locale = null,
        ?string $accent = null,
        ?string $italian = null,
    ): array {
        return [
            'id' => $id,
            'provider' => $provider,
            'label' => $label,
            'name' => $label,
            'language' => $language,
            'locale' => $locale,
            'accent' => $accent,
            'italian' => $italian,
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
