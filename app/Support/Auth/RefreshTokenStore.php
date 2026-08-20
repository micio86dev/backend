<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Redis-backed refresh-token family store (backoffice-session-refresh-hardening
 * D2/D6). No database migration — every key here is Cache-only (Redis in
 * production, the array store in testing) and self-expiring.
 *
 * Reuses the atomic-consume convention already established by
 * App\Support\Jwt\CandidateTokenFactory::consumeJti(): Cache::add() is
 * SET NX EX in a Redis-backed store, so rotation is atomic by construction —
 * "already spent" IS the reuse signal, no separate lock is needed.
 *
 * Key shapes (D2), all reached only through this class:
 *   refresh_family:{family_id}     -> {user_id, generation, absolute_expires_at}
 *   refresh_tok:{sha256(secret)}   -> {family_id, generation, user_id}
 *   refresh_spent:{sha256(secret)} -> {family_id, generation, spent_at}
 *
 * Absolute expiry (A4) is mechanical: every write's TTL is
 * `absolute_expires_at - now`, NEVER a re-assertion of the 14-day constant —
 * see ttlUntil(). `absolute_expires_at` itself is stamped once in issue() and
 * copied unchanged through every rotation.
 */
final class RefreshTokenStore
{
    /**
     * Backstop buffer added ONLY to the family/tok Cache TTL (never to the
     * absolute_expires_at value itself, never to the cookie Max-Age). The
     * manual `now >= absolute_expires_at` check in rotateLiveToken() is the
     * AUTHORITATIVE ceiling gate — it is what returns the distinguishable
     * `Expired` status. Without this buffer, the cache driver's own native
     * TTL eviction would race that check and win (same "now" reference,
     * same instant), silently degrading every past-ceiling rotation to the
     * indistinguishable `Invalid` (unknown-hash) outcome instead. The buffer
     * only delays garbage collection of an abandoned key; it never extends
     * how long a token is usable.
     */
    private const int TTL_BACKSTOP_BUFFER_SECONDS = 300;

    /**
     * New family (login). Generation starts at 0.
     */
    public function issue(int $userId): RefreshTokenIssue
    {
        $familyId = (string) Str::uuid();
        $secret = $this->newSecret();
        $absoluteExpiresAt = now()
            ->addMinutes((int) config('refresh_tokens.absolute_ttl_minutes'))
            ->getTimestamp();

        $this->writeFamily($familyId, $userId, generation: 0, absoluteExpiresAt: $absoluteExpiresAt);
        $this->writeTok($secret, $familyId, generation: 0, userId: $userId, absoluteExpiresAt: $absoluteExpiresAt);

        return new RefreshTokenIssue($familyId, $secret, $absoluteExpiresAt);
    }

    /**
     * D2 rotation algorithm, steps 1-4b, with the D6 concurrency-grace
     * extension folded into both branches that can observe "already spent".
     */
    public function rotate(string $familyId, string $secret): RefreshRotateResult
    {
        $hash = hash('sha256', $secret);
        $tokKey = "refresh_tok:{$hash}";
        $spentKey = "refresh_spent:{$hash}";

        $rec = Cache::get($tokKey);

        if (is_array($rec)) {
            return $this->rotateLiveToken($familyId, $tokKey, $spentKey, $rec);
        }

        // rec === null: the token was already consumed (possible reuse or a
        // concurrent duplicate — D6), or it never existed at all. Step 4b is
        // load-bearing: an unattributable hash must NEVER revoke a family,
        // or anyone able to POST garbage could log families out at will.
        $spent = Cache::get($spentKey);

        if (! is_array($spent)) {
            return RefreshRotateResult::invalid();
        }

        return $this->resolveAgainstTombstone($spent);
    }

