<?php

declare(strict_types=1);

/**
 * SPEC.md §3.7 "Test mode" — the mock avatar provider (public-api step 9).
 *
 * T-TEST-001..006, one `test()` per scenario:
 *   001 — no outbound HTTP call to a real avatar provider.
 *   002 — the real lifecycle walks pending → in_progress → under_evaluation
 *         → completed (proven both by the terminal status AND by the
 *         recorded InterviewEvent sequence — see that test's own docblock
 *         for why a sequence assertion is the honest way to prove "no state
 *         skipped" under QUEUE_CONNECTION=sync, where the whole interview
 *         completes inline before the HTTP response returns).
 *   003 — fabricated BARS scoring satisfies the §3.3 shape.
 *   004 — both progress and evaluation webhooks fire with livemode=false.
 *   005 — test-mode activity is excluded from a live-key /v1/usage read.
 *   006 — a live-mode interview is never routed to MockProvider.
 *
 * Fixtures mirror `tests/Feature/C7a/InterviewStartTest.php`'s own
 * `startProject()`/`startProjectWithCompetencies()`/`startParticipant()`/
 * `startBearer()` helpers (composition — Role + BarsIndicator +
 * ProjectQuestion per competency — is required on EVERY /start() call,
 * mock provider included: `InterviewController::start()` composes the
 * system prompt before any provider is resolved). Declared under
 * `mockMode*` names, never the SAME global names `InterviewStartTest.php`
 * already declares, since Pest loads every test file's top-level `function`
 * into one shared global namespace for a full suite run.
 */

use App\Actions\Interview\SettleParticipantCompletion;
use App\Enums\ApiKeyMode;
use App\Jobs\PublicApi\RunMockInterviewJob;
use App\Models\AvatarTemplate;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\InterviewEvent;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLivePeriod;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\Role;
use App\Models\WebhookDelivery;
use App\Services\Provider\HeygenProvider;
use App\Services\Provider\MockProvider;
use App\Services\Provider\ProviderSessionService;
use App\Support\Interview\SessionLiveClock;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\PublicApi\UsageAggregator;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function mockModeOrg(): Organization
{
    return Organization::factory()->create();
}

/**
 * @return array{0: Project, 1: list<Competency>}
 */
function mockModeProjectWithCompetencies(Organization $org, int $count = 2, bool $withWebhook = false): array
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $avatarTemplate = AvatarTemplate::create(['name' => 'Mock fixture', 'provider' => 'heygen', 'config' => []]);

    $attrs = [
        'status' => 'active',
        'avatar_template_id' => $avatarTemplate->id,
    ];

    // webhook_url is null by default (ProjectFactory's own default) — kept
    // unset for every test EXCEPT T-TEST-004, so the other scenarios never
    // make an incidental outbound webhook-delivery HTTP call that would
    // confuse T-TEST-001's "no avatar-provider HTTP call" assertion with
    // an unrelated HTTP call this fixture itself introduced.
    if ($withWebhook) {
        $attrs['webhook_url'] = 'https://receiver.example.test/hook';
        $attrs['webhook_secret'] = 'whsec_mock_mode_test_secret';
        $attrs['webhook_events'] = ['progress', 'evaluation'];
    }

    $project = Project::factory()->create($attrs);

    $role = Role::factory()->create(['code' => $project->role_code]);

    $competencies = [];
    for ($i = 0; $i < $count; $i++) {
        $comp = Competency::factory()->create();
        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $comp->id,
            'position' => $i,
        ]);

        $ind = new BarsIndicator;
        $ind->forceFill([
            'role_id' => $role->id,
            'competency_id' => $comp->id,
            'text' => ['en' => "mock fixture indicator {$i}"],
            'anchor_5' => ['en' => "Excellent {$i}"],
            'anchor_3' => ['en' => "Adequate {$i}"],
            'anchor_1' => ['en' => "Insufficient {$i}"],
            'position' => 0,
        ]);
        $ind->save();

        ProjectQuestion::create([
            'project_id' => $project->id,
            'competency_id' => $comp->id,
            'text' => ['en' => "mock fixture question {$i}"],
            'position' => 0,
        ]);

        $competencies[] = $comp;
    }

    return [$project, $competencies];
}

