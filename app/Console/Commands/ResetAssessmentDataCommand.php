<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InterviewSnapshot;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Empties the ASSESSMENT data — candidates, interviews, evaluations, projects —
 * and leaves the configuration an organization needs to start over: its users,
 * its avatar templates, its settings, and the framework catalogue.
 *
 * WHAT SURVIVES, AND WHY EACH ONE
 * -------------------------------
 *   users + roles          you have to be able to log back in
 *   organizations          branding, webhook defaults — the settings
 *   avatar_templates       what this reset exists to preserve
 *   llm_credentials        templates reference them with RESTRICT; deleting
 *                          these would either fail or orphan every template
 *   llm_models             a seeded registry, not tenant data
 *   framework_*            the BARS catalogue, and the per-org pinned version
 *                          a new project needs to reference
 *   api_clients            integration credentials, which are configuration —
 *                          `--include-api-clients` removes them
 *   audit_logs             a record of who did what, which a data reset is a
 *                          poor reason to destroy — `--include-audit-logs`
 *                          removes them
 *
 * WHAT GOES: participants and everything beneath them, projects, and the
 * operational logs tied to them.
 *
 * THE ORDER IS THE FOREIGN KEYS, NOT A GUESS
 * -------------------------------------------
 * Deleting a participant CASCADEs to its interview sessions (and through them
 * to utterances, integrity events, snapshots, live periods and LLM usage), its
 * evaluations (and through them to competency results, indicator scores and
 * `ai_requests`), and its webhook deliveries. So participants go first and
 * most of the graph goes with them.
 *
 * Projects go SECOND and never first: `interview_sessions.project_id` and
 * `webhook_deliveries.project_id` are both `restrictOnDelete`, so a project
 * deleted before its participants refuses — loudly, but after this command has
 * already removed other rows.
 *
 * `notification_logs` is not reachable from a participant at all (it is
 * org-scoped operator mail), so it is deleted explicitly.
 *
 * STORAGE OBJECTS GO TOO. Snapshot JPEGs live under
 * `{org}/{participant}/{session}/{uuid}.jpg`, and rows deleted without their
 * objects leave a disk that grows forever and a bill nobody can explain. They
 * are read from `interview_snapshots.s3_key` BEFORE the rows are deleted,
 * because after the cascade there is nothing left to tell us what to remove.
 */
final class ResetAssessmentDataCommand extends Command
{
    protected $signature = 'beai:reset-assessment-data
        {--org= : Slug of one organization to reset. Omit to reset every organization.}
        {--confirm= : Required outside local. Must equal the --org slug, or ALL when --org is omitted.}
        {--include-audit-logs : Also delete the audit trail}
        {--include-api-clients : Also delete M2M API clients}
        {--dry-run : Count what would be deleted and delete nothing}';

    protected $description = 'Delete candidates, interviews, evaluations and projects, keeping users, templates, settings and the framework catalogue.';

    public function handle(): int
    {
        $orgSlug = trim((string) $this->option('org'));
        $dryRun = (bool) $this->option('dry-run');

        $organizations = $orgSlug === ''
            ? Organization::withoutGlobalScopes()->orderBy('id')->get()
            : Organization::withoutGlobalScopes()->where('slug', $orgSlug)->get();

        if ($organizations->isEmpty()) {
            $this->error($orgSlug === ''
                ? 'No organizations exist. Nothing to reset.'
                : "No organization with slug '{$orgSlug}'.");

            return self::FAILURE;
        }

        if (! $dryRun && ! $this->confirmed($orgSlug)) {
            return self::FAILURE;
        }

        $totals = [];

        foreach ($organizations as $organization) {
            $this->line("<comment>{$organization->slug}</comment> (id {$organization->id})");

            TenantContextScope::runFor($organization->id, function () use ($organization, $dryRun, &$totals): void {
                $this->resetOrganization($organization, $dryRun, $totals);
            });
        }

        $this->newLine();

        foreach ($totals as $label => $count) {
            $this->line(sprintf('  %-24s %s', $label, number_format($count)));
        }

        $this->newLine();
        $this->info($dryRun
            ? 'Dry run — nothing was deleted.'
            : 'Done. Users, avatar templates, settings and the framework catalogue are untouched.');

        return self::SUCCESS;
    }

    /**
     * Outside `local`, the operator must name what they are destroying.
     *
     * A bare `--force` is a flag people learn to type. Retyping the slug is a
     * sentence you cannot produce by muscle memory, and it is the same shape
     * `beai:demo-teardown` already uses — one confirmation convention across
     * the destructive commands, not two.
     */
    private function confirmed(string $orgSlug): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        $expected = $orgSlug === '' ? 'ALL' : $orgSlug;
        $given = trim((string) $this->option('confirm'));

        if ($given === $expected) {
            return true;
        }

        $this->error("Refusing to run outside local without --confirm={$expected}.");

        return false;
    }

    /**
     * @param  array<string, int>  $totals
     */
    private function resetOrganization(Organization $organization, bool $dryRun, array &$totals): void
    {
        $orgId = $organization->id;

        // Read BEFORE deleting. The cascade removes the rows that name these
        // objects, so after the delete there is nothing left to tell us which
        // files to remove.
        $snapshotKeys = InterviewSnapshot::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->pluck('s3_key')
            ->filter()
            ->all();

        $counts = [
            'participants' => Participant::withoutGlobalScopes()->where('organization_id', $orgId)->count(),
            'projects' => Project::withoutGlobalScopes()->where('organization_id', $orgId)->count(),
            'snapshot objects' => count($snapshotKeys),
            'notification logs' => DB::table('notification_logs')->where('organization_id', $orgId)->count(),
        ];

        if ($this->option('include-audit-logs')) {
            $counts['audit logs'] = DB::table('audit_logs')->where('organization_id', $orgId)->count();
        }

        if ($this->option('include-api-clients')) {
            $counts['api clients'] = DB::table('api_clients')->where('organization_id', $orgId)->count();
        }

        foreach ($counts as $label => $count) {
            $totals[$label] = ($totals[$label] ?? 0) + $count;
        }

        if ($dryRun) {
            return;
        }

        // Participants FIRST — the cascade takes sessions, utterances,
        // integrity events, snapshots, live periods, LLM usage, evaluations,
        // competency results, indicator scores, ai_requests and webhook
        // deliveries with them.
        Participant::withoutGlobalScopes()->where('organization_id', $orgId)->delete();

        // Projects SECOND, never first: `interview_sessions.project_id` and
        // `webhook_deliveries.project_id` both restrict, so a project deleted
        // ahead of its participants refuses.
        Project::withoutGlobalScopes()->where('organization_id', $orgId)->forceDelete();

        // Not reachable from a participant — org-scoped operator mail.
        DB::table('notification_logs')->where('organization_id', $orgId)->delete();

        if ($this->option('include-audit-logs')) {
            DB::table('audit_logs')->where('organization_id', $orgId)->delete();
        }

        if ($this->option('include-api-clients')) {
            DB::table('api_clients')->where('organization_id', $orgId)->delete();
        }

        // Objects last, and one at a time rather than by prefix: the disk is
        // resolved through the single configuration point (no disk argument),
        // and a prefix delete would trust a path convention instead of the
        // keys the rows actually recorded.
        foreach ($snapshotKeys as $key) {
            Storage::delete($key);
        }
    }
}
