<?php

declare(strict_types=1);

/**
 * RED — beai:reap-stale-interviews (stale-interview-reaper).
 *
 * Observed in production 2026-09-08: a participant started an interview at
 * 10:52, thirty-two utterances of a real conversation were captured, and two
 * hours later the session was still `in_corso` with a null `ended_at` and the
 * participant still `in_corso`. Nothing would ever have scored it — the
 * scheduler runs four jobs and none of them looks at an interview.
 *
 * `POST /end` is the only thing that ends a session, so the whole chain rests
 * on the candidate's tab living long enough to make one more HTTP call. The
 * ways it does not are ordinary: the avatar never speaks its closing phrase,
 * the tab is closed, the laptop sleeps. In every one of them the transcript is
 * already safe on the server; only the sentence saying "this is over" is
 * missing.
 */

use App\Jobs\FinalizeInterview;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{org: Organization, project: Project, participant: Participant, session: InterviewSession}
 */
function reaperScenario(int $lastActivityMinutesAgo, bool $candidateSpoke = true): array
{
    $org = Organization::factory()->create();

    // Tenant context for the FIXTURE only. `Project::factory()` reaches
    // FrameworkVersion, which is tenant-scoped and refuses to be created
    // without one — the same explicit-context rule every seeding helper in
    // this suite follows. The command under test establishes none, and must
    // not: it is a platform-wide sweep acting for nobody.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId((int) $org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['organization_id' => $org->id]);
    $participant = Participant::factory()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'status' => 'in_corso',
    ]);

    $session = InterviewSession::factory()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'framework_version_id' => $project->framework_version_id,
        'participant_id' => $participant->id,
        'status' => 'in_corso',
        'ended_at' => null,
        'started_at' => now()->subMinutes($lastActivityMinutesAgo + 5),
    ]);

    // The avatar's opening is written by the SERVER at /start, so it is present
    // even on a session where the candidate never said anything.
    Utterance::create([
        'interview_session_id' => $session->id,
        'organization_id' => $org->id,
        'speaker' => 'avatar',
        'text' => 'Ciao, e benvenuto!',
        'ts' => now()->subMinutes($lastActivityMinutesAgo + 4),
    ]);

    if ($candidateSpoke) {
        Utterance::create([
            'interview_session_id' => $session->id,
            'organization_id' => $org->id,
            'speaker' => 'candidate',
            'text' => 'Ti racconto un episodio.',
            'ts' => now()->subMinutes($lastActivityMinutesAgo),
        ]);
    }

    return ['org' => $org, 'project' => $project, 'participant' => $participant, 'session' => $session];
}

beforeEach(function (): void {
    Queue::fake();
    config(['interview.stale_after_minutes' => 30]);
});

/**
 * Run the command the way the SCHEDULER runs it: with no ambient tenant
 * context whatsoever.
 *
 * This is the whole point of the helper. The fixtures above must establish
 * context to create tenant-scoped rows at all, and leaving it set made every
 * test here pass for the wrong reason — the command inherited a context it
 * would never have in production, where `TenantResolver` defaults to
 * orgId=null with bypass off and `organization_id = NULL` is never true in
 * SQL. Clearing it first is what turns these into evidence.
 */
function runReaper(array $arguments = []): int
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(null);
    $resolver->setBypass(false);

    return Artisan::call('beai:reap-stale-interviews', $arguments);
}

describe('what it ends', function (): void {
    test('a silent session is ended as timeout, with the same writes /end makes', function (): void {
        $scenario = reaperScenario(lastActivityMinutesAgo: 120);

        runReaper();

        $session = $scenario['session']->fresh();
        expect($session->status)->toBe('timeout');
        expect($session->ended_reason)->toBe('timeout');
        expect($session->ended_at)->not->toBeNull();
    });

    test('an ACTIVE session is left alone', function (): void {
        // A candidate thinking before they answer is not an abandoned session,
        // and a reaper that ends a live conversation is worse than the
        // stranding it fixes.
        $scenario = reaperScenario(lastActivityMinutesAgo: 2);

        runReaper();

        expect($scenario['session']->fresh()->status)->toBe('in_corso');
        expect($scenario['participant']->fresh()->status)->toBe('in_corso');
    });

    test('a LONG but active interview survives — staleness is measured from activity', function (): void {
        // Started four hours ago, last utterance a minute ago. Measuring from
        // `started_at` would kill an interview that is still going.
        $scenario = reaperScenario(lastActivityMinutesAgo: 1);
        $scenario['session']->update(['started_at' => now()->subHours(4)]);

        runReaper();

        expect($scenario['session']->fresh()->status)->toBe('in_corso');
    });

    test('a session that already ended is not touched again', function (): void {
        $scenario = reaperScenario(lastActivityMinutesAgo: 120);
        $endedAt = now()->subHours(3);
        $scenario['session']->update(['status' => 'completed', 'ended_at' => $endedAt]);

        runReaper();

        expect($scenario['session']->fresh()->status)->toBe('completed');
    });
});

