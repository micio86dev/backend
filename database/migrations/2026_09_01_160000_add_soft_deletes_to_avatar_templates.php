<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete avatar templates, matching `projects`.
 *
 * A template is referenced by `interview_sessions.avatar_template_id` and by
 * every historical cost row, so a hard delete either breaks those references or
 * takes the history with it. `projects` already solved this the same way.
 *
 * TWO UNIQUE INDEXES HAVE TO LEARN ABOUT `deleted_at`, and this is the part a
 * plain `softDeletes()` call would miss:
 *
 *   - `(organization_id, name)` — a deleted template would keep its name
 *     reserved forever, so an operator could never recreate one they removed by
 *     mistake. `projects` hit exactly this and its slug index is partial for
 *     the same reason.
 *
 *   - `(organization_id, provider) WHERE is_active` — a deleted row that was
 *     active would keep occupying the one active slot for its provider, making
 *     it impossible to activate any replacement. Deleting the active template
 *     is already refused by the controller, but an index that depends on a
 *     controller check is an index waiting to be surprised.
 *
 * The FK from `projects` stays RESTRICT and is now belt to the controller's
 * braces rather than the only guard: a soft delete leaves the row in place, so
 * the constraint no longer fires. `AvatarTemplateController::destroy()` refuses
 * with `template_in_use` before reaching here, which is what actually protects
 * a live project — and it returns a readable 409 rather than a constraint name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avatar_templates', function (Blueprint $table): void {
            $table->softDeletes();
        });

        // A CONSTRAINT, not a bare index — Laravel's `unique()` creates it that
        // way, and Postgres refuses `DROP INDEX` on an index a constraint owns.
        // A partial unique cannot be expressed as a constraint at all, which is
        // why it comes back as a plain index.
        DB::statement('ALTER TABLE avatar_templates DROP CONSTRAINT IF EXISTS avatar_templates_organization_id_name_unique');
        DB::statement('DROP INDEX IF EXISTS avatar_templates_organization_id_name_unique');
        DB::statement(
            'CREATE UNIQUE INDEX avatar_templates_organization_id_name_unique
             ON avatar_templates (organization_id, name) WHERE deleted_at IS NULL'
        );

        DB::statement('DROP INDEX IF EXISTS avatar_templates_one_active_per_org_provider');
        DB::statement(
            'CREATE UNIQUE INDEX avatar_templates_one_active_per_org_provider
             ON avatar_templates (organization_id, provider) WHERE is_active AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS avatar_templates_organization_id_name_unique');
        DB::statement('DROP INDEX IF EXISTS avatar_templates_one_active_per_org_provider');

        Schema::table('avatar_templates', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });

        // Restored as the CONSTRAINT it originally was, not as an index.
        DB::statement(
            'ALTER TABLE avatar_templates ADD CONSTRAINT avatar_templates_organization_id_name_unique
             UNIQUE (organization_id, name)'
        );
        DB::statement(
            'CREATE UNIQUE INDEX avatar_templates_one_active_per_org_provider
             ON avatar_templates (organization_id, provider) WHERE is_active'
        );
    }
};
