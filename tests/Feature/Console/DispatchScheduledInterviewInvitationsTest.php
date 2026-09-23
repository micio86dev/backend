<?php

declare(strict_types=1);

/**
 * RED — beai:dispatch-scheduled-invitations (interview-scheduling PR-D,
 * design AD-4/AD-5/AD-6/AD-8, tasks T-D3).
 *
 * Modeled on tests/Feature/Interview/ReapStaleInterviewsTest.php: the command
 * under test establishes NO ambient tenant context (it is a platform-wide
 * sweep), so every fixture is built under an EXPLICIT TenantResolver context
 * and the resolver is cleared before each run — the same discipline that file
 * documents at length.
 *
 * Two independent selections drive every scenario here (design AD-4):
 *   notice-due: scheduling_status=Pending AND scheduled_at <= now()+15min
 *   start-due:  scheduling_status IN (Pending, NoticeSent) AND scheduled_at <= now()
 * A Pending participant whose scheduled_at has ALREADY passed satisfies BOTH
 * conditions at once — this is the deliberate backlog-catch-up shape, not a
 * bug: it is what guarantees "exactly one notice email and exactly one start
 * email, never zero of either" for a participant a slow tick skipped over.
 */

use App\Enums\ParticipantSchedulingStatus;
use App\Jobs\SendCandidateInvitationJob;
use App\Jobs\SendScheduledInterviewNoticeJob;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

function sweepOrg(): Organization
{
    return Organization::factory()->create();
}

function sweepProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId((int) $org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    return Project::factory()->create([
        'organization_id' => $org->id,
        'framework_version_id' => $fv->id,
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'goes_live_at' => null,
        'deadline_at' => null,
    ]);
}

function sweepParticipant(Organization $org, Project $project, array $attrs = []): Participant
{
    $participant = new Participant;
    $participant->forceFill(array_merge([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'sweep-'.uniqid(),
        'display_name' => 'Sweep Candidate',
        'email' => uniqid('sweep-').'@example.test',
        'role_code' => $project->role_code,
        'language' => 'en',
        'status' => 'in_attesa',
    ], $attrs));
    $participant->save();

    return $participant;
}

/**
 * Overrides the container's `Dispatcher` binding, AFTER `Bus::fake()` already
 * ran in `beforeEach()`, with one that always throws — simulating an
 * unreachable queue backend or a serialization failure on the actual
 * `dispatch()` call. `Bus::fake()` cannot express this: it records a
 * dispatched job instead of sending it, so it can never itself throw. Laravel
 * rebuilds the container fresh per test, so this binding never leaks into any
 * other test's `Bus::fake()` behavior.
 */
function bindThrowingDispatcher(string $message): void
{
    app()->instance(Dispatcher::class, new class($message) implements Dispatcher
    {
        public function __construct(private readonly string $message) {}

        public function dispatch($command)
        {
            throw new RuntimeException($this->message);
        }

        public function dispatchSync($command, $handler = null) {}

        public function dispatchNow($command, $handler = null) {}

        public function dispatchAfterResponse($command, $handler = null) {}

        public function chain($jobs = null) {}

        public function hasCommandHandler($command)
        {
            return false;
        }

        public function getCommandHandler($command)
        {
            return false;
        }

        public function pipeThrough(array $pipes)
        {
            return $this;
        }

        public function map(array $map)
        {
            return $this;
        }
    });
}

/**
 * Runs the command the way the SCHEDULER runs it: with no ambient tenant
 * context whatsoever — see ReapStaleInterviewsTest::runReaper()'s own
 * docblock for exactly why this matters.
 */
function runSweep(array $arguments = []): int
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(null);
    $resolver->setBypass(false);

    return Artisan::call('beai:dispatch-scheduled-invitations', $arguments);
}

/**
 * SendCandidateInvitationJob's constructor arguments are `private readonly`
 * (by design — "everything arrives as a scalar", never a public surface to
 * mutate). Reflection is the established way this suite inspects a dispatched
 * job's payload (see tests/Feature/Console/RepointGeminiFlashLiteBindingCommandTest.php:172).
 */
function jobProperty(object $job, string $property): mixed
{
    $ref = new ReflectionProperty($job, $property);
    $ref->setAccessible(true);

    return $ref->getValue($job);
}