function mockModeParticipant(Organization $org, Project $project, ApiKeyMode $mode): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'mock-'.uniqid(),
        'display_name' => 'Mock Mode Candidate',
        'email' => uniqid('mock-').'@example.test',
        'status' => 'in_attesa',
        'mode' => $mode,
    ]);
    $p->save();

    return $p->fresh();
}

function mockModeBearer(Participant $participant): string
{
    return CandidateTokenFactory::mintCandidateToken($participant);
}

function mockModeHeygenSuccessResponse(): array
{
    return [
        '*liveavatar*/contexts*' => Http::response(['data' => ['context_id' => 'ctx-mock-001']], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => [
                'session_id' => 'heygen-session-'.uniqid(),
                'session_token' => 'heygen-token-'.uniqid(),
                'url' => 'https://webrtc.heygen.com/test',
            ],
        ], 200),
        '*liveavatar*/sessions/*' => Http::response([], 200), // teardown
    ];
}

// ─── T-TEST-001 ─────────────────────────────────────────────────────────────

test('a test-mode interview never makes an outbound HTTP call to a real avatar provider', function (): void {
    $org = mockModeOrg();
    [$project] = mockModeProjectWithCompetencies($org, 1);
    $participant = mockModeParticipant($org, $project, ApiKeyMode::Test);

    // Faked but never matched by anything a MockProvider-routed /start()
    // does — assertNothingSent() below is what actually proves it.
    Http::fake();

    $response = test()->withHeaders(['Authorization' => 'Bearer '.mockModeBearer($participant)])
        ->postJson('/api/candidate/interview/start');

    $response->assertCreated();
    $response->assertJsonPath('provider', 'mock');

    Http::assertNothingSent();

    expect(InterviewSession::first()->provider)->toBe('mock');
});

// ─── T-TEST-002 ─────────────────────────────────────────────────────────────

test('a test-mode interview walks the real lifecycle pending to in_progress to under_evaluation to completed', function (): void {
    $org = mockModeOrg();
    [$project] = mockModeProjectWithCompetencies($org, 2);
    $participant = mockModeParticipant($org, $project, ApiKeyMode::Test);

    expect($participant->status)->toBe('in_attesa'); // pending

    Http::fake();

    $response = test()->withHeaders(['Authorization' => 'Bearer '.mockModeBearer($participant)])
        ->postJson('/api/candidate/interview/start');

    $response->assertCreated();

    // Everything above ran synchronously (QUEUE_CONNECTION=sync in tests),
    // so by the time the HTTP response returns the WHOLE scripted interview
    // — every competency, the CAS to in_valutazione, the fabricated scoring,
    // the CAS to completato — has already happened inline. The terminal
    // status alone would not prove no state was SKIPPED (a bug could jump
    // straight from in_attesa to completato); Participant's own transition
    // guard (`ParticipantTransitionException`) already makes that
    // structurally impossible (in_attesa's only allowed edges are
    // in_corso/errore), and the persisted InterviewEvent sequence below is
    // the observable proof that every intermediate seam actually fired, in
    // order, rather than merely being unreachable.
    $participant->refresh();
    expect($participant->status)->toBe('completato');

    $types = InterviewEvent::where('participant_id', $participant->id)
        ->orderBy('id')
        ->pluck('type')
        ->all();

    expect($types)->toContain('session_started')
        ->and($types)->toContain('under_evaluation')
        ->and($types)->toContain('transcript_ready')
        ->and($types)->toContain('completed')
        ->and($types)->toContain('scoring_ready');

    // Order proof: session_started (in_progress) precedes under_evaluation,
    // which precedes completed — the pending/in_progress/under_evaluation/
    // completed order the spec names, never a shuffled or skipped one.
    $indexOf = fn (string $type): int => array_search($type, $types, true);
    expect($indexOf('session_started'))->toBeLessThan($indexOf('under_evaluation'))
        ->and($indexOf('under_evaluation'))->toBeLessThan($indexOf('completed'));

    // pre-commit gate finding 3: every InterviewSession this run created —
    // both the one the controller opened a live period for AND every one
    // RunMockInterviewJob itself opened for the 2nd+ competency — must have
    // its live period CLOSED by the time the interview reaches completato,
    // or the D5 "at most one open period" invariant is silently violated
    // forever (a mock interview is the only kind that never calls /end,
    // the real path's own close() site).
    $sessionIds = InterviewSession::where('participant_id', $participant->id)->pluck('id');
    expect($sessionIds)->toHaveCount(2);

    $openPeriods = InterviewSessionLivePeriod::whereIn('interview_session_id', $sessionIds)
        ->whereNull('ended_at')
        ->count();

    expect($openPeriods)->toBe(0);
});

