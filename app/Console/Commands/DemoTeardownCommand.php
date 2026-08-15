<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\InterviewSnapshot;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Support\Demo\DemoMarker;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Removes every row and storage object `beai:demo-seed` created from an
 * organization, leaving real data untouched and the organization itself in
 * place (design D1 — no separate demo org, so there is nothing else to
 * delete).
 *
 * Delete order matters and is NOT left to cascade alone:
 * `interview_sessions.project_id` is `restrictOnDelete` (belt-and-suspenders
 * against an accidental project hard-delete), so participants — whose
 * cascade removes their sessions, utterances, integrity events, snapshots,
 * evaluations, competency results and indicator scores — are always deleted
 * BEFORE their project. `projects.framework_version_id` is `restrictOnDelete`
 * too, so `beai-demo-1.0.0` is only attempted last, after every demo project
 * that could reference it is already gone.
 *
 * Two guards make the reachability argument's one unsound case (design D1) a
 * loud stop instead of silent data loss:
 *
 * 1. A non-demo participant found inside a demo project blocks the ENTIRE
 *    run before a single row is deleted — someone may have minted a real SSO
 *    link against a demo project's slug.
 * 2. If `beai-demo-1.0.0` is still referenced by a non-demo row after every
 *    other delete has run, the version delete hard-fails and names what
 *    references it (ratified answer to design open question 2) — everything
 *    else removed by this run stays removed; this is not a rollback.
 */
final class DemoTeardownCommand extends Command
{
    protected $signature = 'beai:demo-teardown
        {--org= : Slug of the organization to remove demo data from (required)}
        {--confirm-slug= : Required in production — must exactly equal --org}';

    protected $description = 'Remove every row and storage object beai:demo-seed created, leaving real data untouched.';

    public function handle(): int
    {
        $orgSlug = trim((string) $this->option('org'));

        if ($orgSlug === '') {
            $this->error('The --org option is required.');

            return self::FAILURE;
        }

        $organization = Organization::where('slug', $orgSlug)->first();

        if ($organization === null) {
            $this->error("Organization [{$orgSlug}] not found.");

            return self::FAILURE;
        }

        $isProduction = app()->environment('production');
        $confirmSlug = trim((string) $this->option('confirm-slug'));

        if ($isProduction && $confirmSlug !== $orgSlug) {
            $this->error('Refusing to delete in a PRODUCTION organization without explicit confirmation.');
            $this->line("Pass --confirm-slug={$orgSlug} (typed exactly, matching --org) to proceed.");

            return self::FAILURE;
        }

        return TenantContextScope::runFor($organization->id, fn (): int => $this->teardown($organization));
    }

