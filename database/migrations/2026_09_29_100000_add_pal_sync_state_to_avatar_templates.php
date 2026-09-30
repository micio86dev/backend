<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The LAST Tavus PAL sync outcome, persisted on the template.
 *
 * `TavusPalSync::sync()` returned a transient array and stored nothing, so a
 * Tavus refusal (HTTP 400 "Invalid persona_id" for a persona the account may
 * not edit) reached only whoever read that one response. The candidate then
 * heard a different voice with no trace of why.
 *
 * These columns are deliberately NOT `llm_sync_status` / `llm_synced_at`. Those
 * mean "the managed-LLM BINDING reached the vendor" and gate billing
 * (`LlmBindingResolver`); this records whether the PERSONA KNOBS did. Two
 * questions with two answers must not share a column.
 *
 *  - `pal_sync_status`: `synced` | `skipped` | `warning`. NULL = never synced
 *    (and every non-Tavus template).
 *  - `pal_sync_code`: the stable machine code behind a `warning`
 *    (`pal_not_editable`, `pal_sync_failed`, ...). NEVER a provider message.
 *  - `pal_synced_at`: when the persona was last SUCCESSFULLY synced; a later
 *    failure leaves it alone, so the operator can read "last worked on X".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('avatar_templates', 'pal_sync_status')) {
            return;
        }

        Schema::table('avatar_templates', function (Blueprint $table): void {
            $table->string('pal_sync_status', 16)->nullable();
            $table->string('pal_sync_code', 64)->nullable();
            $table->timestampTz('pal_synced_at')->nullable();
        });

        DB::statement(
            "ALTER TABLE avatar_templates ADD CONSTRAINT avatar_templates_pal_sync_status_check
             CHECK (pal_sync_status IS NULL OR pal_sync_status IN ('synced', 'skipped', 'warning'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE avatar_templates DROP CONSTRAINT IF EXISTS avatar_templates_pal_sync_status_check');

        Schema::table('avatar_templates', function (Blueprint $table): void {
            $table->dropColumn(['pal_sync_status', 'pal_sync_code', 'pal_synced_at']);
        });
    }
};
