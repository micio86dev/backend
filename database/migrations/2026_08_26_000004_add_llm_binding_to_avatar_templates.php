<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Five binding columns on `avatar_templates`, a CHECK, and a one-way
 * `config` strip (pluggable-conversation-llm PR P3a, design D3).
 *
 * `llm_model_id` / `llm_credential_id` are FKs, never `config` jsonb keys —
 * jsonb carries no referential integrity, and "which templates use this
 * credential?" (D2's 409) must be queryable. `restrictOnDelete` on both: a
 * model or credential in use cannot be silently deleted out from under a
 * bound template.
 *
 * `llm_sync_status` / `llm_synced_at` are the TAVUS half of the orphan
 * ledger (design C2). HeyGen persists its own outcome for free —
 * `heygen_llm_configuration_id` is non-null iff registration succeeded —
 * but `TavusPalSync::sync()` returns a transient array and persists
 * nothing. Without these two columns, a Tavus push that failed is knowable
 * only to the operator who happened to read the warning banner, and
 * `degraded` (design D0) is unreachable on the Tavus path. NULL by default,
 * and NULL is not `'synced'` — every path that never pushed (a portability
 * import, a seeder-written row, a save whose PATCH timed out) fails CLOSED.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded so `up()` is safe to invoke a SECOND time — the fixture
        // that verifies the strip below (mirroring
        // `StripTemplateLanguageMigrationTest.php`'s "run the REAL
        // migration's up(), not a copy of its SQL") needs to re-run this
        // method against seeded data, and unlike that migration this one
        // also adds columns, which is not naturally idempotent.
        if (! Schema::hasColumn('avatar_templates', 'llm_model_id')) {
            Schema::table('avatar_templates', function (Blueprint $table): void {
                $table->foreignId('llm_model_id')->nullable()
                    ->constrained('llm_models')->restrictOnDelete();

                $table->foreignId('llm_credential_id')->nullable()
                    ->constrained('llm_credentials')->restrictOnDelete();

                $table->string('heygen_llm_configuration_id')->nullable();

                // 'synced' | 'failed' | 'not_required'. NULL means "never
                // pushed" — a different fact from 'failed', and both resolve
                // to `degraded` at the resolver (design D0).
                $table->string('llm_sync_status')->nullable();
                $table->timestampTz('llm_synced_at')->nullable();

                $table->index(['organization_id', 'llm_credential_id']);
            });

            DB::statement(
                'ALTER TABLE avatar_templates ADD CONSTRAINT avatar_templates_llm_binding_both_or_neither_check
                 CHECK ((llm_model_id IS NULL) = (llm_credential_id IS NULL))'
            );
        }

        // `??` — DOUBLED — is the escaped form of Postgres's `?` key-exists
        // operator. A SINGLE `?` is consumed by PDO as a parameter
        // placeholder before Postgres ever sees it, and this statement would
        // die with SQLSTATE[HY093] mid-deploy on a ONE-WAY, IRREVERSIBLE data
        // strip. Verbatim from
        // 2026_08_20_140000_strip_language_from_avatar_templates_config.php,
        // whose docblock states this in the same words. `llmModel` moves to
        // the real `llm_model_id` FK above; the old Tavus select for it is
        // removed from `ProviderFieldSpecs::tavus()` in this same PR, and
        // both writers targeting the same PAL path (`layers/llm/model`)
        // would otherwise silently race, last writer wins.
        DB::statement("UPDATE avatar_templates SET config = config - 'llmModel' WHERE config ?? 'llmModel'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE avatar_templates DROP CONSTRAINT IF EXISTS avatar_templates_llm_binding_both_or_neither_check');

        Schema::table('avatar_templates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('llm_model_id');
            $table->dropConstrainedForeignId('llm_credential_id');
            $table->dropColumn(['heygen_llm_configuration_id', 'llm_sync_status', 'llm_synced_at']);
        });

        // The `llmModel` strip is a documented no-op on rollback — see the
        // class docblock. The values are gone; restoring them means
        // re-writing the key (currently exactly one demo row,
        // DemoWriter.php), i.e. a `beai:demo-seed` re-run.
    }
};
