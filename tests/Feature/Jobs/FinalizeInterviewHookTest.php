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
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

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
        'status' => 'in_valutazione',
    ]);
    $participant->save();
    $participant = $participant->fresh();

    // Run the FinalizeInterview job
    $finalizeJob = new FinalizeInterview($participant->id);
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
        'status' => 'in_valutazione',
    ]);
    $participant->save();
    $participant = $participant->fresh();

    // Run the FinalizeInterview job WITHOUT Event::fake so the listener fires
    $finalizeJob = new FinalizeInterview($participant->id);
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
