<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `refresh_tokens` table (backoffice-session-refresh-hardening, storage
 * corrected from Redis to PostgreSQL — see design.md).
 *
 * Refresh tokens are durable authentication state, not cache: the same shared
 * Redis instance backs CACHE_STORE, QUEUE_CONNECTION and SESSION_DRIVER
 * (api/.env.example), so requiring `maxmemory-policy=noeviction` on it would
 * make cache writes and queue pushes fail loudly the moment memory fills — a
 * cache must stay evictable. This follows the Laravel-standard shape already
 * used for `password_reset_tokens` (0001_01_01_000000_create_users_table.php)
 * and by `personal_access_tokens` — cited as shape precedent only, because
 * this project's auth is JWT (tymon/jwt-auth) and does not use Sanctum:
 * durable credential state lives in the database, hashed at rest, with a
 * scheduled prune of dead rows.
 *
 * One row per issued generation (never mutated into a different generation —
 * a rotation INSERTs a new row, it does not overwrite the old one), which is
 * what lets App\Support\Auth\RefreshTokenStore distinguish, atomically under
 * `lockForUpdate()`:
 *   - `consumed_at` (nullable): stamped when THIS generation's secret was
 *     legitimately rotated away — the tombstone used for the two-tab
 *     concurrency-grace check (superseded-but-recent, still the same
 *     immediately-prior generation) vs a genuine replay.
 *   - `revoked_at` (nullable): stamped when the WHOLE family is killed
 *     (reuse detected, logout, or the absolute ceiling was crossed) —
 *     permanent, independent of whether this row was ever consumed normally.
 *
 * `token_hash` stores ONLY sha256(secret) — the raw secret is never
 * persisted anywhere, mirroring the jti-denylist convention already used by
 * App\Support\Jwt\CandidateTokenFactory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Shared by every generation of the same rotation chain.
            $table->uuid('family_id');

            // sha256(secret) — 64 lowercase hex chars. The raw secret is
            // NEVER stored; this is the sole lookup key for rotate().
            $table->string('token_hash', 64)->unique();

            $table->unsignedInteger('generation');

            // Copied UNCHANGED from generation 0 through every rotation —
            // ABSOLUTE ceiling, never a sliding re-stamp. Every TTL/expiry
            // check the store performs is `absolute_expires_at - now()`,
            // never a re-assertion of the config constant.
            $table->timestampTz('absolute_expires_at');

            // Set once, when this generation's secret is legitimately
            // rotated away (concurrency-grace tombstone). Distinct from
            // revoked_at — see class doc above.
            $table->timestampTz('consumed_at')->nullable();

            // Set once, when the whole family is killed (reuse, logout, or
            // ceiling expiry). A row with revoked_at set is permanently dead
            // regardless of consumed_at.
            $table->timestampTz('revoked_at')->nullable();

            $table->timestamps();

            // rotate()'s lookup path: find the presented secret's row.
            $table->index('family_id');

            // Prune's cleanup path: dead rows past their ceiling or killed.
            $table->index(['absolute_expires_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
