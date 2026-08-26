<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens the "one active template" invariant from per-organization to
 * per-(organization, provider) (pluggable-conversation-llm PR P0, design D0).
 *
 * `avatar_templates_one_active_per_org` capped an organization at exactly one
 * active template ACROSS ALL PROVIDERS. Provider is chosen per project, not
 * per organization: an organization running one project on HeyGen and
 * another on Tavus needs two SIMULTANEOUSLY active templates, one per
 * provider, and the narrower index made that unconfigurable. Widening the
 * index alone — without also narrowing `ActiveTemplateResolver::resolve()`
 * and `AvatarTemplateController::activate()`'s deactivate query to the same
 * provider (both done in this same PR) — would reproduce the cross-provider
 * leakage bug one layer up, so all three edits ship together.
 *
 * `down()` re-narrows. This is the ONE step in the whole change with a data
 * precondition on rollback: it FAILS if any organization has activated two
 * templates on different providers under the wider index, because a
 * unique-violation insert is not a thing a migration can silently paper over.
 * That is documented and accepted (design.md "Migration / Rollout") — this is
 * a genuine invariant narrowing, not a bug fix, so a clean revert requires
 * the data to already satisfy the narrower rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX avatar_templates_one_active_per_org');

        DB::statement(
            'CREATE UNIQUE INDEX avatar_templates_one_active_per_org_provider
             ON avatar_templates (organization_id, provider)
             WHERE is_active'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX avatar_templates_one_active_per_org_provider');

        DB::statement(
            'CREATE UNIQUE INDEX avatar_templates_one_active_per_org
             ON avatar_templates (organization_id)
             WHERE is_active'
        );
    }
};