beforeEach(function (): void {
    Bus::fake();
    config(['interview.candidate_app_url' => 'https://interview.example.test']);
});

describe('the notice window', function (): void {
    test('a notice fires when scheduled_at falls within the 15-minute lead window', function (): void {
        $org = sweepOrg();
        $project = sweepProject($org);
        $participant = sweepParticipant($org, $project, [
            'scheduled_at' => now()->addMinutes(10),
            'scheduling_status' => ParticipantSchedulingStatus::Pending,
        ]);

        runSweep();

        expect($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::NoticeSent);
        Bus::assertDispatched(SendScheduledInterviewNoticeJob::class);
        Bus::assertNotDispatched(SendCandidateInvitationJob::class);
    });

    test('a participant scheduled well beyond the lead window is left untouched', function (): void {
        $org = sweepOrg();
        $project = sweepProject($org);
        $participant = sweepParticipant($org, $project, [
            'scheduled_at' => now()->addMinutes(45),
            'scheduling_status' => ParticipantSchedulingStatus::Pending,
        ]);

        runSweep();

        expect($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::Pending);
        Bus::assertNothingDispatched();
    });
});

describe('the start moment', function (): void {
    test('a start fires with a freshly minted link once scheduled_at has arrived', function (): void {
        $org = sweepOrg();
        $project = sweepProject($org);
        $participant = sweepParticipant($org, $project, [
            'scheduled_at' => now()->subMinute(),
            // Already past its notice — the notice-due query no longer
            // matches (scheduling_status != Pending), so ONLY the start
            // fires in this run.
            'scheduling_status' => ParticipantSchedulingStatus::NoticeSent,
        ]);

        runSweep();

        expect($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::Started);
        Bus::assertNotDispatched(SendScheduledInterviewNoticeJob::class);
        Bus::assertDispatched(
            SendCandidateInvitationJob::class,
            fn ($job): bool => jobProperty($job, 'email') === $participant->email,
        );
    });

    test('a cancelled participant is skipped entirely, never sent anything', function (): void {
        $org = sweepOrg();
        $project = sweepProject($org);
        $participant = sweepParticipant($org, $project, [
            'scheduled_at' => now()->subHour(),
            'scheduling_status' => ParticipantSchedulingStatus::Cancelled,
        ]);

        runSweep();

        expect($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::Cancelled);
        Bus::assertNothingDispatched();
    });
});

describe('idempotency — the sweep never double-sends', function (): void {
    test('a repeated run does not double-send either email', function (): void {
        $org = sweepOrg();
        $project = sweepProject($org);
        $participant = sweepParticipant($org, $project, [
            'scheduled_at' => now()->subMinute(),
            'scheduling_status' => ParticipantSchedulingStatus::NoticeSent,
        ]);

        runSweep();
        runSweep();

        expect($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::Started);
        Bus::assertDispatchedTimes(SendCandidateInvitationJob::class, 1);
    });

    test('a backlog run — both thresholds already passed — sends exactly one notice and one start, never zero of either', function (): void {
        $org = sweepOrg();
        $project = sweepProject($org);
        $participant = sweepParticipant($org, $project, [
            // A candidate the sweep never reached until long after BOTH the
            // notice window and the start moment — worker down / deploy
            // window, per AD-10's own residual-risk list — still Pending.
            'scheduled_at' => now()->subHours(2),
            'scheduling_status' => ParticipantSchedulingStatus::Pending,
        ]);

        runSweep();

        expect($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::Started);
        Bus::assertDispatchedTimes(SendScheduledInterviewNoticeJob::class, 1);
        Bus::assertDispatchedTimes(SendCandidateInvitationJob::class, 1);
    });
});

describe('multi-tenancy', function (): void {
    test('two organizations due in the same tick are each processed under their own organization_id, with no cross-contamination', function (): void {
        $orgA = sweepOrg();
        $projectA = sweepProject($orgA);
        $participantA = sweepParticipant($orgA, $projectA, [
            'scheduled_at' => now()->subMinute(),
            'scheduling_status' => ParticipantSchedulingStatus::NoticeSent,
        ]);

        $orgB = sweepOrg();
        $projectB = sweepProject($orgB);
        $participantB = sweepParticipant($orgB, $projectB, [
            'scheduled_at' => now()->subMinute(),
            'scheduling_status' => ParticipantSchedulingStatus::NoticeSent,
        ]);

        runSweep();

        expect($participantA->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::Started);
        expect($participantB->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::Started);

        Bus::assertDispatched(
            SendCandidateInvitationJob::class,
            fn ($job): bool => jobProperty($job, 'email') === $participantA->email
                && jobProperty($job, 'organizationName') === $projectA->organization->name,
        );
        Bus::assertDispatched(
            SendCandidateInvitationJob::class,
            fn ($job): bool => jobProperty($job, 'email') === $participantB->email
                && jobProperty($job, 'organizationName') === $projectB->organization->name,
        );
    });
});

