<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The primary-question snapshot a session's system prompt and opening
 * greeting were composed from, plus the follow-up budget that accompanied
 * it (framework-catalogue-authoring PR7, design D7/D8).
 *
 * Written in the `/start` request that composed the prompt, and MAY be
 * refreshed by a later `/start` on the SAME session (a resume, or a retry
 * re-offer) that composes a fresh prompt from the current `project_questions`
 * state — but only while the session has recorded no transcript yet.
 * `TurnClassifier` reads `primary_questions` as the fixed reference an
 * avatar turn is matched against; once a turn exists to compare against it,
 * the reference must stop moving, or turns matched against an earlier list
 * are silently re-indexed against a different one.
 *
 * Both columns are nullable: every row that exists at migration time was
 * created before this snapshot existed, and there is no prior value to
 * backfill — a session composed under the old dual-channel wiring has no
 * primary-questions snapshot to reconstruct after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table): void {
            $table->jsonb('primary_questions')->nullable();
            $table->integer('follow_up_budget')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table): void {
            $table->dropColumn(['primary_questions', 'follow_up_budget']);
        });
    }
};
