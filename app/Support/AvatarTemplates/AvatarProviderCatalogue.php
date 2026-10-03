<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
 * - Preview media: ElevenLabs voices carry a public CDN `preview_url`, which
 *   surfaces as `preview_audio_url`. Cartesia voices carry `preview_file_url`
 *   (only when asked with `expand[]=preview_file_url`), but that file host
 *   answers 401 without the platform key, so its url is NEVER surfaced: the
 *   entry says `preview_audio_via_api: true` and the clip is served by
 *   `GET /api/avatar-templates/catalogue-sample`. Tavus voices carry none, and HeyGen's
 *   voice list carries none either (its sample is a per-voice base64 endpoint,
 *   deliberately NOT fetched here — that would be one call per voice — and is
 *   served by `POST /api/avatar-templates/voice-preview`). Tavus's replica
 *   (`thumbnail_image_url`/`thumbnail_video_url`) and HeyGen's avatar
 *   (`preview_url`) carry image/video previews.
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

    /**
     * Part of every cache key. BUMP IT whenever a catalogue item gains, loses
     * or changes a key (the shape snapshot test fails as a reminder): entries
     * live 24h, so a deploy that changes the shape would otherwise keep serving
     * the old one — e.g. personas without `editable` read as "unknown".
     * v2: Tavus persona items gained `editable`.
     * v3: every item gained `preview_audio_via_api`, and Cartesia items stopped
     *     carrying the raw `preview_file_url` in `preview_audio_url` (a v2 entry
     *     would keep serving that url to the browser for up to 24h).
     */
    public const CACHE_VERSION = 3;

    private const PAGE_SIZE = 100;

    private const TIMEOUT_SECONDS = 15;

    /** Safety bound on pagination so a misbehaving vendor cannot loop us. */
    private const MAX_PAGES = 20;

    /**
     * `status` is one of:
     *  - `ok`             — the provider answered with at least one usable item.
     *  - `empty`          — the provider answered successfully and has none. A
     *                       configuration matter (wrong account, nothing
     *                       created yet), not an outage, and never cached: the
     *                       operator is likely to fix it and retry at once.
     *  - `provider_error` — could not ask or was refused; `code` says why and is
     *                       safe to show (never a provider message or a key):
     *                       `provider_key_missing`, `provider_unauthorized`,
     *                       `provider_rate_limited`, `provider_unavailable`,
     *                       `provider_unreachable`, `provider_rejected`,
     *                       `provider_bad_response`, `unsupported_catalogue`.
     *                       Never cached either.
     *
     * @return array{status: string, items: list<array<string, mixed>>, code?: string}
     */
    public static function fetch(string $provider, string $resource, bool $fresh = false): array
    {
        $cacheKey = self::cacheKey($provider, $resource);

        // `$fresh` skips the 24h cache for one call: a just-created avatar or
        // voice must not be refused for a day because the list predates it.
        if ($fresh) {
            Cache::forget($cacheKey);
        }

        /** @var array{status: string, items: list<array<string, mixed>>}|null $cached */
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $items = self::fetchLive($provider, $resource);
        } catch (CatalogueFetchException $e) {
            // D3: a provider failure is NEVER cached — the next call retries
            // rather than replaying a stale failure. Only the safe code and the
            // exception CLASS are logged, never a message: some exception types
            // echo response content, which could carry the provider's own
            // error body.
            Log::warning('AvatarProviderCatalogue: provider fetch failed', [
                'provider' => $provider,
                'resource' => $resource,
                'code' => $e->safeCode,
                'http_status' => $e->httpStatus,
            ]);

            return ['status' => 'provider_error', 'items' => [], 'code' => $e->safeCode];
        } catch (Throwable $e) {
            Log::warning('AvatarProviderCatalogue: provider fetch failed', [
                'provider' => $provider,
                'resource' => $resource,
                'exception' => $e::class,
            ]);

            return ['status' => 'provider_error', 'items' => [], 'code' => 'provider_bad_response'];
        }

        if ($items === []) {
            return ['status' => 'empty', 'items' => []];
        }

        $result = ['status' => 'ok', 'items' => $items];
        Cache::put($cacheKey, $result, now()->addDay());

        return $result;
    }

    /** The one place a catalogue cache key is built. */
    public static function cacheKey(string $provider, string $resource): string
    {
        return 'avatar-catalogue:v'.self::CACHE_VERSION.":{$provider}:{$resource}";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchLive(string $provider, string $resource): array
    {
        // The HTTP surface (AvatarTemplateController::catalogue()) already
        // rejects an unknown provider/resource with 422 before reaching here —
        // this is a defensive floor for any other caller.
        $items = match ("{$provider}:{$resource}") {
            'tavus:voice' => self::tavusVoices(),
            'tavus:replica' => self::tavusReplicas(),
            'tavus:pal' => self::tavusPals(),
            'heygen:voice' => self::heygenVoices(),
            'heygen:avatar' => self::heygenAvatars(),
            'cartesia:voice' => self::cartesiaVoices(),
            'elevenlabs:voice' => self::elevenlabsVoices(),
            default => throw new CatalogueFetchException('unsupported_catalogue'),
        };

        return $resource === 'voice' ? self::italianFirst($items) : $items;
    }

    /**
     * @wire-source Tavus `openapi.yaml` (docs.tavus.io/openapi.yaml, read
     * 2026-09-25) — `GET /v2/voices?source=all&limit=&page=` returns `{data:
     * [{voice_id, voice_name, voice_type, status, description?, tags?,
     * created_at}], total_count}`. `limit` defaults to 10 (max 100), so an
     * unpaginated call silently returned ten voices. No `language` field
     * exists on this resource at all.
     *
     * @return list<array<string, mixed>>
     */
    private static function tavusVoices(): array
    {
        $rows = self::tavusPaged('/voices', ['source' => 'all']);

        return array_map(
            fn (array $row): array => self::entry(
                provider: 'tavus',
                id: self::stringOrEmpty($row['voice_id'] ?? null),
                label: self::stringOrEmpty($row['voice_name'] ?? null),
            ),
            array_values(array_filter($rows, static fn (array $row): bool => ($row['status'] ?? 'completed') === 'completed')),
        );
    }

    /**
     * Tavus renamed replicas to FACES (`replica_id` == `face_id`; the
     * conversation endpoint accepts both). `GET /v2/faces?verbose=true` returns
     * `{data: [{face_id, face_name, status, default_voice_id,
     * thumbnail_video_url, face_type, ...}], total_count}`. There is no still
     * thumbnail, so the preview is a VIDEO url.
     *
     * The resource is still named `replica` on this API's wire: it is the
     * picker's contract with the backoffice and with `ProviderFieldSpecs`.
     *
     * @return list<array<string, mixed>>
     */
    private static function tavusReplicas(): array
    {
        $rows = self::tavusPaged('/faces', ['verbose' => 'true']);

        return array_map(
            fn (array $row): array => self::entry(
                provider: 'tavus',
                id: self::stringOrEmpty($row['face_id'] ?? $row['replica_id'] ?? null),
                label: self::stringOrEmpty($row['face_name'] ?? $row['replica_name'] ?? null),
                previewImageUrl: self::stringOrNull($row['thumbnail_image_url'] ?? null),
                previewVideoUrl: self::stringOrNull($row['thumbnail_video_url'] ?? null),
            ),
            // `completed` only: a face still training or errored cannot start a
            // conversation, and offering it makes the interview fail later.
            array_values(array_filter($rows, static fn (array $row): bool => ($row['status'] ?? 'completed') === 'completed')),
        );
    }

    /**
     * `GET /v2/pals` returns `{data: [{pal_id, pal_name, default_face_id,
     * system_prompt, layers, ...}], total_count}`; paginated like the others
     * (`limit` default 10). Only the id, name and `editable` are kept: a PAL
     * carries its system prompt and layer configuration, none of which a picker
     * may see.
     *
     * `editable` says whether BEAI may PATCH this persona's layers:
     *  - `true`  — the id is in Tavus's `GET /v2/pals?persona_type=user` list,
     *              i.e. a persona the account authored;
     *  - `false` — the id is in `persona_type=system` (Tavus stock personas);
     *  - `null`  — UNKNOWN. In neither list (observed live 2026-09-29: 46
     *              personas unfiltered, 10 `user`, 30 `system`, so some are in
     *              neither), or the list that would decide could not be
     *              fetched. Unknown is never promoted to true: PATCH on such a
     *              persona was answered 400 "Invalid persona_id".
     * A persona in both lists is `true`. A failed `persona_type` call degrades
     * only this flag; it never fails the catalogue.
     *
     * @return list<array<string, mixed>>
     */
    private static function tavusPals(): array
    {
        $rows = self::tavusPaged('/pals', []);
        $userIds = self::tavusPalIds('user');
        $systemIds = self::tavusPalIds('system');

        return array_map(
            function (array $row) use ($userIds, $systemIds): array {
                $id = self::stringOrEmpty($row['pal_id'] ?? $row['persona_id'] ?? null);

                $editable = match (true) {
                    $userIds !== null && in_array($id, $userIds, true) => true,
                    $systemIds !== null && in_array($id, $systemIds, true) => false,
                    default => null,
                };

                return self::entry(
                    provider: 'tavus',
                    id: $id,
                    label: self::stringOrEmpty($row['pal_name'] ?? $row['persona_name'] ?? null),
                ) + ['editable' => $editable];
            },
            $rows,
        );
    }

    /**
     * The ids of one `persona_type` list, or `null` when it could not be
     * fetched (so the caller can tell "not in the list" from "list unknown").
     *
     * @return list<string>|null
     */
    private static function tavusPalIds(string $personaType): ?array
    {
        try {
            $rows = self::tavusPaged('/pals', ['persona_type' => $personaType]);
        } catch (CatalogueFetchException $e) {
            Log::warning('AvatarProviderCatalogue: persona_type list failed', [
                'persona_type' => $personaType,
                'code' => $e->safeCode,
                'http_status' => $e->httpStatus,
            ]);

            return null;
        }

        return array_values(array_filter(array_map(
            static fn (array $row): string => self::stringOrEmpty($row['pal_id'] ?? $row['persona_id'] ?? null),
            $rows,
        ), static fn (string $id): bool => $id !== ''));
    }

    /**
     * @wire-source LiveAvatar `openapi.json` (docs.liveavatar.com/openapi.json,
     * read 2026-09-25) — `GET /v1/voices?page=&page_size=&voice_type=public|
     * private` returns `{code, data: {count, next, previous, results: [{id,
     * name, description?, language, gender, tags, ...}]}}`. `page_size`
     * defaults to 20 (max 100) and `voice_type` defaults to `public`, so an
     * unpaginated call missed most voices and every private (third-party
     * bound) one. No preview URL exists on the item: the preview is a
     * separate base64 endpoint.
     *
     * @return list<array<string, mixed>>
     */
    private static function heygenVoices(): array
    {
        // Private voices are the operator's own bindings: their absence must
        // not hide the public catalogue, so a failure there is tolerated.
        $rows = array_merge(
            self::heygenPaged('/voices', ['voice_type' => 'public']),
            self::heygenPaged('/voices', ['voice_type' => 'private'], optional: true),
        );

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
            self::uniqueById($rows, 'id'),
        );
    }

    /**
     * `GET /v1/avatars` lists only the account's OWN avatars — empty for an
     * account that has trained none, which is exactly why HeyGen "returned no
     * avatars". The stock avatars live on `GET /v1/avatars/public`. Both are
     * paginated (`page_size` default 20, max 100) and both are merged here.
     *
     * Items: `{id, name, preview_url?, status, is_expired, type, ...}`.
     * Expired avatars and any status other than ACTIVE cannot start a session,
     * so they are not offered.
     *
     * @return list<array<string, mixed>>
     */
    private static function heygenAvatars(): array
    {
        $rows = array_merge(
            self::heygenPaged('/avatars/public'),
            self::heygenPaged('/avatars', optional: true),
        );

        $usable = array_filter(
            $rows,
            static fn (array $row): bool => ($row['is_expired'] ?? false) !== true
                && ($row['status'] ?? 'ACTIVE') === 'ACTIVE',
        );

        return array_map(
            fn (array $row): array => self::entry(
                provider: 'heygen',
                id: self::stringOrEmpty($row['id'] ?? null),
                label: self::stringOrEmpty($row['name'] ?? null),
                previewImageUrl: self::stringOrNull($row['preview_url'] ?? null),
            ),
            self::uniqueById(array_values($usable), 'id'),
        );
    }

    /**
     * @wire-source Cartesia `GET /voices` — `{data: [{id, name, description,
     * language, gender, is_public, ...}], has_more, next_page}`, paginated
     * with `limit` / `starting_after`. Older API versions answer a bare list;
     * both are accepted. Cartesia carries no accent field, so `accent` is
     * always null. `preview_file_url` is only present with
     * `expand[]=preview_file_url`. Verified live 2026-10-03: the list, the
     * per-voice `GET /voices/{id}?expand[]=preview_file_url` and the file
     * download (Bearer or `X-API-Key`, answers `audio/wav`; 401 without a key).
     *
     * @return list<array<string, mixed>>
     */
    private static function cartesiaVoices(): array
    {
        $rows = [];
        $startingAfter = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = self::request(
                'cartesia',
                self::CARTESIA_BASE_URL.'/voices',
                (string) config('services.cartesia.api_key', ''),
                'X-API-Key',
                // A string, not an array: Guzzle would encode the list as `expand[0]=`,
                // and Cartesia documents the repeated `expand[]` form.
                http_build_query(['limit' => self::PAGE_SIZE] + ($startingAfter === null ? [] : ['starting_after' => $startingAfter]))
                    .'&expand[]=preview_file_url',
                ['Cartesia-Version' => self::CARTESIA_VERSION],
            );

            $isList = array_is_list($body);
            $batch = $isList ? $body : ($body['data'] ?? []);
            $rows = array_merge($rows, is_array($batch) ? array_values($batch) : []);

            $next = $isList ? null : ($body['next_page'] ?? null);

            if ($isList || ($body['has_more'] ?? false) !== true || ! is_string($next) || $next === '') {
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
                    // Only WHETHER a clip exists: the url needs the key (see the class docblock).
                    previewAudioViaApi: self::stringOrNull($row['preview_file_url'] ?? null) !== null,
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
        $rows = [];
        $token = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = self::request(
                'elevenlabs',
                self::ELEVENLABS_BASE_URL.'/v2/voices',
                (string) config('services.elevenlabs.api_key', ''),
                'xi-api-key',
                ['page_size' => self::PAGE_SIZE] + ($token === null ? [] : ['next_page_token' => $token]),
            );

            $batch = $body['voices'] ?? [];
            $rows = array_merge($rows, is_array($batch) ? array_values($batch) : []);

            $next = $body['next_page_token'] ?? null;

            if (($body['has_more'] ?? false) !== true || ! is_string($next) || $next === '') {
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
     * Tavus list endpoints: `limit` (1-100, default 10) / `page`, with
     * `total_count`.
     *
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private static function tavusPaged(string $path, array $query): array
    {
        $rows = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = self::request(
                'tavus',
                self::TAVUS_BASE_URL.$path,
                (string) config('interview.tavus.api_key', ''),
                'x-api-key',
                $query + ['limit' => self::PAGE_SIZE, 'page' => $page],
            );

            $batch = is_array($body['data'] ?? null) ? array_values(array_filter($body['data'], 'is_array')) : [];
            $rows = array_merge($rows, $batch);

            $total = $body['total_count'] ?? null;

            if ($batch === [] || count($batch) < self::PAGE_SIZE || (is_int($total) && count($rows) >= $total)) {
                break;
            }
        }

        return $rows;
    }

    /**
     * LiveAvatar list endpoints: `page` / `page_size` (default 20, max 100),
     * envelope `{code, data: {count, next, previous, results}}`.
     *
     * `$optional` swallows a failure of a secondary list (the account's own
     * avatars / private voices) so it cannot hide the public catalogue; the
     * failure is still logged with its safe code.
     *
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private static function heygenPaged(string $path, array $query = [], bool $optional = false): array
    {
        $rows = [];

        try {
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $body = self::request(
                    'heygen',
                    self::HEYGEN_BASE_URL.$path,
                    (string) config('interview.heygen.api_key', ''),
                    'X-API-KEY',
                    $query + ['page' => $page, 'page_size' => self::PAGE_SIZE],
                );

                $data = is_array($body['data'] ?? null) ? $body['data'] : [];
                $batch = is_array($data['results'] ?? null) ? array_values(array_filter($data['results'], 'is_array')) : [];
                $rows = array_merge($rows, $batch);

                $next = $data['next'] ?? null;

                if ($batch === [] || ! is_string($next) || $next === '') {
                    break;
                }
            }
        } catch (CatalogueFetchException $e) {
            if (! $optional) {
                throw $e;
            }

            Log::warning('AvatarProviderCatalogue: optional list failed', [
                'path' => $path,
                'code' => $e->safeCode,
            ]);
        }

        return $rows;
    }

    /**
     * One authenticated GET, with failures mapped to SAFE codes.
     *
     * The key is used ONLY as the auth header value. Neither this method nor
     * its callers ever place any part of a response body into an exception
     * message or a return value.
     *
     * @param  array<string, mixed>|string  $query
     * @param  array<string, string>  $extraHeaders
     * @return array<mixed>
     */
    private static function request(
        string $provider,
        string $url,
        string $apiKey,
        string $authHeader,
        array|string $query,
        array $extraHeaders = [],
    ): array {
        if ($apiKey === '') {
            throw new CatalogueFetchException('provider_key_missing');
        }

        try {
            $response = Http::withHeaders([$authHeader => $apiKey] + $extraHeaders)
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url, $query);
        } catch (ConnectionException) {
            throw new CatalogueFetchException('provider_unreachable');
        }

        if (! $response->successful()) {
            $status = $response->status();

            throw new CatalogueFetchException(match (true) {
                $status === 401, $status === 403 => 'provider_unauthorized',
                $status === 429 => 'provider_rate_limited',
                $status >= 500 => 'provider_unavailable',
                default => 'provider_rejected',
            }, $status);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new CatalogueFetchException('provider_bad_response', $response->status());
        }

        return $body;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function uniqueById(array $rows, string $key): array
    {
        $seen = [];
        $unique = [];

        foreach ($rows as $row) {
            $id = $row[$key] ?? null;

            if (! is_string($id) || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $unique[] = $row;
        }

        return $unique;
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
        ?string $previewVideoUrl = null,
        bool $previewAudioViaApi = false,
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
            'preview_audio_via_api' => $previewAudioViaApi,
            'preview_video_url' => $previewVideoUrl,
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
