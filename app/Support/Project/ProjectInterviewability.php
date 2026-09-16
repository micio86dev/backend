<?php

declare(strict_types=1);

namespace App\Support\Project;

use App\Models\Project;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The single interviewability predicate (framework-catalogue-authoring PR6,
 * D5, project-config spec — "A Single Interviewability Predicate Gates Every
 * Interview Entry Point"). Every route capable of starting or continuing an
 * interview consumes THIS class; none reimplements its own check. This repo
 * has already paid twice for "three things that must agree" drifting
 * (`AGENTS.md` living as a copy of `CLAUDE.md`, an indicator-count guard that
 * disagreed with the spec and the data) — this class exists precisely so a
 * fourth instance does not ship.
 *
 * PLAIN `DB::table()`, NEVER `ProjectQuestion::query()`. `SsoExchangeController`
 * resolves its project with `withoutGlobalScope('tenant')` because a candidate
 * is unauthenticated and no tenant is ever resolved on that path — an
 * Eloquent read through `ProjectQuestion` (a `TenantModel`) would hit
 * `TenantScoped` with no resolver value and fail for the wrong reason. The
 * organization is therefore taken from `$project->organization_id` — the row
 * the caller already resolved — and stated EXPLICITLY in the query's `ON`
 * clause, never read from ambient tenant context. `project_competencies`
 * carries no `organization_id` of its own; it is bounded by `project_id`,
 * which is already tenant-bound by the project the caller resolved.
 *
 * NO CACHE, NO STATIC MEMO, NO `relationLoaded()` SHORTCUT. Resolved from the
 * container at each call site and queried fresh on every call —
 * "evaluated at the moment of use" is a property of the code, not a comment
 * on it. `App\Support\Project\`, not a `Project::isInterviewable()` accessor:
 * an accessor is exactly the shape that gets eager-loaded, memoised, and read
 * stale.
 */
final class ProjectInterviewability
{
    /**
     * Competency codes currently SELECTED on the project with ZERO live
     * (non-soft-deleted) `project_questions` rows.
     *
     * Soft-delete semantics: `deleted_at IS NULL` lives in the LEFT JOIN, not
     * a `WHERE` — a `WHERE` would drop the joined row entirely and report the
     * competency as satisfied by a row that no longer counts. A competency
     * whose rows are ALL soft-deleted therefore still counts as zero live. A
     * DESELECTED competency has no `project_competencies` row at all, so it
     * never enters this query — out of scope for the check entirely, exactly
     * as the spec states.
     *
     * Empty does NOT, on its own, mean "interviewable" — see
     * `isInterviewable()` for the zero-competency case this method alone
     * cannot express.
     *
     * @return list<string>
     */
    public function unsatisfiedCompetencyCodes(Project $project): array
    {
        $codes = DB::table('project_competencies as pc')
            ->join('framework_competencies as c', 'c.id', '=', 'pc.competency_id')
            ->leftJoin('project_questions as q', function (JoinClause $join) use ($project): void {
                $join->on('q.project_id', '=', 'pc.project_id')
                    ->on('q.competency_id', '=', 'pc.competency_id')
                    // Explicit, never ambient — see the class docblock.
                    ->where('q.organization_id', '=', $project->organization_id)
                    ->whereNull('q.deleted_at');
            })
            ->where('pc.project_id', $project->id)
            // Grouped by the PIVOT's own competency id, not merely `c.code`
            // (gga review finding — a review-only observation, since a
            // project's pivot rows are always revision-scoped and a code is
            // unique within one revision, but grouping by id costs nothing
            // and removes even the theoretical ambiguity): two SELECTED
            // pivot rows can never legitimately share one competency, so
            // this is the more precise grouping key. `c.code` is
            // functionally dependent on it and stays in the `GROUP BY`
            // only because Postgres requires every selected column to be.
            ->groupBy('pc.competency_id', 'c.code')
            ->havingRaw('count(q.id) = 0')
            ->pluck('c.code');

        return array_values($codes->map(static fn (mixed $code): string => (string) $code)->all());
    }

    /**
     * A project IS INTERVIEWABLE only while every currently-selected
     * competency has at least one live question, AND it has at least one
     * selected competency.
     *
     * The second half is an ADDITION beyond the spec's literal wording (see
     * design.md's Contradiction 5): `unsatisfiedCompetencyCodes()` alone
     * returns `[]` VACUOUSLY for a project with zero selected competencies
     * (the `HAVING` clause matches an empty set), and a project with nothing
     * selected cannot start an interview at all.
     */
    public function isInterviewable(Project $project): bool
    {
        return $this->evaluate($project)['interviewable'];
    }

    /**
     * Both pieces of information a REFUSAL response body needs
     * (`error`/`message` + `competency_codes`), computed from ONE
     * `unsatisfiedCompetencyCodes()` call. This is the ONE place the
     * "unsatisfied is empty AND at least one competency selected" rule is
     * written (gga review finding — `isInterviewable()` used to duplicate
     * it instead of delegating here, which is exactly the kind of drift
     * this class exists to prevent). `isInterviewable()` is a thin wrapper
     * over this method's `interviewable` key, kept as its own method
     * because most callers (`InterviewController::start()`,
     * `SsoExchangeController`) need only the boolean and calling
     * `evaluate()` there would read as needing the competency list too.
     *
     * @return array{interviewable: bool, unsatisfied_competency_codes: list<string>}
     */
    public function evaluate(Project $project): array
    {
        $unsatisfied = $this->unsatisfiedCompetencyCodes($project);

        $interviewable = $unsatisfied === [] && DB::table('project_competencies')
            ->where('project_id', $project->id)
            ->exists();

        return ['interviewable' => $interviewable, 'unsatisfied_competency_codes' => $unsatisfied];
    }
}
