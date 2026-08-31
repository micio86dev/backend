<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add nullable `avatar_template_id` to `projects` — per-project avatar template.
 *
 * Before this column, a project had no say in WHICH template it ran on. The
 * provider came from `provider_override` or the `INTERVIEW_PROVIDER` env
 * default, and `ActiveTemplateResolver` then returned the organization's ONE
 * active template for that provider. Two projects on the same provider could
 * therefore never use different templates, and an operator holding one active
 * HeyGen and one active Tavus template — a legal state, since activation is
 * scoped per provider — had no way to say which one a given project used.
 *
 * NULLABLE, deliberately. The organization-wide active template stays the
 * fallback, so every existing project keeps behaving exactly as it did and no
 * backfill is required. A project opts IN to pinning; it is never forced to.
 *
 * `nullOnDelete`, not `cascadeOnDelete`: deleting a template must not delete
 * the projects that referenced it — it must return them to the org-active
 * fallback, which is a working state rather than data loss.
 *
 * The index leads with `organization_id` per the project's multi-tenancy rule
 * (CLAUDE.md), so the lookup stays tenant-scoped rather than scanning every
 * organization's projects for a template id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('avatar_template_id')
                ->nullable()
                ->after('provider_override')
                ->constrained('avatar_templates')
                ->nullOnDelete();

            $table->index(['organization_id', 'avatar_template_id'], 'projects_org_avatar_template_index');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex('projects_org_avatar_template_index');
            $table->dropConstrainedForeignId('avatar_template_id');
        });
    }
};