describe('what happens to the participant', function (): void {
    test('a partial interview is SCORED, not discarded', function (): void {
        // The whole point. Thirty-two answered questions must not be thrown
        // away because no final POST arrived — the completion gate is built
        // for exactly this and reports it as partial.
        $scenario = reaperScenario(lastActivityMinutesAgo: 120);

        runReaper();

        expect($scenario['participant']->fresh()->status)->toBe('in_valutazione');
        Queue::assertPushed(FinalizeInterview::class);
    });

    test('an interview with NO candidate speech fails, recoverably', function (): void {
        // Nothing to score. An evaluation assembled from the avatar's own
        // opening line would be worse than a reported failure.
        $scenario = reaperScenario(lastActivityMinutesAgo: 120, candidateSpoke: false);

        runReaper();

        expect($scenario['participant']->fresh()->status)->toBe('errore');
        Queue::assertNotPushed(FinalizeInterview::class);
    });

    test('a participant already past in_corso is never overwritten', function (): void {
        $scenario = reaperScenario(lastActivityMinutesAgo: 120);
        // `in_valutazione`, not `completato`: the lifecycle refuses
        // `in_corso -> completato` directly, and the state this guards against
        // is anyone who has already LEFT `in_corso` — which is what the
        // compare-and-set keys on.
        $scenario['participant']->update(['status' => 'in_valutazione']);

        runReaper();

        expect($scenario['participant']->fresh()->status)->toBe('in_valutazione');
        Queue::assertNotPushed(FinalizeInterview::class);
    });

    test('scoring is dispatched ONCE even when several of their sessions are stale', function (): void {
        $scenario = reaperScenario(lastActivityMinutesAgo: 120);
        InterviewSession::factory()->create([
            'organization_id' => $scenario['org']->id,
            'project_id' => $scenario['project']->id,
            'framework_version_id' => $scenario['project']->framework_version_id,
            'participant_id' => $scenario['participant']->id,
            // A DIFFERENT competency: (participant_id, competency_code) is
            // unique, which is itself the rule that one competency is
            // interviewed once.
            'competency_code' => 'PRS',
            'status' => 'in_corso',
            'ended_at' => null,
            'started_at' => now()->subHours(3),
        ]);

        runReaper();

        // The compare-and-set is what guarantees this: the second settle finds
        // no `in_corso` row and is a no-op rather than a second job.
        Queue::assertPushed(FinalizeInterview::class, 1);
    });
});

describe('reporting without acting', function (): void {
    test('--dry-run names the sessions and changes nothing', function (): void {
        // It writes to two tables and spends money at a vendor. The first run
        // in any environment must be able to say what it would do.
        $scenario = reaperScenario(lastActivityMinutesAgo: 120);

        runReaper(['--dry-run' => true]);

        expect(Artisan::output())->toContain((string) $scenario['session']->id);
        expect($scenario['session']->fresh()->status)->toBe('in_corso');
        expect($scenario['participant']->fresh()->status)->toBe('in_corso');
        Queue::assertNothingPushed();
    });
});

test('it reports how many sessions it ended', function (): void {
    reaperScenario(lastActivityMinutesAgo: 120);
    reaperScenario(lastActivityMinutesAgo: 200);
    reaperScenario(lastActivityMinutesAgo: 1);

    $exit = runReaper();

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('2');
});

describe('refusing an unusable threshold', function (): void {
    test('a non-numeric env value is refused, not treated as zero', function (): void {
        // `(int) 'abc'` is 0, and a zero threshold is `now()` — every live
        // interview on the platform ended mid-conversation on the next tick.
        config(['interview.stale_after_minutes' => 'abc']);
        $scenario = reaperScenario(lastActivityMinutesAgo: 2);

        $exit = runReaper();

        expect($exit)->toBe(1);
        expect($scenario['session']->fresh()->status)->toBe('in_corso');
        Queue::assertNothingPushed();
    });

    test('zero is refused too', function (): void {
        config(['interview.stale_after_minutes' => 0]);
        $scenario = reaperScenario(lastActivityMinutesAgo: 1);

        expect(runReaper())->toBe(1);
        expect($scenario['session']->fresh()->status)->toBe('in_corso');
    });
});
