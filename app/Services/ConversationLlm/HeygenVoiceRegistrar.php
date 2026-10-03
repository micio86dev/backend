<?php

declare(strict_types=1);

namespace App\Services\ConversationLlm;

use App\Models\HeygenBoundVoice;
use App\Models\HeygenVendorSecret;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Binds a Cartesia / ElevenLabs voice to LiveAvatar so a HeyGen session can
 * speak with it, and remembers the answer (heygen-third-party-voices H2).
 *
 * Modelled on `HeygenLlmRegistrar`: the same host, header and "NEVER THROWS"
 * doctrine. Every method returns a typed result, because a save that cannot
 * bind must say so in words an operator can act on, and a registrar that threw
 * would turn a provider hiccup into a 500.
 *
 * Only the PLATFORM vendor keys are used (`services.cartesia.api_key`,
 * `services.elevenlabs.api_key`, the same ones the catalogue and the previews
 * use): there are no per-tenant keys in this first cut (owner decision
 * 2026-10-03). A bound voice and its secret are KEPT after any template that
 * names them is deleted; nothing here ever deletes one on a template's behalf.
 *
 * WHAT LIVEAVATAR ACTUALLY DOES (live evidence 2026-10-03, base
 * `https://api.liveavatar.com/v1`, header `X-API-KEY`; raw captures in the
 * session scratchpad `h1/s1.out`..`s4.out`)
 * -------------------------------------------------------------------------
 *
 * @wire-source `POST /v1/secrets` `{secret_name, secret_value, secret_type:
 * 'CARTESIA_API_KEY'}` -> HTTP 200 `{code:1000, data:{id, secret_name},
 * message}`; the id is at `data.id`. (`ELEVENLABS_API_KEY` is a documented
 * `SecretTypeEnum` value; only the Cartesia one was exercised live.)
 * @wire-source `POST /v1/voices/third_party` `{provider_voice_id, secret_id,
 * name?}` -> HTTP 200 `{code:1000, data:{voice_id}, message:'Voice imported
 * successfully'}` with the platform Cartesia key and a real Italian Cartesia
 * voice. With no `name` the voice is called `Imported Voice: <provider id>`.
 *
 * Four facts that shape this class, each seen live:
 *  1. NOT IDEMPOTENT. Three identical binds returned three DIFFERENT voice ids
 *     and three records in `GET /v1/voices?voice_type=private`. The ledger
 *     (`heygen_bound_voices`, UNIQUE `(engine, provider_voice_id)`) is the only
 *     idempotency, and a lock around "read ledger, POST, insert" keeps two
 *     concurrent saves from both reaching the POST.
 *  2. NO VALIDATION OF THE PROVIDER VOICE ID. A well-formed non-existent id and
 *     the string `not-a-real-voice-id` both answered HTTP 200 and created a
 *     voice. A successful bind therefore proves NOTHING about the voice: the
 *     caller must refuse unknown ids against the vendor catalogue BEFORE
 *     binding.
 *  3. THE BOUND VOICE'S `language` IS ALWAYS `en` (`GET /v1/voices/{id}` ->
 *     `language: 'en'`, `gender: 'unknown'`, tags `Cartesia`,`Imported`) whatever
 *     the real voice speaks, so it is never read, trusted or surfaced here. The
 *     spoken language comes from `avatar_persona.language`, project-sourced.
 *  4. AN UNKNOWN `secret_id` answers HTTP 400 `{code:4000, data:null, message:
 *     "Secret with id '...' not found in your space"}`. That is how a secret
 *     deleted in LiveAvatar's own dashboard shows up, and it is the one case
 *     where the memoised secret is dropped, recreated once and the bind retried
 *     once.
 *
 * Failure codes (stable, mapped to words by the backoffice): see FAILURES.
 */
final class HeygenVoiceRegistrar
{
    /** Same host as `HeygenProvider::BASE_URL`; a separate literal on purpose, like `HeygenLlmRegistrar`. */
    private const BASE_URL = 'https://api.liveavatar.com/v1';

    /** Bounded so a slow provider cannot hold an admin request open. */
    private const TIMEOUT_SECONDS = 10;

    /** LiveAvatar `SecretTypeEnum` per engine. */
    public const SECRET_TYPES = [
        'cartesia' => 'CARTESIA_API_KEY',
        'elevenlabs' => 'ELEVENLABS_API_KEY',
    ];

    /** Longest voice name sent (LiveAvatar documents 64 for a rename). */
    private const NAME_MAX = 64;