describe('a permanently-failing participant is cancelled, not retried forever', function (): void {
    test('a project relation that no longer resolves at start time is cancelled and never reselected', function (): void {
        $orgA = sweepOrg();
        $orgB = sweepOrg();
        $projectB = sweepProject($orgB);
        // organization_id = orgA but project_id points at orgB's project:
        // under TenantContextScope::runFor(orgA), Project's own tenant scope
        // filters this relation to null — the same "gone" shape a hard
        // delete would leave, without fighting the FK's cascadeOnDelete.
        $participant = sweepParticipant($orgA, $projectB, [
            'scheduled_at' => now()->subMinute(),
            'scheduling_status' => ParticipantSchedulingStatus::NoticeSent,
        ]);

        runSweep();
        runSweep();

        expect($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::Cancelled);
        Bus::assertNothingDispatched();
    });

    test('a project relation that no longer resolves at notice time is cancelled', function (): void {
        $orgA = sweepOrg();
        $orgB = sweepOrg();
        $projectB = sweepProject($orgB);
        $participant = sweepParticipant($orgA, $projectB, [
            'scheduled_at' => now()->addMinutes(10),
            'scheduling_status' => ParticipantSchedulingStatus::Pending,
        ]);

        runSweep();

        expect($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::Cancelled);
        Bus::assertNothingDispatched();
    });

    test('a project whose entry gates permanently refuse the mint is cancelled', function (): void {
        $org = sweepOrg();
        $project = sweepProject($org);
        // Project's own lifecycle guard forbids reverting 'active' -> 'draft',
        // so the gate is closed here via a past deadline instead:
        // EntryLinkMinter::mint() refuses with EntryLinkRefusalReason::Gates,
        // and no amount of retrying reopens a closed deadline.
        $project->update(['deadline_at' => now()->subDay()]);
        $participant = sweepParticipant($org, $project, [
            'scheduled_at' => now()->subMinute(),
            'scheduling_status' => ParticipantSchedulingStatus::NoticeSent,
        ]);

        runSweep();

        expect($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::Cancelled);
        Bus::assertNothingDispatched();
    });

});

describe('a transient failure never permanently cancels a backlogged participant', function (): void {
    test('a backlogged participant that hits a generic transient failure is left retryable, not cancelled, and still gets its email once the failure clears', function (): void {
        $org = sweepOrg();
        $project = sweepProject($org);
        // The exact backlog shape the command's own header treats as
        // first-class: worker down / deploy window, far past scheduled_at,
        // still NoticeSent. Cancelling this on its very first transient
        // failure is exactly the finding this test guards against.
        $participant = sweepParticipant($org, $project, [
            'scheduled_at' => now()->subHours(3),
            'scheduling_status' => ParticipantSchedulingStatus::NoticeSent,
        ]);

        Event::listen('eloquent.saved: '.Participant::class, function (): void {
            throw new RuntimeException('transient deadlock');
        });

        runSweep();

        expect($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::NoticeSent);
        Bus::assertNothingDispatched();

        Event::forget('eloquent.saved: '.Participant::class);

        runSweep();

        expect($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::Started);
        Bus::assertDispatchedTimes(SendCandidateInvitationJob::class, 1);
    });
});

describe('reporting without acting', function (): void {
    test('--dry-run names the due participants and changes nothing', function (): void {
        $org = sweepOrg();
        $project = sweepProject($org);
        $participant = sweepParticipant($org, $project, [
            'scheduled_at' => now()->subMinute(),
            'scheduling_status' => ParticipantSchedulingStatus::Pending,
        ]);

        runSweep(['--dry-run' => true]);

        expect(Artisan::output())->toContain($participant->candidate_ref);
        expect($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::Pending);
        Bus::assertNothingDispatched();
    });
});

