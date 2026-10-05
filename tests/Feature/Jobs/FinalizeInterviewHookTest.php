<?php

declare(strict_types=1);

/**
 * RED — Task 22.7: FinalizeInterview hook wiring tests (C9 D2 PR3 hook).
 *
 * Verifies:
 * - Calling FinalizeInterview for a participant in 'in_valutazione' emits ScoringRequested.
 * - ScoreEvaluationJob is dispatched exactly once via DispatchScoringJob listener.
 *
 * Refs spec: D2 "FinalizeInterview → event(ScoringRequested) → DispatchScoringJob → ScoreEvaluationJob".
 * Task 21.1/21.2/21.3: DispatchScoringJob listener, EventServiceProvider registration, FinalizeInterview hook.
 */

use App\Events\ScoringRequested;
use App\Jobs\FinalizeInterview;
use App\Jobs\ScoreEvaluationJob;
use App\Listeners\DispatchScoringJob;
use App\Models\Evaluation;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * @return array{0: Organization, 1: Participant}
 */
function finalizeHookParticipant(string $status = 'in_valutazione'): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active']);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'finalize-hook-org-'.uniqid(),
        'display_name' => 'FinalizeInterview Org Filter Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => $status,
    ]);
    $participant->save();

    return [$org, $participant->fresh()];
}

test('FinalizeInterview emits ScoringRequested and dispatches ScoreEvaluationJob once', function (): void {
    Queue::fake();
    Event::fake([ScoringRequested::class]);

    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active']);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'finalize-hook-'.uniqid(),
        'display_name' => 'FinalizeInterview Hook Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_valutazione',
    ]);
    $participant->save();
    $participant = $participant->fresh();

    // Run the FinalizeInterview job
    $finalizeJob = new FinalizeInterview($participant->id, $org->id);
    $finalizeJob->handle();

    // ScoringRequested event must be emitted
    Event::assertDispatched(ScoringRequested::class, function (ScoringRequested $e) use ($participant): bool {
        return $e->participantId === $participant->id;
    });
});

test('FinalizeInterview dispatches ScoreEvaluationJob via listener when events are not faked', function (): void {
    Queue::fake();

    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active']);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'finalize-dispatch-'.uniqid(),
        'display_name' => 'FinalizeInterview Dispatch Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_valutazione',
    ]);
    $participant->save();
    $participant = $participant->fresh();

    // Run the FinalizeInterview job WITHOUT Event::fake so the listener fires
    $finalizeJob = new FinalizeInterview($participant->id, $org->id);
    $finalizeJob->handle();

    // ScoreEvaluationJob should be dispatched exactly once via DispatchScoringJob listener
    Queue::assertPushed(ScoreEvaluationJob::class, 1);
    Queue::assertPushed(ScoreEvaluationJob::class, function (ScoreEvaluationJob $job) use ($participant): bool {
        // Access the participantId property via reflection (it's private)
        $ref = new ReflectionClass($job);
        $prop = $ref->getProperty('participantId');
        $prop->setAccessible(true);

        return $prop->getValue($job) === $participant->id;
    });
});

// review-reliability round-3 finding R3-org-filter-untested: an existing
// participant looked up under the WRONG organization must behave exactly
// like a not-found participant — never leak across the tenant boundary.

test('FinalizeInterview treats a participant looked up under the wrong organization as not found', function (): void {
    [, $participant] = finalizeHookParticipant('in_valutazione');
    $wrongOrg = Organization::factory()->create();

    Log::spy();

    $job = new FinalizeInterview($participant->id, $wrongOrg->id);
    $job->handle();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'FinalizeInterview: participant not found'
            && $context['participant_id'] === $participant->id
            && $context['organization_id'] === $wrongOrg->id
        );
});