    /** The lock outlives any single bound request, then expires on its own. */
    private const LOCK_SECONDS = 60;

    /**
     * Every `failed` code this class can return.
     *
     *  - `tts_engine_unsupported`   engine is not cartesia/elevenlabs
     *  - `tts_provider_unconfigured` BEAI's HeyGen (LiveAvatar) key is not set
     *  - `tts_vendor_key_missing`   BEAI's Cartesia/ElevenLabs key is not set
     *  - `tts_secret_failed`        LiveAvatar refused or lost the secret
     *  - `tts_voice_bind_failed`    LiveAvatar refused or lost the bind
     *  - `tts_bind_busy`            another save is binding this very voice
     */
    public const FAILURES = [
        'tts_engine_unsupported',
        'tts_provider_unconfigured',
        'tts_vendor_key_missing',
        'tts_secret_failed',
        'tts_voice_bind_failed',
        'tts_bind_busy',
    ];

    /** The LiveAvatar voice id for `(engine, voice)` if already bound. Ledger only: never calls LiveAvatar. */
    public function boundVoiceId(string $engine, string $providerVoiceId): ?string
    {
        $voiceId = HeygenBoundVoice::query()
            ->where('engine', $engine)
            ->where('provider_voice_id', $providerVoiceId)
            ->value('voice_id');

        return is_string($voiceId) && $voiceId !== '' ? $voiceId : null;
    }

