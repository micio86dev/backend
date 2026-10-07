<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger behind `HeygenVoiceRegistrar` (heygen-third-party-voices H2).
 *
 * Two PLATFORM tables, deliberately without `organization_id`: a bound voice is
 * BEAI's own LiveAvatar resource, made with BEAI's platform vendor keys and
 * shared by every template that names it (owner decision 2026-10-03). Neither
 * is read through any tenant surface.
 *
 * `heygen_bound_voices` is the idempotency of the bind. LiveAvatar's
 * `POST /v1/voices/third_party` is NOT idempotent (live 2026-10-03: three
 * identical binds returned three different voice ids), so the only thing that
 * stops a second bind is a row here, keyed `(engine, provider_voice_id)` with a
 * UNIQUE index. `voice_id` is NOT NULL: a row exists only once LiveAvatar
 * answered with an id, so there is no "claimed, not bound" half-state to
 * recover from.
 *
 * `heygen_vendor_secrets` memoises ONE LiveAvatar secret per vendor (`engine`
 * UNIQUE): `secret_name` is not unique on the vendor side, so a second POST
 * silently orphans a secret.
 *
 * A new, empty table is an ordinary transactional create; `down()` drops both.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('heygen_vendor_secrets')) {
            Schema::create('heygen_vendor_secrets', function (Blueprint $table): void {
                $table->id();
                $table->string('engine', 32)->unique();
                $table->string('secret_id', 64);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('heygen_bound_voices')) {
            Schema::create('heygen_bound_voices', function (Blueprint $table): void {
                $table->id();
                $table->string('engine', 32);
                $table->string('provider_voice_id', 128);
                $table->string('secret_id', 64);
                $table->string('voice_id', 64)->unique();
                $table->timestamps();

                $table->unique(['engine', 'provider_voice_id'], 'heygen_bound_voices_engine_voice_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('heygen_bound_voices');
        Schema::dropIfExists('heygen_vendor_secrets');
    }
};