    private function teardown(Organization $organization): int
    {
        $demoProjects = Project::withTrashed()->where('slug', 'like', DemoMarker::PREFIX.'%')->get();

        // Guard 1: any non-demo participant inside a demo project blocks the
        // WHOLE run before a single row is deleted.
        foreach ($demoProjects as $project) {
            $intruder = Participant::where('project_id', $project->id)
                ->where('candidate_ref', 'not like', DemoMarker::PREFIX.'%')
                ->first();

            if ($intruder !== null) {
                $this->error(
                    "Refusing to tear down: demo project [{$project->slug}] holds a NON-DEMO participant ".
                    "(id={$intruder->id}, candidate_ref={$intruder->candidate_ref}). No cascade, no silent delete."
                );

                return self::FAILURE;
            }
        }

        $demoParticipants = Participant::where('organization_id', $organization->id)
            ->where('candidate_ref', 'like', DemoMarker::PREFIX.'%')
            ->get();

        // Step 1, MUST BE FIRST (design D12): notification_logs has no FK to
        // its polymorphic subject, so its demo rows are identified by
        // resolving (subject_type, subject_id) against a STILL-EXISTING
        // demo participant or demo webhook delivery — collected here, before
        // anything else is deleted. Deleting subjects first would make these
        // rows PERMANENTLY unidentifiable orphans (non-negotiable #6).
        $notificationLogsRemoved = $this->deleteNotificationLogs($demoParticipants);

        $this->sweepStorage($organization, $demoParticipants);

        // Deleting each participant cascades its sessions (→ utterances,
        // integrity events, snapshots) and its evaluation (→ competency
        // results → indicator scores) — every row this command created that
        // is NOT itself one of the four marked roots.
        foreach ($demoParticipants as $participant) {
            $participant->delete();
        }

        // Hard delete: Project uses SoftDeletes, and a soft-deleted row
        // still occupies the (organization_id, slug) partial unique index —
        // "removed" has to mean gone, not hidden.
        foreach ($demoProjects as $project) {
            $project->forceDelete();
        }

        $demoTemplates = AvatarTemplate::where('name', 'like', DemoMarker::PREFIX.'%')->get();

        foreach ($demoTemplates as $template) {
            $template->delete();
        }

        // Step 6 (design D12): explicit delete — no cascade exists from
        // organizations. api_clients is NOT a TenantModel, so this is
        // filtered by organization_id explicitly, mirroring the guard's own
        // raw lookup.
        $demoApiClientsRemoved = ApiClient::where('organization_id', $organization->id)
            ->where('name', 'like', DemoMarker::PREFIX.'%')
            ->delete();

        $version = FrameworkVersion::where('version', DemoMarker::PREFIX.'1.0.0')->first();
        $versionRemoved = false;

        if ($version !== null) {
            $references = $this->frameworkVersionReferences($version);

            if ($references !== []) {
                $this->error(
                    "Refusing to delete FrameworkVersion [{$version->version}]: still referenced by ".implode(', ', $references).'. '.
                    'Everything else this run could safely remove has already been removed.'
                );

                return self::FAILURE;
            }

            $version->delete();
            $versionRemoved = true;
        }

        $this->info('Demo dataset removed.');
        $this->table(
            ['What', 'Removed'],
            [
                ['Notification logs', $notificationLogsRemoved],
                ['Participants', $demoParticipants->count()],
                ['Projects', $demoProjects->count()],
                ['Avatar templates', $demoTemplates->count()],
                ['API clients', $demoApiClientsRemoved],
                ['FrameworkVersion', $versionRemoved ? 'beai-demo-1.0.0' : 'none (did not exist)'],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Step 1 of teardown, MUST run before anything else is deleted (design
     * D12). `notification_logs` has no FK to its polymorphic subject, so its
     * demo rows are identified by resolving `(subject_type, subject_id)`
     * against a demo participant's id or a demo webhook delivery's id — both
     * collected here while they still exist. `webhook_deliveries` are read
     * (never written) from participants that have not been touched yet, so
     * every one reachable from a demo participant is still present.
     *
     * @param  Collection<int, Participant>  $demoParticipants
     */
    private function deleteNotificationLogs(Collection $demoParticipants): int
    {
        $participantIds = $demoParticipants->pluck('id');

        $deliveryIds = WebhookDelivery::whereIn('participant_id', $participantIds)->pluck('id');

        return NotificationLog::where(function ($query) use ($participantIds, $deliveryIds): void {
            $query->where(fn ($q) => $q->where('subject_type', 'participant')->whereIn('subject_id', $participantIds))
                ->orWhere(fn ($q) => $q->where('subject_type', 'webhook_delivery')->whereIn('subject_id', $deliveryIds));
        })->delete();
    }

    /**
     * Object first, per participant: collect every snapshot key reachable
     * from this participant's sessions and delete each object, then sweep
     * `{org}/{participantId}` as a directory — this catches any orphan a
     * rolled-back seed run left behind (design D6), not just the rows that
     * survived to be counted here.
     *
     * @param  Collection<int, Participant>  $demoParticipants
     */
    private function sweepStorage(Organization $organization, Collection $demoParticipants): void
    {
        foreach ($demoParticipants as $participant) {
            $sessionIds = InterviewSession::where('participant_id', $participant->id)->pluck('id');

            $s3Keys = InterviewSnapshot::whereIn('interview_session_id', $sessionIds)->pluck('s3_key');

            foreach ($s3Keys as $s3Key) {
                Storage::delete($s3Key);
            }

            Storage::deleteDirectory("{$organization->id}/{$participant->id}");
        }
    }

    /**
     * @return list<string>
     */
    private function frameworkVersionReferences(FrameworkVersion $version): array
    {
        $references = [];

        $projectCount = Project::withTrashed()->where('framework_version_id', $version->id)->count();

        if ($projectCount > 0) {
            $references[] = "{$projectCount} project(s)";
        }

        $sessionCount = InterviewSession::where('framework_version_id', $version->id)->count();

        if ($sessionCount > 0) {
            $references[] = "{$sessionCount} interview session(s)";
        }

        $evaluationCount = Evaluation::where('framework_version_id', $version->id)->count();

        if ($evaluationCount > 0) {
            $references[] = "{$evaluationCount} evaluation(s)";
        }

        return $references;
    }
}