test('DispatchScoringJob refuses (never dispatches ScoreEvaluationJob for) a wrong-organization lookup', function (): void {
    // review-reliability round-4 finding 2: G-53's original "fail open,
    // accepted trade-off" decision is REVERSED here. ScoreEvaluationJob
    // resolves its own Participant via withoutGlobalScopes() — fully
    // unscoped by design — so falling through to
    // ScoreEvaluationJob::dispatch() on an org-mismatch would let it score
    // (and, for a test-mode participant, BILL) that row anyway, completely
    // bypassing the org check this guard exists to enforce. Cost of failing
    // closed is near-zero: ScoringRequested only ever fires from
    // FinalizeInterview, which already confirmed this exact
    // (participantId, organizationId) pair resolves before firing it, so a
    // mismatch here can only mean a genuine anomaly, never the normal path.
    [, $participant] = finalizeHookParticipant('in_valutazione');
    $wrongOrg = Organization::factory()->create();

    Queue::fake();
    Log::spy();

    (new DispatchScoringJob)->handle(new ScoringRequested($participant->id, $wrongOrg->id));

    Queue::assertNotPushed(ScoreEvaluationJob::class);

    // review-reliability round-4 finding R3-dispatch-refusal-log-unasserted:
    // the refusal must be operator-visible, not just observable via a
    // missing side effect.
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'DispatchScoringJob: refusing — participant not found under the expected organization'
            && $context['participant_id'] === $participant->id
            && $context['organization_id'] === $wrongOrg->id
        );
});

test('DispatchScoringJob still dispatches ScoreEvaluationJob for the correct (same-organization) live-mode lookup', function (): void {
    // review-reliability round-4 finding R3-dispatch-refusal-log-unasserted:
    // the null-guard restructure (round 4) must not have broken the
    // ordinary happy path — a live-mode participant under its OWN
    // organization still reaches ScoreEvaluationJob::dispatch().
    [$org, $participant] = finalizeHookParticipant('in_valutazione');

    Queue::fake();

    (new DispatchScoringJob)->handle(new ScoringRequested($participant->id, $org->id));

    Queue::assertPushed(ScoreEvaluationJob::class, 1);
});

// ─── scoring-retry-rt-b (design D8, slice PR2a): the dispatch carries the retry flag ──

/** The `retryAttempt` payload of a queued ScoreEvaluationJob. */
function dispatchedRetryFlag(ScoreEvaluationJob $job): bool
{
    return (new ReflectionProperty($job, 'retryAttempt'))->getValue($job);
}

test('DispatchScoringJob passes retryAttempt=true when the persisted evaluation has retry_attempt', function (): void {
    [$org, $participant] = finalizeHookParticipant('in_valutazione');
    Evaluation::factory()->pending()->create([
        'participant_id' => $participant->id,
        'retry_attempt' => true,
    ]);
    Queue::fake();

    (new DispatchScoringJob)->handle(new ScoringRequested($participant->id, $org->id));

    Queue::assertPushed(ScoreEvaluationJob::class, 1);
    Queue::assertPushed(ScoreEvaluationJob::class, fn (ScoreEvaluationJob $job): bool => dispatchedRetryFlag($job) === true);
});

test('DispatchScoringJob passes retryAttempt=false for an evaluation without a retry and for no evaluation at all', function (): void {
    [$org, $withEvaluation] = finalizeHookParticipant('in_valutazione');
    Evaluation::factory()->pending()->create([
        'participant_id' => $withEvaluation->id,
        'retry_attempt' => false,
    ]);
    [$otherOrg, $withoutEvaluation] = finalizeHookParticipant('in_valutazione');
    Queue::fake();

    (new DispatchScoringJob)->handle(new ScoringRequested($withEvaluation->id, $org->id));
    (new DispatchScoringJob)->handle(new ScoringRequested($withoutEvaluation->id, $otherOrg->id));

    Queue::assertPushed(ScoreEvaluationJob::class, 2);
    Queue::assertPushed(ScoreEvaluationJob::class, fn (ScoreEvaluationJob $job): bool => dispatchedRetryFlag($job) === false);
    Queue::assertNotPushed(ScoreEvaluationJob::class, fn (ScoreEvaluationJob $job): bool => dispatchedRetryFlag($job) === true);
});

test('DispatchScoringJob ignores a retry flag on an evaluation row of another organization', function (): void {
    [$org, $participant] = finalizeHookParticipant('in_valutazione');
    $evaluation = Evaluation::factory()->pending()->create([
        'participant_id' => $participant->id,
        'retry_attempt' => true,
    ]);
    $foreignOrg = Organization::factory()->create();
    DB::table('evaluations')->where('id', $evaluation->id)->update(['organization_id' => $foreignOrg->id]);
    Queue::fake();

    (new DispatchScoringJob)->handle(new ScoringRequested($participant->id, $org->id));

    Queue::assertPushed(ScoreEvaluationJob::class, fn (ScoreEvaluationJob $job): bool => dispatchedRetryFlag($job) === false);
});
