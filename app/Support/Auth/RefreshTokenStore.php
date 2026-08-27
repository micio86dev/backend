<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\RefreshToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Database-backed refresh-token family store (backoffice-session-refresh-hardening
 * D2/D6, storage corrected from Redis to PostgreSQL — see design.md).
 *
 * Refresh tokens are durable authentication state, not cache: the SAME Redis
 * instance backs CACHE_STORE, QUEUE_CONNECTION and SESSION_DRIVER
 * (api/.env.example), so requiring `maxmemory-policy=noeviction` on it —
 * which the original Redis-backed design did — would make cache writes and
 * queue pushes fail the moment memory fills. A cache must stay evictable;
 * durable auth state belongs in the database, following the same shape
 * already used for `password_reset_tokens` (Laravel's own convention) and
 * Sanctum's `personal_access_tokens`.
 *
 * One row per issued generation (App\Models\RefreshToken), never mutated
 * into a different generation — rotation INSERTs a new row, it never
 * overwrites the old one:
 *   - `consumed_at`: stamped when THIS generation's secret was legitimately
 *     rotated away — the tombstone used by the two-tab concurrency-grace
 *     check (D6).
 *   - `revoked_at`: stamped when the WHOLE family is killed (reuse detected,
 *     logout, or the absolute ceiling was crossed) — permanent, independent
 *     of whether this row was ever consumed normally.
 *
 * Rotation and reuse detection are atomic by construction: every read that
 * decides the outcome happens inside DB::transaction() with
 * lockForUpdate() — the same convention already used throughout
 * App\Http\Controllers\Candidate\InterviewController — so a concurrent
 * presentation of the SAME secret blocks on the row lock rather than racing
 * it.
 *
 * Absolute expiry (A4) is mechanical: every row's `absolute_expires_at` is
 * stamped ONCE in issue() and copied unchanged through every rotation; every
 * TTL a caller derives from it is `absolute_expires_at - now`, NEVER a
 * re-assertion of the 14-day constant.
 */
final class RefreshTokenStore
{
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

        $this->createGeneration($familyId, $userId, generation: 0, secret: $secret, absoluteExpiresAt: $absoluteExpiresAt);

        return new RefreshTokenIssue($familyId, $secret, $absoluteExpiresAt);
    }

    /**
     * D2 rotation algorithm, steps 1-4b, with the D6 concurrency-grace
     * extension folded into both branches that can observe "already spent".
     *
     * Atomic: the presented row is locked (`lockForUpdate()`) inside a
     * single DB::transaction() — a concurrent presentation of the SAME
     * secret blocks on that lock and observes the winner's outcome, exactly
     * mirroring the Cache::add() SET-NX-EX atomicity the prior Redis-backed
     * implementation relied on.
     */
    public function rotate(string $familyId, string $secret): RefreshRotateResult
    {
        $hash = hash('sha256', $secret);

        return DB::transaction(function () use ($familyId, $hash): RefreshRotateResult {
            $presented = RefreshToken::where('token_hash', $hash)->lockForUpdate()->first();

            if ($presented === null) {
                // Unattributable hash — Step 4b is load-bearing: an unknown
                // token must NEVER revoke a family, or anyone able to POST
                // garbage could log families out at will.
                return RefreshRotateResult::invalid();
            }

            if ($presented->family_id !== $familyId) {
                // The family id travels in the token but is attacker-
                // controlled and is therefore cross-checked against the
                // stored row, never trusted — the hash is the authority.
                return RefreshRotateResult::invalid();
            }

            if ($presented->revoked_at !== null) {
                // The family was already killed (prior reuse-kill, logout,
                // or an earlier ceiling-expiry) — permanently dead,
                // regardless of whether THIS row was ever consumed.
                return RefreshRotateResult::revoked();
            }

            if ($presented->consumed_at !== null) {
                // Already rotated away — resolve concurrent-duplicate (D6)
                // vs genuine reuse against the family's current live row.
                // $consumedAt passed explicitly (not re-read off $presented
                // inside the callee) so PHPStan can see the non-null
                // narrowing just proved above; it cannot follow that
                // narrowing across a method call on a nullable property.
                return $this->resolveAgainstTombstone($presented, $presented->consumed_at);
            }

            // Live, unconsumed, not revoked — this IS the presentable row.
            if (now()->getTimestamp() >= $presented->absolute_expires_at->getTimestamp()) {
                $presented->revoked_at = now();
                $presented->save();

                return RefreshRotateResult::expired();
            }

            $presented->consumed_at = now();
            $presented->save();

            return $this->issueNextGeneration(
                $familyId,
                $presented->user_id,
                $presented->generation,
                $presented->absolute_expires_at->getTimestamp(),
            );
        });
    }

    /**
     * Kills every outstanding token in the family immediately (logout, or
     * the terminal step of a detected reuse). A single UPDATE, atomic by
     * construction — no read-then-write race to guard against.
     */
    public function revokeFamily(string $familyId): void
    {
        RefreshToken::where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Kills every family the USER holds — every device, every login.
     *
     * NOT a convenience wrapper over `revokeFamily()`, and the difference is
     * the point (self-service-password-reset AD-4). A user holds one family
     * PER LOGIN (`issue()` mints a fresh `familyId` each time), so a
     * two-device operator has two families. An out-of-session password reset
     * has no session at all, therefore no `fam` claim to scope by, and
     * family-scoped revocation would leave the other device logged in.
     *
     * This is the half of "a reset ends every session" that
     * `password_changed_at` cannot cover: `POST /api/auth/refresh` runs
     * OUTSIDE `RejectStaleCredentials` on purpose (routes/api.php, D8 — an
     * expired access token is exactly when refresh must still work), so
     * `password_changed_at` is never consulted there and a stolen refresh
     * cookie would otherwise keep minting fresh access tokens after a reset.
     *
     * `whereNull('revoked_at')` is not just an optimisation: re-stamping an
     * already-revoked row would move the recorded revocation time forward and
     * misdate the incident in the very row that records it.
     *
     * @return int The number of live rows revoked.
     */
    public function revokeAllForUser(int $userId): int
    {
        return RefreshToken::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function issueNextGeneration(string $familyId, int $userId, int $currentGeneration, int $absoluteExpiresAt): RefreshRotateResult
    {
        $nextGeneration = $currentGeneration + 1;
        $secret = $this->newSecret();

        $this->createGeneration($familyId, $userId, $nextGeneration, $secret, $absoluteExpiresAt);

        return RefreshRotateResult::rotated($userId, new RefreshTokenIssue($familyId, $secret, $absoluteExpiresAt));
    }

    /**
     * Reuse-vs-concurrent-duplicate decision (D6), reached when the
     * presented row was already legitimately consumed by a prior rotation.
     *
     * A concurrent duplicate iff the family still has a live (unconsumed,
     * unrevoked) row, the presented row's `consumed_at` is younger than the
     * grace window, AND its generation is exactly one behind the live row's
     * — i.e. this presentation is the immediate duplicate of the rotation
     * that just happened, not a stale replay from several rotations ago.
     * Every other case revokes the whole family (Step: reuse kill).
     */
    private function resolveAgainstTombstone(RefreshToken $presented, Carbon $consumedAt): RefreshRotateResult
    {
        $liveRow = RefreshToken::where('family_id', $presented->family_id)
            ->whereNull('revoked_at')
            ->whereNull('consumed_at')
            ->lockForUpdate()
            ->first();

        if ($liveRow !== null) {
            $graceSeconds = (int) config('refresh_tokens.concurrency_grace_seconds');
            $ageSeconds = now()->getTimestamp() - $consumedAt->getTimestamp();
            $withinGrace = $ageSeconds >= 0 && $ageSeconds <= $graceSeconds;
            $isImmediatelyPriorGeneration = $presented->generation === $liveRow->generation - 1;

            if ($withinGrace && $isImmediatelyPriorGeneration) {
                Log::warning('refresh.concurrent_duplicate', [
                    'family_id' => $presented->family_id,
                    'generation' => $presented->generation,
                    'age_seconds' => $ageSeconds,
                ]);

                return RefreshRotateResult::concurrentDuplicate($liveRow->user_id, $presented->family_id);
            }
        }

        Log::warning('refresh.reused', [
            'family_id' => $presented->family_id,
            'generation' => $presented->generation,
        ]);

        $this->revokeFamily($presented->family_id);

        return RefreshRotateResult::reused();
    }

    private function createGeneration(string $familyId, int $userId, int $generation, string $secret, int $absoluteExpiresAt): void
    {
        RefreshToken::create([
            'user_id' => $userId,
            'family_id' => $familyId,
            'token_hash' => hash('sha256', $secret),
            'generation' => $generation,
            'absolute_expires_at' => $absoluteExpiresAt,
        ]);
    }

    private function newSecret(): string
    {
        // 32 CSPRNG bytes, base64url — 256 bits of entropy, not
        // brute-forceable (D2: this is why hashing uses sha256, not bcrypt).
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
