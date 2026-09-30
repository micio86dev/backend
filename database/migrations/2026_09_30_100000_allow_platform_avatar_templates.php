<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Platform (global) avatar templates: `organization_id IS NULL` means the row
 * belongs to the platform, not to a tenant.
 *
 * ROLLING-DEPLOY SAFE. `DROP NOT NULL` is catalog-only (no table rewrite or
 * scan) and takes ACCESS EXCLUSIVE for an instant; `lock_timeout` makes a lock
 * wait abort the deploy quickly instead of queueing interview-start reads
 * behind it. The unique index is built on a tens-of-rows table, so a plain
 * (non-concurrent) build inside the migration transaction holds its lock for
 * milliseconds. Old code keeps working against the new schema: no NULL row
 * exists until new code creates one, and the old strict tenant scope
 * (`organization_id = X`) never matches NULL.
 *
 * The foreign key to `organizations` (ON DELETE CASCADE) is unchanged: a NULL
 * foreign key is never checked and never cascaded (MATCH SIMPLE), so deleting
 * an organization never touches a platform row. The per-organization unique
 * indexes are unchanged too; NULLs are distinct there, which is what lets
 * several platform templates of one provider be active together.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        DB::statement('ALTER TABLE avatar_templates ALTER COLUMN organization_id DROP NOT NULL');

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS avatar_templates_global_name_unique
             ON avatar_templates (name)
             WHERE organization_id IS NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        // Soft-deleted rows count: SET NOT NULL fails on ANY NULL, trashed or not.
        $count = DB::table('avatar_templates')->whereNull('organization_id')->count();

        if ($count > 0) {
            throw new RuntimeException(
                "Refusing to restore NOT NULL: {$count} platform avatar template row(s) exist "
                .'(soft-deleted included). Follow the rollback runbook first.'
            );
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('DROP INDEX IF EXISTS avatar_templates_global_name_unique');
        DB::statement('ALTER TABLE avatar_templates ALTER COLUMN organization_id SET NOT NULL');
    }
};
