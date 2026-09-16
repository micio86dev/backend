<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguishes a primary-question avatar turn from a follow-up turn in the
 * transcript (framework-catalogue-authoring PR7, design D8).
 *
 * Nullable, values `primary` | `follow_up`, set only on `speaker = 'avatar'`
 * rows. Every row that exists at migration time was written before
 * `TurnClassifier` existed and carries no classification — NULL is that
 * honest absence, never backfilled to a guess. A CHECK constraint pins the
 * closed set (`framework_catalog_revisions.state`'s own pattern, same PR
 * sequence): `TurnClassifier`'s audit counts rows by exact string equality
 * against `'primary'`, so a typo or a third value written outside the
 * classifier would silently undercount without one. NULL always satisfies a
 * CHECK, so a `candidate`-speaker row (never classified) needs no
 * exemption clause.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utterances', function (Blueprint $table): void {
            $table->string('turn_kind')->nullable();
        });

        DB::statement(
            "ALTER TABLE utterances
             ADD CONSTRAINT utterances_turn_kind_check CHECK (turn_kind IN ('primary', 'follow_up'))"
        );
    }

    public function down(): void
    {
        Schema::table('utterances', function (Blueprint $table): void {
            $table->dropColumn('turn_kind');
        });
    }
};