describe('dispatch waits for the transaction to commit', function (): void {
    test('a failure while saving sendNotice\'s status advance leaves no notice queued and the row Pending', function (): void {
        $org = sweepOrg();
        $project = sweepProject($org);
        $participant = sweepParticipant($org, $project, [
            'scheduled_at' => now()->addMinutes(10),
            'scheduling_status' => ParticipantSchedulingStatus::Pending,
        ]);

        // Registered on the per-test dispatcher only (same pattern as
        // ResetUserPasswordCommandTest), so it does not leak across the suite.
        // It fires once the locked row's UPDATE has actually run inside
        // DB::transaction(), forcing a rollback AFTER the write but BEFORE the
        // command could ever see a successful commit.
        Event::listen('eloquent.saved: '.Participant::class, function (): void {
            throw new RuntimeException('deadlock victim');
        });

        runSweep();

        expect($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::Pending);
        Bus::assertNotDispatched(SendScheduledInterviewNoticeJob::class);
    });

    test('a failure while saving sendStart\'s status advance leaves no start email queued and the row unchanged', function (): void {
        $org = sweepOrg();
        $project = sweepProject($org);
        $participant = sweepParticipant($org, $project, [
            'scheduled_at' => now()->subMinute(),
            'scheduling_status' => ParticipantSchedulingStatus::NoticeSent,
        ]);

        Event::listen('eloquent.saved: '.Participant::class, function (): void {
            throw new RuntimeException('deadlock victim');
        });

        runSweep();

        expect($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::NoticeSent);
        Bus::assertNotDispatched(SendCandidateInvitationJob::class);
    });
});

describe('a dispatch that throws AFTER the status advance already committed', function (): void {
    test('sendNotice: the NoticeSent advance is kept, never reverted, and the failure is logged as a distinguishable, actionable error', function (): void {
        $org = sweepOrg();
        $project = sweepProject($org);
        $participant = sweepParticipant($org, $project, [
            'scheduled_at' => now()->addMinutes(10),
            'scheduling_status' => ParticipantSchedulingStatus::Pending,
        ]);

        Log::spy();
        bindThrowingDispatcher('queue backend unreachable');

        runSweep();

        // The DB::transaction() already committed NoticeSent before the
        // dispatch line ever ran — a post-commit dispatch failure must NOT
        // revert it (that would just trade this bug for a double-send race
        // against a concurrent tick).
        expect($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::NoticeSent);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'SILENT DATA LOSS')
                && $context['participant_id'] === $participant->id
                && $context['organization_id'] === $org->id
                && $context['project_id'] === $project->id
                && $context['email'] === $participant->email
                && $context['stage'] === 'notice'
                && $context['persisted_status'] === ParticipantSchedulingStatus::NoticeSent->value
                && $context['exception'] === 'queue backend unreachable');
    });

    test('sendStart: the Started advance is kept, never reverted, and the failure is logged as a distinguishable, actionable error', function (): void {
        $org = sweepOrg();
        $project = sweepProject($org);
        $participant = sweepParticipant($org, $project, [
            'scheduled_at' => now()->subMinute(),
            'scheduling_status' => ParticipantSchedulingStatus::NoticeSent,
        ]);

        Log::spy();
        bindThrowingDispatcher('queue backend unreachable');

        runSweep();

        expect($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::Started);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'SILENT DATA LOSS')
                && $context['participant_id'] === $participant->id
                && $context['organization_id'] === $org->id
                && $context['project_id'] === $project->id
                && $context['email'] === $participant->email
                && $context['stage'] === 'start'
                && $context['persisted_status'] === ParticipantSchedulingStatus::Started->value
                && $context['exception'] === 'queue backend unreachable');
    });
});

test('it reports how many of each email it sent', function (): void {
    $org = sweepOrg();
    $project = sweepProject($org);
    sweepParticipant($org, $project, [
        'scheduled_at' => now()->addMinutes(5),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);
    sweepParticipant($org, $project, [
        'scheduled_at' => now()->subMinute(),
        'scheduling_status' => ParticipantSchedulingStatus::NoticeSent,
    ]);

    $exit = runSweep();
    // Artisan::output() reads a BufferedOutput, which EMPTIES on fetch() —
    // a second call in the same test returns '', not the same string again.
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('1 notice(s)')
        ->and($output)->toContain('1 start email(s)');
});
