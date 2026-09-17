<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `parent_revision_id` on `framework_catalog_revisions` (framework-
 * catalogue-authoring PR3, D3 — the cross-role duplicate DELTA check).
 *
 * `OpenDraftRevision` clones the latest published revision into a new draft;
 * `PublishRevision::violations()` needs to know WHICH published revision a
 * draft was cloned from to compare anchor text against it — "refuse only a
 * cross-role duplicate NEW to this revision" is meaningless without a
 * concrete parent to diff against. Nullable: the baseline has no parent (it
 * is the root), and every revision that predates this column stays that way.
 * Self-referential FK, `nullOnDelete` — a parent's later deletion (never
 * legal for a published, referenced revision in practice, but not this
 * column's job to prevent) must not cascade into orphaning its children's
 * own rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('framework_catalog_revisions', function (Blueprint $table): void {
            $table->foreignId('parent_revision_id')->nullable()->after('is_baseline')
                ->constrained('framework_catalog_revisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('framework_catalog_revisions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_revision_id');
        });
    }
};
