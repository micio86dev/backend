<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LLM credentials become PLATFORM rows, owned by BEAI (RATIFIED 2026-09-14).
 *
 * This REVERSES design D2 of pluggable-conversation-llm — "an organization's
 * own bring-your-own Google Gemini key" — and the reversal is a product
 * decision, not a refactor: the key is BEAI's now, so the token spend is
 * BEAI's too. `llm_models` was already global on exactly this reasoning ("a
 * rate card is a vendor fact, not an organization's"); the credential that
 * pays for those models now sits beside it.
 *
 * DESTRUCTIVE, and deliberately so. Ratified disposal: keep the MOST RECENTLY
 * CREATED Google credential and delete every other row.
 *
 * Two obstacles make this more than a DELETE, and both are load-bearing:
 *
 * 1. `avatar_templates.llm_credential_id` references this table with ON DELETE
 *    RESTRICT, so a bound template blocks the delete outright. Every surviving
 *    binding is REPOINTED at the survivor first — which is what "global" means:
 *    one key, every tenant.
 *
 * 2. `AvatarTemplate::saving()` I4 refuses a binding whose credential vendor
 *    differs from its model's. Every row in `database/seeders/data/llm_models.php`
 *    is `vendor => 'google'`, so repointing at a Google survivor is
 *    vendor-consistent by construction — but this migration does not TRUST that,
 *    because a registry is data and data changes. A template bound to a model of
 *    any other vendor is UNBOUND instead (both columns together, since I1's CHECK
 *    refuses a half-bound row), which leaves a row the application can still save.
 *    Silently repointing it would write a row that every later `save()` rejects.
 *
 * NOT handled here, on purpose: `heygen_secret_id`. Deleting these rows orphans
 * the matching `/v1/secrets` objects at HeyGen. A migration must not make HTTP
 * calls — it runs inside a deploy, against a vendor that can be down, and a
 * failed cleanup would roll back a schema change over a remote 500. The orphans
 * are inert (nothing references them once the row is gone) and are a manual
 * console cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        $survivorId = $this->survivorId();

        if ($survivorId !== null) {
            $this->repointBindings($survivorId);

            DB::table('llm_credentials')->where('id', '!=', $survivorId)->delete();
        } else {
            // No Google credential to keep. Unbind everything, then empty the
            // table — the operator re-creates the one platform key by hand.
            // The SAME three columns the repoint below tears down, for the
            // same reason: `heygen_llm_configuration_id` names a configuration
            // built at HeyGen from a credential about to be deleted, and
            // `llm_sync_status = 'synced'` asserts a push that no longer
            // corresponds to anything. `resolveStatus()` answers `Unbound`
            // here so it is not billable — but a stale ledger id is precisely
            // the orphan D8 exists to prevent, and a row that lies is a row
            // somebody will believe.
            DB::table('avatar_templates')
                ->whereNotNull('llm_credential_id')
                ->update([
                    'llm_model_id' => null,
                    'llm_credential_id' => null,
                    'heygen_llm_configuration_id' => null,
                    'llm_sync_status' => null,
                    'llm_synced_at' => null,
                ]);

            DB::table('llm_credentials')->delete();
        }

        Schema::table('llm_credentials', function (Blueprint $table): void {
            // Both indexes lead with the column about to disappear, so they go
            // first — Postgres will not drop a column an index still spans.
            $table->dropUnique(['organization_id', 'name']);
            $table->dropIndex(['organization_id', 'heygen_secret_id']);
            $table->dropConstrainedForeignId('organization_id');

            // A name is now unique across the PLATFORM, which is the whole
            // point: `AvatarTemplatePortabilityController` resolves a
            // credential by name on import, and that lookup used to be
            // disambiguated by the tenant scope it no longer has.
            $table->unique('name');
            $table->index('heygen_secret_id');
        });

        // The index this change MAKES NECESSARY, added in the same breath as
        // the ones it drops.
        //
        // `avatar_templates.llm_credential_id` was covered only by
        // `(organization_id, llm_credential_id)`, and Postgres does not
        // auto-index a foreign key. Three lookups now query that column with
        // NO `organization_id` in the predicate — `LlmCredentialController::
        // destroy()`'s in-use guard, `ResyncCredentialBindings`' platform-wide
        // sweep, and `repointBindings()` above — and a btree
        // whose leading column is absent from the predicate cannot serve any
        // of them. Without this, every credential delete and every key
        // rotation is a sequential scan.
        //
        // The composite is deliberately LEFT IN PLACE: `avatar_templates` is
        // still tenant-scoped, so the org-leading lookups it serves are
        // unaffected by any of this.
        Schema::table('avatar_templates', function (Blueprint $table): void {
            $table->index('llm_credential_id');
        });
    }

    /**
     * The most recently created Google credential, or null when there is none.
     *
     * Ordered by `id` rather than `created_at`: the ids are a monotonic
     * sequence, while `created_at` is second-precision and two rows written in
     * the same second would make "the last one" ambiguous — on a disposal step
     * that keeps exactly one row and deletes the rest.
     */
    private function survivorId(): ?int
    {
        $row = DB::table('llm_credentials')
            ->where('vendor', 'google')
            ->orderByDesc('id')
            ->first(['id']);

        return $row === null ? null : (int) $row->id;
    }

    /**
     * Point every bound template at the survivor, unbinding the ones whose
     * model vendor would violate I4.
     */
    private function repointBindings(int $survivorId): void
    {
        $survivorVendor = DB::table('llm_credentials')
            ->where('id', $survivorId)
            ->value('vendor');

        // Unbind first: a template whose model vendor does not match the
        // survivor cannot be repointed without writing a row the application
        // refuses to save.
        DB::table('avatar_templates')
            ->whereNotNull('llm_credential_id')
            ->whereNotIn(
                'llm_model_id',
                DB::table('llm_models')->where('vendor', $survivorVendor)->select('id')
            )
            ->update([
                'llm_model_id' => null,
                'llm_credential_id' => null,
                // Same teardown as the repoint below — an unbound row must not
                // keep a ledger id or a `synced` stamp from a binding it no
                // longer has.
                'heygen_llm_configuration_id' => null,
                'llm_sync_status' => null,
                'llm_synced_at' => null,
            ]);

        // Everything still bound is vendor-compatible — repoint it, and TEAR
        // DOWN the sync state in the same statement.
        //
        // The three extra columns are not tidiness. A repointed template keeps
        // `heygen_llm_configuration_id`, and that configuration was built at
        // HeyGen from the DELETED credential's `secret_id` — which this
        // migration deliberately leaves orphaned at the vendor. So the row
        // would go on reading `llm_sync_status = 'synced'`, which
        // `LlmBindingResolver::resolveStatus()` reports as `Applied`: the one
        // state its docblock calls billable, asserted about a key the template
        // is no longer bound to.
        //
        // Nulling them lands the row in `Degraded` instead, which is the
        // honest answer until something actually pushes. The next save, or the
        // next rotation of the survivor, re-creates the configuration against
        // the surviving secret and stamps `synced` for real.
        DB::table('avatar_templates')
            ->whereNotNull('llm_credential_id')
            ->where('llm_credential_id', '!=', $survivorId)
            ->update([
                'llm_credential_id' => $survivorId,
                'heygen_llm_configuration_id' => null,
                'llm_sync_status' => null,
                'llm_synced_at' => null,
            ]);
    }

    /**
     * Schema-only reversal.
     *
     * The rows this migration deleted are GONE — `api_key` is encrypted at
     * rest and was never copied anywhere, so no down() could bring them back,
     * and a down() that silently restores the column while leaving the data
     * destroyed would be a lie told in SQL. The column comes back NULLABLE for
     * the same reason: the surviving row has no organization to belong to, and
     * a NOT NULL column with no value to put in it cannot be added at all.
     */
    public function down(): void
    {
        Schema::table('avatar_templates', function (Blueprint $table): void {
            $table->dropIndex(['llm_credential_id']);
        });

        Schema::table('llm_credentials', function (Blueprint $table): void {
            $table->dropUnique(['name']);
            $table->dropIndex(['heygen_secret_id']);

            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'heygen_secret_id']);
        });
    }
};
