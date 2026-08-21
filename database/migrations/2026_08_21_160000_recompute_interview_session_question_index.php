<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recompute `interview_sessions.question_index` from
 * `project_competencies.position` (interview-question-index-offset, D2/D3/D4).
 *
 * THE CRUX. Production is MIXED: 29 sessions already carry the correct value
 * (`DemoWriter.php:404` never subtracted), 8 carry a defect-shaped drift (`-1`,
 * `(0,1)`, `(1,2)`). A blanket `UPDATE ... SET question_index = question_index + 1`
 * repairs the 8 and CORRUPTS the 29 — and corrupts them into values that remain
 * plausible, so nothing downstream would ever raise. Two of the eight drifted rows
 * are indistinguishable from correct rows by their own value alone; only a join
 * back to the authoritative source can tell them apart. This migration therefore
 * RECOMPUTES from `project_competencies.position`, never shifts the existing value.
 *
 * The join: `framework_competencies.code` is globally UNIQUE
 * (`…111646_create_competencies_table.php:21`), and `project_competencies` carries
 * `UNIQUE(project_id, competency_id)`, so at most one `position` matches one
 * session. This is the same join `ProgressPayloadAssembler:46-51` performs at read
 * time.
 *
 * `IS DISTINCT FROM` makes idempotency and non-destructiveness ONE clause: a
 * row whose `question_index` already equals `position` never enters the UPDATE's
 * result set, so it is left untouched rather than rewritten with the same value —
 * `updated_at` stays byte-identical. The second run has an empty result set by
 * construction.
 *
 * RAW SQL, NOT ELOQUENT — two reasons, both load-bearing:
 *   1. `InterviewSession` is `TenantScoped`. A model-based update run inside a
 *      migration has no ambient `TenantContext` and would silently repair one
 *      tenant (or none), not every tenant.
 *   2. An Eloquent mass update stamps `updated_at` unconditionally, which would
 *      break the byte-identical guarantee this migration exists to uphold for the
 *      29 already-correct rows.
 * Unscoped by design — every tenant, one statement — per the `error_count`
 * backfill precedent (`…120000_add_error_count_to_interview_sessions.php:44-47`):
 * the invariant is per row, so a tenant filter would leave exactly the rows it
 * excluded broken.
 *
 * DETACHED COMPETENCIES (D3). `ProjectController::update():164` performs a
 * detaching `sync()`, so a session can outlive its `project_competencies` pivot
 * row and the join can miss it. The `UPDATE ... FROM` structurally cannot touch an
 * unmatched row — the join itself excludes it, no defensive `WHERE` needed for
 * correctness — but leaving it silent would make the residual unattributable. A
 * pre-UPDATE SELECT counts and names any unmatched session ids in one
 * `Log::warning` before the UPDATE runs, stating the value is unverifiable because
 * the competency is detached from the project. `question_index` is NEVER written
 * as `0` or `NULL` for these rows — the row keeps whatever value it already had.
 *
 * REORDERING. A project reordered since its sessions were created recomputes to
 * the CURRENT position. Accepted: every reader (the transcript serializer, both
 * webhook assemblers, the admin resources) already joins against current
 * `position`, so this makes the row agree with the surfaces that render it.
 *
 * Ships in the SAME PR as the reader fix (D1) — a half-fixed dataset (new writes
 * correct, old rows still drifted, nothing in the data to tell them apart) is
 * worse than either state alone.
 *
 * REQ: interview-session "question_index backfill recomputes from position and
 *      never shifts"
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pre-UPDATE detection (D3): a session whose competency has no matching
        // project_competencies row (detached by a prior sync()). The UPDATE below
        // cannot touch these rows regardless — the join has nothing to match — so
        // this SELECT exists purely for disclosure, never to gate the UPDATE.
        $unmatched = DB::select(<<<'SQL'
            SELECT s.id
            FROM interview_sessions AS s
            WHERE NOT EXISTS (
                SELECT 1
                FROM project_competencies AS pc
                JOIN framework_competencies AS fc ON fc.id = pc.competency_id
                WHERE pc.project_id = s.project_id
                  AND fc.code = s.competency_code
            )
            ORDER BY s.id
            SQL);

        if ($unmatched !== []) {
            $sessionIds = array_column(array_map(static fn (object $row): array => (array) $row, $unmatched), 'id');

            Log::warning(
                'interview-question-index-offset: question_index backfill found sessions whose '
                .'competency is detached from their project (no matching project_competencies row). '
                .'Their question_index is left unchanged — the value is unverifiable, not 0.',
                ['session_ids' => $sessionIds],
            );
        }

        // THE recompute (D2). Raw SQL only — see class docblock for why Eloquent
        // is disqualified. `IS DISTINCT FROM` excludes already-correct rows from
        // the write set entirely, which is what keeps them byte-identical.
        DB::statement(<<<'SQL'
            UPDATE interview_sessions AS s
            SET question_index = pc.position
            FROM project_competencies AS pc
            JOIN framework_competencies AS fc ON fc.id = pc.competency_id
            WHERE pc.project_id = s.project_id
              AND fc.code       = s.competency_code
              AND s.question_index IS DISTINCT FROM pc.position
            SQL);
    }

    public function down(): void
    {
        // Deliberately empty and irreversible (D4). `-1` and `0` both recompute to
        // `0` — the pre-migration value is not derivable from the post-migration
        // value, the pivot, or anything else in the schema. A down() that wrote
        // `position - 1` back would restore the DEFECT to every row, including the
        // 29 that never had it — more destructive than the forward migration.
        // If rollback ever matters, restore from a database backup (the
        // `strip_language_from_avatar_templates_config` precedent, `:38-41`).
    }
};
