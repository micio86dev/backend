<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `content_version` (framework-catalogue-authoring PR3b, gga review finding
 * on H5's `DiscardUnusedDraftRevision` — blocking, third pass): the original
 * "is this draft untouched" check compared per-table ROW COUNTS between the
 * draft and its parent, which is invariant under `UPDATE` — a concurrent
 * request renaming a role in the draft (the "ordinary concurrent traffic"
 * path the class's own docblock names) changes nothing a count can see, so
 * the guard would still discard a draft carrying real, committed work.
 *
 * A real per-revision mutation counter closes that gap: every Eloquent
 * write (create/update/delete) to `Role`/`Competency`/`BarsIndicator`
 * bumps its revision's `content_version` (see each model's `booted()`).
 * `OpenDraftRevision`'s own clone step writes via raw `DB::table()->insert()`
 * — no Eloquent event fires — so a freshly-cloned draft starts and stays at
 * `0` until a REAL write (insert, update, OR delete) reaches it through the
 * catalogue's actual CRUD surface. `DiscardUnusedDraftRevision` then asks
 * exactly one question: "is this still `0`?" — true "genuinely
 * untouched", false "something wrote here, leave it".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('framework_catalog_revisions', function (Blueprint $table): void {
            $table->unsignedInteger('content_version')->default(0)->after('parent_revision_id');
        });
    }

    public function down(): void
    {
        Schema::table('framework_catalog_revisions', function (Blueprint $table): void {
            $table->dropColumn('content_version');
        });
    }
};
