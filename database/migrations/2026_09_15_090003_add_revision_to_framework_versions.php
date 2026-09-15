<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `revision_id` to `framework_versions` (framework-catalogue-authoring
 * PR1, D1).
 *
 * NULLABLE, deliberately — unlike the four catalog-content tables.
 * `framework-catalog` spec: "revision_id (FK to framework_catalog_revisions,
 * set when the version is resolved)". A `FrameworkVersion` is a tenant's pin;
 * plenty of existing tests and application flows create one without ever
 * caring which catalogue revision it resolves to, and giving this column a
 * DEFAULT (the way the four catalog tables do) would silently repoint every
 * one of those unscoped creations at a real revision they never asked to
 * pin — a materially different, wider behaviour change than "add a column".
 * NULL correctly states "not resolved to anything" for a `FrameworkVersion`
 * that has never named a revision, independent of what state the baseline
 * happens to be in.
 *
 * `restrictOnDelete()` — a revision with a pin on it must not be deletable
 * out from under that pin (mirrors `projects.framework_version_id`'s own
 * `restrictOnDelete`).
 *
 * The backfill migration (next) stamps every EXISTING `framework_versions`
 * row with the baseline revision's id via a raw UPDATE, bypassing
 * `FrameworkVersion::booted()`'s "refuse a draft target" guard (task 2.8) —
 * that migration also publishes the baseline in the same breath, so the
 * historical stamp and the guard agree rather than merely avoiding each
 * other: every pre-existing row ends up pointing at a genuinely `published`
 * revision, exactly what the guard would have allowed had it run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('framework_versions', function (Blueprint $table): void {
            $table->foreignId('revision_id')->nullable()->after('organization_id')
                ->constrained('framework_catalog_revisions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('framework_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('revision_id');
        });
    }
};