    /**
     * Deletes the family record — invalidates every outstanding token in it
     * immediately (logout, or the terminal step of a detected reuse).
     */
    public function revokeFamily(string $familyId): void
    {
        Cache::forget("refresh_family:{$familyId}");
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function rotateLiveToken(string $familyId, string $tokKey, string $spentKey, array $rec): RefreshRotateResult
    {
        if (($rec['family_id'] ?? null) !== $familyId) {
            // The family id travels in the token but is attacker-controlled
            // and is therefore cross-checked against the stored record,
            // never trusted — the hash is the authority (D2).
            return RefreshRotateResult::invalid();
        }

        $famKey = "refresh_family:{$familyId}";
        $fam = Cache::get($famKey);

        if (! is_array($fam)) {
            return RefreshRotateResult::revoked();
        }

        $absoluteExpiresAt = (int) $fam['absolute_expires_at'];

        if (now()->getTimestamp() >= $absoluteExpiresAt) {
            Cache::forget($famKey);

            return RefreshRotateResult::expired();
        }

        $spentPayload = [
            'family_id' => $familyId,
            'generation' => (int) $rec['generation'],
            'spent_at' => now()->getTimestamp(),
        ];

        // Cache::add() is atomic SET NX EX in a Redis-backed store: the
        // single winner of a concurrent race performs the rotation below;
        // the loser falls through to the D6 concurrency-grace check.
        if (! Cache::add($spentKey, $spentPayload, $this->ttlUntil($absoluteExpiresAt, floor: 60))) {
            $existingSpent = Cache::get($spentKey);

            return is_array($existingSpent)
                ? $this->resolveAgainstTombstone($existingSpent)
                : RefreshRotateResult::invalid();
        }

        Cache::forget($tokKey);

        return $this->issueNextGeneration($familyId, (int) $rec['user_id'], (int) $fam['generation'], $absoluteExpiresAt);
    }

    private function issueNextGeneration(string $familyId, int $userId, int $currentGeneration, int $absoluteExpiresAt): RefreshRotateResult
    {
        $nextGeneration = $currentGeneration + 1;
        $secret = $this->newSecret();

        $this->writeFamily($familyId, $userId, $nextGeneration, $absoluteExpiresAt);
        $this->writeTok($secret, $familyId, $nextGeneration, $userId, $absoluteExpiresAt);

        return RefreshRotateResult::rotated($userId, new RefreshTokenIssue($familyId, $secret, $absoluteExpiresAt));
    }

    /**
     * Reuse-vs-concurrent-duplicate decision (D6), shared by the "tok
     * already gone" path and the "lost the atomic Cache::add race" path.
     *
     * A concurrent duplicate iff the family is still alive, the tombstone is
     * younger than the grace window, AND its recorded generation is exactly
     * one behind the family's CURRENT generation — i.e. this presentation is
     * the immediate duplicate of the rotation that just happened, not a
     * stale replay from several rotations ago.
     */
    private function resolveAgainstTombstone(array $spent): RefreshRotateResult
    {
        $famKey = "refresh_family:{$spent['family_id']}";
        $fam = Cache::get($famKey);

        if (is_array($fam)) {
            $graceSeconds = (int) config('refresh_tokens.concurrency_grace_seconds');
            $ageSeconds = now()->getTimestamp() - (int) $spent['spent_at'];
            $withinGrace = $ageSeconds >= 0 && $ageSeconds <= $graceSeconds;
            $isImmediatelyPriorGeneration = (int) $spent['generation'] === ((int) $fam['generation'] - 1);

            if ($withinGrace && $isImmediatelyPriorGeneration) {
                Log::warning('refresh.concurrent_duplicate', [
                    'family_id' => $spent['family_id'],
                    'generation' => $spent['generation'],
                    'age_seconds' => $ageSeconds,
                ]);

                return RefreshRotateResult::concurrentDuplicate((int) $fam['user_id']);
            }
        }

        Log::warning('refresh.reused', [
            'family_id' => $spent['family_id'],
            'generation' => $spent['generation'],
        ]);

        Cache::forget($famKey);

        return RefreshRotateResult::reused();
    }

    private function writeFamily(string $familyId, int $userId, int $generation, int $absoluteExpiresAt): void
    {
        Cache::put(
            "refresh_family:{$familyId}",
            ['user_id' => $userId, 'generation' => $generation, 'absolute_expires_at' => $absoluteExpiresAt],
            $this->ttlUntil($absoluteExpiresAt, floor: 1) + self::TTL_BACKSTOP_BUFFER_SECONDS,
        );
    }

    private function writeTok(string $secret, string $familyId, int $generation, int $userId, int $absoluteExpiresAt): void
    {
        Cache::put(
            'refresh_tok:'.hash('sha256', $secret),
            ['family_id' => $familyId, 'generation' => $generation, 'user_id' => $userId],
            $this->ttlUntil($absoluteExpiresAt, floor: 1) + self::TTL_BACKSTOP_BUFFER_SECONDS,
        );
    }

    /**
     * Absolute-expiry TTL, mechanically: `absolute_expires_at - now`, NEVER a
     * re-assertion of the 14-day constant (D2/A4). $floor guards against a
     * zero/negative TTL being handed to a cache driver — callers that reach
     * this method have already confirmed `now < absolute_expires_at`.
     */
    private function ttlUntil(int $absoluteExpiresAt, int $floor): int
    {
        return max($absoluteExpiresAt - now()->getTimestamp(), $floor);
    }

    private function newSecret(): string
    {
        // 32 CSPRNG bytes, base64url — 256 bits of entropy, not
        // brute-forceable (D2: this is why hashing uses sha256, not bcrypt).
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