    /**
     * The LiveAvatar voice id for `(engine, voice)`, binding it on first use.
     * NEVER THROWS.
     *
     * @return array{status: 'bound', voice_id: string}|array{status: 'failed', code: string}
     */
    public function ensureVoice(string $engine, string $providerVoiceId): array
    {
        if (! isset(self::SECRET_TYPES[$engine])) {
            return $this->failed('tts_engine_unsupported');
        }

        $existing = $this->boundVoiceId($engine, $providerVoiceId);

        if ($existing !== null) {
            return ['status' => 'bound', 'voice_id' => $existing];
        }

        if ($this->heygenKey() === '') {
            return $this->failed('tts_provider_unconfigured');
        }

        if ($this->vendorKey($engine) === '') {
            return $this->failed('tts_vendor_key_missing');
        }

        $lock = $this->acquire('heygen-voice-bind:'.$engine.':'.sha1($providerVoiceId));

        if ($lock === null) {
            // The save that holds the lock may have finished while this one waited.
            $finished = $this->boundVoiceId($engine, $providerVoiceId);

            return $finished !== null
                ? ['status' => 'bound', 'voice_id' => $finished]
                : $this->failed('tts_bind_busy');
        }

        try {
            // Re-read under the lock: the winner of an earlier race has committed by now.
            $existing = $this->boundVoiceId($engine, $providerVoiceId);

            return $existing !== null
                ? ['status' => 'bound', 'voice_id' => $existing]
                : $this->bind($engine, $providerVoiceId);
        } catch (Throwable $e) {
            Log::warning('HeyGen voice bind errored', ['engine' => $engine, 'exception' => $e::class]);

            return $this->failed('tts_voice_bind_failed');
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{status: 'bound', voice_id: string}|array{status: 'failed', code: string}
     */
    private function bind(string $engine, string $providerVoiceId): array
    {
        $secretId = $this->ensureSecret($engine);

        if ($secretId === null) {
            return $this->failed('tts_secret_failed');
        }

        $response = $this->bindRequest($engine, $providerVoiceId, $secretId);

        if ($response !== null && $this->isUnknownSecret($response)) {
            // Deleted in LiveAvatar's own dashboard: forget it, recreate ONCE, retry ONCE.
            HeygenVendorSecret::query()->where('engine', $engine)->delete();
            $secretId = $this->ensureSecret($engine);

            if ($secretId === null) {
                return $this->failed('tts_secret_failed');
            }

            $response = $this->bindRequest($engine, $providerVoiceId, $secretId);
        }

        if ($response === null || ! $response->successful()) {
            // The status only, never the body: a vendor error can echo request content.
            Log::warning('HeyGen voice bind failed', ['engine' => $engine, 'status' => $response?->status()]);

            return $this->failed('tts_voice_bind_failed');
        }

        $voiceId = $response->json('data.voice_id');

        if (! is_string($voiceId) || $voiceId === '') {
            Log::warning('HeyGen voice bind returned a malformed id', ['engine' => $engine]);

            return $this->failed('tts_voice_bind_failed');
        }

        return $this->record($engine, $providerVoiceId, $secretId, $voiceId);
    }

    /**
     * @return array{status: 'bound', voice_id: string}|array{status: 'failed', code: string}
     */
    private function record(string $engine, string $providerVoiceId, string $secretId, string $voiceId): array
    {
        try {
            // A nested transaction is a SAVEPOINT: a unique violation must not poison an outer one.
            DB::transaction(fn () => HeygenBoundVoice::query()->create([
                'engine' => $engine,
                'provider_voice_id' => $providerVoiceId,
                'secret_id' => $secretId,
                'voice_id' => $voiceId,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Another PROCESS (the lock is only as shared as the cache) committed first.
            // Adopt its voice and remove the duplicate this request just created.
            $this->deleteVoice($voiceId);
            $winner = $this->boundVoiceId($engine, $providerVoiceId);

            return $winner !== null
                ? ['status' => 'bound', 'voice_id' => $winner]
                : $this->failed('tts_voice_bind_failed');
        }

        return ['status' => 'bound', 'voice_id' => $voiceId];
    }

    /** The vendor's LiveAvatar secret id, creating it on first use. Memoised per vendor. */
    private function ensureSecret(string $engine): ?string
    {
        $stored = HeygenVendorSecret::query()->where('engine', $engine)->value('secret_id');

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $lock = $this->acquire('heygen-voice-secret:'.$engine);

        if ($lock === null) {
            $stored = HeygenVendorSecret::query()->where('engine', $engine)->value('secret_id');

            return is_string($stored) && $stored !== '' ? $stored : null;
        }

        try {
            $stored = HeygenVendorSecret::query()->where('engine', $engine)->value('secret_id');

            if (is_string($stored) && $stored !== '') {
                return $stored;
            }

            $response = $this->send('post', '/secrets', [
                'secret_name' => 'beai-platform-'.$engine,
                'secret_value' => $this->vendorKey($engine),
                'secret_type' => self::SECRET_TYPES[$engine],
            ]);

            if ($response === null || ! $response->successful()) {
                Log::warning('HeyGen vendor secret registration failed', ['engine' => $engine, 'status' => $response?->status()]);

                return null;
            }

            $secretId = $response->json('data.id');

            if (! is_string($secretId) || $secretId === '') {
                Log::warning('HeyGen vendor secret registration returned a malformed id', ['engine' => $engine]);

                return null;
            }

            HeygenVendorSecret::query()->create(['engine' => $engine, 'secret_id' => $secretId]);

            return $secretId;
        } finally {
            $lock->release();
        }
    }

    private function bindRequest(string $engine, string $providerVoiceId, string $secretId): ?Response
    {
        return $this->send('post', '/voices/third_party', [
            'provider_voice_id' => $providerVoiceId,
            'secret_id' => $secretId,
            'name' => mb_substr('beai-'.$engine.'-'.$providerVoiceId, 0, self::NAME_MAX),
        ]);
    }

    /** Best effort: clears a duplicate this request itself created. Never throws. */
    private function deleteVoice(string $voiceId): void
    {
        $this->send('delete', '/voices/'.$voiceId);
    }

    private function isUnknownSecret(Response $response): bool
    {
        return $response->status() === 400
            && $response->json('code') === 4000
            && str_contains((string) $response->json('message'), 'Secret');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(string $method, string $path, array $body = []): ?Response
    {
        try {
            $request = Http::withHeaders(['X-API-KEY' => $this->heygenKey()])->timeout(self::TIMEOUT_SECONDS);

            return $method === 'delete'
                ? $request->delete(self::BASE_URL.$path)
                : $request->post(self::BASE_URL.$path, $body);
        } catch (Throwable $e) {
            Log::warning('HeyGen voice call errored', ['path' => $path, 'exception' => $e::class]);

            return null;
        }
    }

    /**
     * A held lock, or null when it could not be taken within the configured wait
     * (`interview.heygen.bind_lock_wait_seconds`; 0 tries exactly once).
     */
    private function acquire(string $name): ?Lock
    {
        $lock = Cache::lock($name, self::LOCK_SECONDS);
        $deadline = microtime(true) + max(0, (int) config('interview.heygen.bind_lock_wait_seconds', 10));

        do {
            if ($lock->get()) {
                return $lock;
            }

            usleep(200_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function heygenKey(): string
    {
        return (string) config('interview.heygen.api_key', '');
    }

    private function vendorKey(string $engine): string
    {
        return (string) config("services.{$engine}.api_key", '');
    }

    /**
     * @return array{status: 'failed', code: string}
     */
    private function failed(string $code): array
    {
        return ['status' => 'failed', 'code' => $code];
    }
}
