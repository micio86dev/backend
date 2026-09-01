<?php

declare(strict_types=1);

/**
 * ScoreEvaluationJob::failed() tests (C9 D9 CC5, Task 6.4).
 *
 * Verifies:
 * (a) failed() transitions participant in_valutazione → errore AND emits EvaluationFailed.
 * (b) failed() skips transition when participant already errore,
 *     but STILL emits EvaluationFailed.
 *
 * REQ: Catastrophic failure guard — failed() (C9 D9 CC5)
 */

use App\Events\EvaluationFailed;
use App\Jobs\ScoreEvaluationJob;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Event;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function c9FailedOrg(): Organization
{
    return Organization::factory()->create();
}

function c9FailedProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function c9FailedParticipant(Organization $org, Project $project, string $status): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'c9-failed-'.uniqid(),
        'display_name' => 'C9 Failed Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => $status,
    ]);
    $p->save();

    return $p->fresh();
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('(a) failed() transitions in_valutazione → errore and emits EvaluationFailed', function (): void {
    Event::fake([EvaluationFailed::class]);

    $org = c9FailedOrg();
    $project = c9FailedProject($org);
    $participant = c9FailedParticipant($org, $project, 'in_valutazione');

    $job = new ScoreEvaluationJob($participant->id);
    $job->failed(new RuntimeException('Simulated job failure'));

    // (a) Participant must be transitioned to errore.
    $fresh = Participant::find($participant->id);
    expect($fresh->status)->toBe('errore',
        'Participant must be transitioned to errore after failed().'
    );

    // EvaluationFailed must be emitted.
    Event::assertDispatched(EvaluationFailed::class, function (EvaluationFailed $event) use ($participant): bool {
        return $event->participantId === $participant->id;
    });
});

test('(b) failed() skips transition when participant already errore, but still emits EvaluationFailed', function (): void {
    Event::fake([EvaluationFailed::class]);

    $org = c9FailedOrg();
    $project = c9FailedProject($org);
    // Participant is ALREADY errore (simulate prior failure or race).
    $participant = c9FailedParticipant($org, $project, 'errore');

    $statusBefore = $participant->status;

    $job = new ScoreEvaluationJob($participant->id);
    $job->failed(new RuntimeException('Simulated second failure'));

    // Participant status must remain errore (no forbidden errore→errore transition attempt).
    $fresh = Participant::find($participant->id);
    expect($fresh->status)->toBe('errore',
        'Participant status must remain errore — no duplicate transition.'
    );

    // The participant lifecycle guard on Participant must NOT be violated.
    // (errore → errore is not a listed transition; the guard would throw if attempted.)

    // EvaluationFailed MUST still be emitted even though the transition was skipped.
    Event::assertDispatched(EvaluationFailed::class, function (EvaluationFailed $event) use ($participant): bool {
        return $event->participantId === $participant->id;
    });
});

test('failed() emits EvaluationFailed even when participant is not found', function (): void {
    Event::fake([EvaluationFailed::class]);

    $nonExistentParticipantId = 99999;

    $job = new ScoreEvaluationJob($nonExistentParticipantId);
    $job->failed(new RuntimeException('Simulated failure for missing participant'));

    // Even when participant cannot be found, EvaluationFailed must be emitted
    // so C10 is notified of the failure.
    Event::assertDispatched(EvaluationFailed::class, function (EvaluationFailed $event) use ($nonExistentParticipantId): bool {
        return $event->participantId === $nonExistentParticipantId;
    });
});
