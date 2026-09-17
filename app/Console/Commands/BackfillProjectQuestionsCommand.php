<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Project\ApplyCompetencySelection;
use App\Exceptions\Console\BackfillDryRunRollback;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Project\ProjectInterviewability;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan beai:backfill-project-questions [--org=] [--dry-run]`
 * (Z22, R3-interviewability-rollout-existing-projects, framework-catalogue-
 * authoring, REQUIRED BEFORE ARCHIVE).
 *
 * THE GAP THIS CLOSES. `ProjectInterviewability` gates every interview
 * ingress on "every currently-selected competency has at least one live
 * `project_questions` row" — but that predicate did not exist when earlier
 * projects were created, so a project can have a competency SELECTED
 * (`project_competencies`) with ZERO `project_questions` rows for it, never
 * having gone through `ApplyCompetencySelection::apply()` at all. The moment
 * `ProjectInterviewability` deploys, every such project stops minting
 * invites — not because anything about the project changed, but because the
 * check is new. This command finds every one and brings it into the same
 * state a fresh competency selection would produce today.
 *
 * WHY A COMMAND, NOT A MIGRATION. This needed the same decision logic
 * `ApplyCompetencySelection` already owns — which pinned catalogue revision
 * to copy from (`FrameworkVersion::revision_id`), the per-assessment-type
 * question cap (`PlatformSettings`), and the restore-vs-copy-vs-no-op branch
 * — none of which belongs duplicated into a schema migration. A migration
 * also runs unattended, exactly once, with no room to preview the blast
 * radius first; this is a cross-tenant, business-logic-dependent backfill,
 * which is exactly the shape `catalogue:import` and
 * `framework:forget-locale` already use a command for. `--dry-run` computes
 * the SAME decision as a real run (inside a transaction it then rolls back,
 * see `backfillProject()`) rather than a separately-maintained prediction
 * that could silently drift from what a real run actually does.
 *
 * IDEMPOTENT AND SAFE TO RE-RUN. Every write goes through
 * `ApplyCompetencySelection::ensureCompetencyHasQuestions()` — the same
 * method a fresh selection uses — so a competency already satisfied is a
 * no-op (branch 2, live rows already exist), and a competency the command
 * already fixed in an earlier run stays untouched on the next one.
 *
 * SOURCE REVISION: PINNED, THEN LATEST-PUBLISHED FALLBACK
 * (R4-interviewability-rollout-unrecoverable). Every pre-existing project is
 * pinned to the BASELINE, whose defaults are always empty, so the pinned
 * revision alone can never recover it — `ensureCompetencyHasQuestions()`
 * falls back to the latest PUBLISHED revision (matched by competency CODE)
 * only when the pin has nothing. Runbook: author the missing defaults in a
 * draft → publish it → re-run this command.
 *
 * WHAT "STILL INCOMPLETE" MEANS. A selected competency can have ZERO
 * catalogue defaults in BOTH the project's own pinned revision and the
 * latest published one (project-config spec — "A competency with no
 * catalogue defaults yields zero rows" is not an error). This command cannot
 * invent content that was never authored: it reports these cases by name so
 * an operator can follow the runbook above (or deselect the competency) —
 * see the summary this command prints. It never silently leaves a project
 * believed-fixed when it is not.
 */
final class BackfillProjectQuestionsCommand extends Command
{
    protected $signature = 'beai:backfill-project-questions
        {--org= : Slug of one organization to backfill. Omit to backfill every organization.}
        {--dry-run : Report what would be backfilled without writing anything}';

    protected $description = 'Copy catalogue defaults into project_questions for every existing project whose selected competency has zero live questions (Z22, framework-catalogue-authoring)';

    public function handle(ApplyCompetencySelection $applyCompetencySelection, ProjectInterviewability $interviewability): int
    {
        $orgSlug = trim((string) $this->option('org'));
        $dryRun = (bool) $this->option('dry-run');

        $organizations = $orgSlug === ''
            ? Organization::withoutGlobalScopes()->orderBy('id')->get()
            : Organization::withoutGlobalScopes()->where('slug', $orgSlug)->get();

        if ($organizations->isEmpty()) {
            $this->error($orgSlug === ''
                ? 'No organizations exist. Nothing to backfill.'
                : "No organization with slug '{$orgSlug}'.");

            return self::FAILURE;
        }

        $backfilledCount = 0;
        /** @var list<string> $incompleteLines */
        $incompleteLines = [];

        foreach ($organizations as $organization) {
            $projects = Project::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->orderBy('id')
                ->get();

            foreach ($projects as $project) {
                TenantContextScope::runFor($organization->id, function () use (
                    $project,
                    $applyCompetencySelection,
                    $interviewability,
                    $dryRun,
                    &$backfilledCount,
                    &$incompleteLines,
                ): void {
                    $result = $this->backfillProject($project, $applyCompetencySelection, $interviewability, $dryRun);

                    foreach ($result['backfilled'] as $code) {
                        $backfilledCount++;
                        $this->line(sprintf(
                            '  %s project %d (%s) — competency %s',
                            $dryRun ? '[dry-run] would backfill' : 'backfilled',
                            $project->id,
                            $project->name,
                            $code,
                        ));
                    }

                    foreach ($result['incomplete'] as $code) {
                        $incompleteLines[] = "project {$project->id} ({$project->name}) — competency {$code}";
                    }
                });
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d competency selection(s) across %d organization(s).',
            $dryRun ? 'Would backfill' : 'Backfilled',
            $backfilledCount,
            $organizations->count(),
        ));

        if ($incompleteLines !== []) {
            $this->newLine();
            $this->warn(sprintf(
                '%d competency selection(s) stay incomplete — neither the pinned catalogue revision nor the latest published one has default questions for them (nothing to copy; author defaults, publish, and re-run this command, or deselect):',
                count($incompleteLines),
            ));

            foreach ($incompleteLines as $line) {
                $this->line("  - {$line}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Runs the REAL decision logic inside a transaction, always — for
     * `--dry-run` the exact same writes happen and the exact same
     * post-write state is read, then `BackfillDryRunRollback` unwinds the
     * transaction before it commits. This is deliberately not a separate,
     * read-only prediction: a second copy of "would this competency get
     * fixed" is exactly the kind of drift `ProjectInterviewability`'s own
     * docblock warns this repo has already paid for twice.
     *
     * @return array{backfilled: list<string>, incomplete: list<string>}
     */
    private function backfillProject(
        Project $project,
        ApplyCompetencySelection $applyCompetencySelection,
        ProjectInterviewability $interviewability,
        bool $dryRun,
    ): array {
        $unsatisfiedBefore = $interviewability->unsatisfiedCompetencyCodes($project);

        if ($unsatisfiedBefore === []) {
            return ['backfilled' => [], 'incomplete' => []];
        }

        /** @var array<string, int> $selected code => competency_id, this project's own selection only */
        $selected = DB::table('project_competencies as pc')
            ->join('framework_competencies as c', 'c.id', '=', 'pc.competency_id')
            ->where('pc.project_id', $project->id)
            ->pluck('c.id', 'c.code')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $unsatisfiedAfter = null;

        try {
            DB::transaction(function () use (
                $project,
                $unsatisfiedBefore,
                $selected,
                $applyCompetencySelection,
                $interviewability,
                $dryRun,
                &$unsatisfiedAfter,
            ): void {
                foreach ($unsatisfiedBefore as $code) {
                    $competencyId = $selected[$code] ?? null;

                    // Not expected — `unsatisfiedCompetencyCodes()` derives
                    // its codes from this SAME project's own pivot rows —
                    // but a competency pinned to this project could in
                    // principle be renamed between the two reads under
                    // concurrent catalogue edits. Skipped, not fatal: the
                    // next run picks it up.
                    if ($competencyId === null) {
                        continue;
                    }

                    $applyCompetencySelection->ensureCompetencyHasQuestions($project, $competencyId);
                }

                $unsatisfiedAfter = $interviewability->unsatisfiedCompetencyCodes($project);

                if ($dryRun) {
                    throw new BackfillDryRunRollback;
                }
            });
        } catch (BackfillDryRunRollback) {
            // Deliberate — the transaction above already rolled back every
            // write this dry run made; `$unsatisfiedAfter` was captured
            // INSIDE that transaction, so it still reflects what a real run
            // would have left in place.
        }

        $unsatisfiedAfter ??= $unsatisfiedBefore;

        return [
            'backfilled' => array_values(array_diff($unsatisfiedBefore, $unsatisfiedAfter)),
            'incomplete' => $unsatisfiedAfter,
        ];
    }
}