// ─── T-TEST-003 ─────────────────────────────────────────────────────────────

test('the fabricated BARS scoring satisfies the §3.3 shape', function (): void {
    $org = mockModeOrg();
    [$project, $competencies] = mockModeProjectWithCompetencies($org, 2);
    $participant = mockModeParticipant($org, $project, ApiKeyMode::Test);

    Http::fake();

    test()->withHeaders(['Authorization' => 'Bearer '.mockModeBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertCreated();

    $evaluation = Evaluation::where('participant_id', $participant->id)->first();

    expect($evaluation)->not->toBeNull()
        ->and($evaluation->framework_version_id)->not->toBeNull()
        ->and($evaluation->model_version)->not->toBeNull()->not->toBe('')
        ->and($evaluation->prompt_version)->not->toBeNull()->not->toBe('')
        ->and($evaluation->evaluated_at)->not->toBeNull();

    $results = CompetencyResult::where('evaluation_id', $evaluation->id)->get();
    expect($results)->toHaveCount(count($competencies));

    foreach ($results as $result) {
        $session = InterviewSession::where('participant_id', $participant->id)
            ->where('competency_code', $result->competency_code)
            ->firstOrFail();
        $candidateAnswer = $session->utterances()->where('speaker', 'candidate')->value('text');

        $indicatorScores = IndicatorScore::where('competency_result_id', $result->id)
            ->orderBy('position')
            ->get();

        // "exactly 3 behaviours" (§3.3).
        expect($indicatorScores)->toHaveCount(3);

        foreach ($indicatorScores as $indicatorScore) {
            // Score enum {1,2,3,4,5,-1}; the mock path never fabricates -1.
            expect($indicatorScore->score)->toBeGreaterThanOrEqual(1)
                ->and($indicatorScore->score)->toBeLessThanOrEqual(5)
                ->and($indicatorScore->unassessable_reason)->toBeNull();

            // Verbatim substring containment — not just "present-looking".
            foreach ($indicatorScore->excerpts as $excerpt) {
                expect(str_contains((string) $candidateAnswer, $excerpt))->toBeTrue();
            }
        }
    }
});

// ─── T-TEST-004 ─────────────────────────────────────────────────────────────

test('both progress and evaluation webhooks fire for the mock run with livemode false', function (): void {
    $org = mockModeOrg();
    [$project] = mockModeProjectWithCompetencies($org, 1, withWebhook: true);
    $participant = mockModeParticipant($org, $project, ApiKeyMode::Test);

    Http::fake(); // fakes the OUTBOUND webhook delivery HTTP call too — no real receiver needed

    test()->withHeaders(['Authorization' => 'Bearer '.mockModeBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertCreated();

    $progressDelivery = WebhookDelivery::where('participant_id', $participant->id)
        ->where('event_type', 'progress')
        ->first();
    $evaluationDelivery = WebhookDelivery::where('participant_id', $participant->id)
        ->where('event_type', 'evaluation')
        ->first();

    expect($progressDelivery)->not->toBeNull()
        ->and($progressDelivery->payload['livemode'])->toBeFalse()
        ->and($evaluationDelivery)->not->toBeNull()
        ->and($evaluationDelivery->payload['livemode'])->toBeFalse();
});

// ─── T-TEST-005 ─────────────────────────────────────────────────────────────

test('a test-mode interview is never counted when the same organization is queried with a live key', function (): void {
    $org = mockModeOrg();
    [$project] = mockModeProjectWithCompetencies($org, 1);
    $participant = mockModeParticipant($org, $project, ApiKeyMode::Test);

    Http::fake();

    test()->withHeaders(['Authorization' => 'Bearer '.mockModeBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertCreated();

    $participant->refresh();
    expect($participant->status)->toBe('completato');

    $from = Carbon::now()->subMonth()->toImmutable();
    $to = Carbon::now()->addMonth()->toImmutable();

    $liveUsage = UsageAggregator::build($org, ApiKeyMode::Live, $from, $to);
    $testUsage = UsageAggregator::build($org, ApiKeyMode::Test, $from, $to);

    expect($liveUsage['interviews']['completed'])->toBe(0)
        ->and(array_sum($liveUsage['interviews']))->toBe(0)
        ->and($testUsage['interviews']['completed'])->toBe(1);
});

// ─── T-TEST-006 ─────────────────────────────────────────────────────────────

test('a live-mode interview is never routed to MockProvider', function (): void {
    $org = mockModeOrg();
    [$project] = mockModeProjectWithCompetencies($org, 1);
    $participant = mockModeParticipant($org, $project, ApiKeyMode::Live);

    Http::fake(mockModeHeygenSuccessResponse());

    $response = test()->withHeaders(['Authorization' => 'Bearer '.mockModeBearer($participant)])
        ->postJson('/api/candidate/interview/start');

    $response->assertCreated();
    $response->assertJsonPath('provider', 'heygen');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'liveavatar'));

    expect(InterviewSession::first()->provider)->toBe('heygen');

    // Container-binding boundary (InterviewServiceProvider's own
    // defense-in-depth) — a live-mode candidate request must never resolve
    // MockProvider through the interface either. actingAs() sets the user
    // directly on the 'api-candidate' guard instance (deterministic,
    // independent of whatever the prior real HTTP call left the auth
    // manager holding), so this is a clean re-check of the SAME closure
    // the HTTP request above exercised indirectly.
    test()->actingAs($participant, 'api-candidate');
    expect(app(ProviderSessionService::class))->toBeInstanceOf(HeygenProvider::class)
        ->not->toBeInstanceOf(MockProvider::class);
});

// ─── Robustness (review-resilience/review-reliability round 3) ────────────
//
// R4-mockjob-check-then-act / R3-mock-job-nonatomic-idempotency: the
// controller dispatches a NEW RunMockInterviewJob on every test-mode
// /start(), so a genuinely concurrent second run must never duplicate the
// job's own scripted work — proven here by pre-seeding the lock the job
// itself acquires, the same observable effect a real second in-flight run
// would have.

test('a concurrent RunMockInterviewJob run is a safe no-op when the lock is already held', function (): void {
    $org = mockModeOrg();
    [$project] = mockModeProjectWithCompetencies($org, 1);
    $participant = mockModeParticipant($org, $project, ApiKeyMode::Test);

    Cache::put('mock-interview:'.$participant->id, true, 300);

    Http::fake();

    test()->withHeaders(['Authorization' => 'Bearer '.mockModeBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertCreated();

    // The controller's own /start() write still happens — it runs BEFORE
    // the job is dispatched. Only the job's own scripted work is skipped:
    // the one session /start() opened never gets a transcript, and the
    // interview never advances past it.
    $participant->refresh();
    expect($participant->status)->toBe('in_corso');

    $session = InterviewSession::where('participant_id', $participant->id)->sole();
    expect($session->status)->toBe('in_corso')
        ->and($session->utterances()->count())->toBe(0);

    expect(Evaluation::where('participant_id', $participant->id)->exists())->toBeFalse();
});

// R3-lock-never-released: the lock must not outlive the run that acquired
// it — only a genuinely OVERLAPPING run should ever find it held.

test('the lock is released once a successful run completes, so a later re-dispatch runs again safely', function (): void {
    $org = mockModeOrg();
    [$project] = mockModeProjectWithCompetencies($org, 1);
    $participant = mockModeParticipant($org, $project, ApiKeyMode::Test);

    Http::fake();

    test()->withHeaders(['Authorization' => 'Bearer '.mockModeBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertCreated();

    $participant->refresh();
    expect($participant->status)->toBe('completato');
    expect(Cache::has('mock-interview:'.$participant->id))->toBeFalse();

    // A genuine re-dispatch after completion is not blocked by a stale
    // lock — it reaches (and safely no-ops through) idempotency layer 1.
    (new RunMockInterviewJob($org->id, $participant->id))
        ->handle(app(SettleParticipantCompletion::class), app(SessionLiveClock::class));

    $participant->refresh();
    expect($participant->status)->toBe('completato');
    expect(Evaluation::where('participant_id', $participant->id)->count())->toBe(1);
});

test('the lock is released after an early business no-op, so a fixed re-dispatch can still finish the interview', function (): void {
    $org = mockModeOrg();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    // No mockModeProjectWithCompetencies() call — zero competencies
    // attached, forcing runScriptedInterview()'s early "nothing to mock"
    // exit, well before any lifecycle work happens.
    $project = Project::factory()->create(['status' => 'active']);
    $participant = mockModeParticipant($org, $project, ApiKeyMode::Test);

    (new RunMockInterviewJob($org->id, $participant->id))
        ->handle(app(SettleParticipantCompletion::class), app(SessionLiveClock::class));

    $participant->refresh();
    expect($participant->status)->toBe('in_attesa');
    expect(Cache::has('mock-interview:'.$participant->id))->toBeFalse();
});

// R4-mockjob-no-recovery: a mid-run exception (object-storage error, DB
// error) after settleIfFinished() already moved the participant to
// in_valutazione must not strand it forever — DispatchScoringJob never
// dispatches the real ScoreEvaluationJob for a test-mode participant, so
// nothing else would ever retry scoring it.

test('RunMockInterviewJob::failed() flips a stranded in_valutazione participant to errore', function (): void {
    $org = mockModeOrg();
    [$project] = mockModeProjectWithCompetencies($org, 1);
    $participant = mockModeParticipant($org, $project, ApiKeyMode::Test);

    // Participant::$allowedTransitions has no in_attesa -> in_valutazione
    // edge — walk the real chain, same as the guard requires everywhere else.
    $participant->status = 'in_corso';
    $participant->save();
    $participant->status = 'in_valutazione';
    $participant->save();

    (new RunMockInterviewJob($org->id, $participant->id))->failed(new RuntimeException('simulated storage failure'));

    $participant->refresh();
    expect($participant->status)->toBe('errore');
});

test('RunMockInterviewJob::failed() is a no-op for an already-terminal participant', function (): void {
    $org = mockModeOrg();
    [$project] = mockModeProjectWithCompetencies($org, 1);
    $participant = mockModeParticipant($org, $project, ApiKeyMode::Test);

    $participant->status = 'in_corso';
    $participant->save();
    $participant->status = 'in_valutazione';
    $participant->save();
    $participant->status = 'completato';
    $participant->save();

    (new RunMockInterviewJob($org->id, $participant->id))->failed(new RuntimeException('simulated failure after completion'));

    $participant->refresh();
    expect($participant->status)->toBe('completato');
});

// R3-transaction-atomicity-untested: fabricateScoring() is one
// DB::transaction() (round 4 finding 3) — prove the rollback, not just
// assert the wrapping exists.

test('a mid-transaction failure in fabricateScoring() rolls back completely, leaving no partial rows', function (): void {
    $org = mockModeOrg();
    [$project, $competencies] = mockModeProjectWithCompetencies($org, 2);
    $participant = mockModeParticipant($org, $project, ApiKeyMode::Test);

    // Walk directly to the state runScriptedInterview()'s loop skips past
    // (both competencies already ended) so the only thing left to run is
    // fabricateScoring() itself — never going through the job's own
    // scripted loop, so nothing else in this test can trip the failure.
    foreach ($competencies as $competency) {
        InterviewSession::create([
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => 0,
            'competency_code' => $competency->code,
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'mock',
            'status' => 'completed',
            'ended_reason' => 'completed',
            'started_at' => now(),
            'ended_at' => now(),
        ]);
    }
    $participant->status = 'in_corso';
    $participant->save();
    $participant->status = 'in_valutazione';
    $participant->save();

    $seen = 0;
    CompetencyResult::creating(function () use (&$seen): void {
        $seen++;

        if ($seen === 2) {
            throw new RuntimeException('simulated DB failure on the second competency');
        }
    });

    $job = new RunMockInterviewJob($org->id, $participant->id);

    // Direct handle() call, never through HTTP — the exception must reach
    // this test uncaught, not be absorbed by the framework's HTTP
    // exception handler.
    expect(fn () => $job->handle(app(SettleParticipantCompletion::class), app(SessionLiveClock::class)))
        ->toThrow(RuntimeException::class);

    expect(Evaluation::where('participant_id', $participant->id)->exists())->toBeFalse()
        ->and(CompetencyResult::count())->toBe(0)
        ->and(IndicatorScore::count())->toBe(0);
});
