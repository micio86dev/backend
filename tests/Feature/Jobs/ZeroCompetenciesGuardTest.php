<?php

declare(strict_types=1);

/**
 * RED — Task 22.6: ZeroCompetenciesGuard test (C9 D5 CC1 invariant guard).
 *
 * Verifies:
 * - Project with 0 project_competencies → job logs ERROR + marks participant 'errore'
 *   + does NOT emit EvaluationCompleted.
 *
 * Refs spec: D5 CC1 "Invariant guard: total_competencies == 0 → errore".
 */

use App\Events\EvaluationCompleted;
use App\Events\EvaluationFailed;
use App\Jobs\ScoreEvaluationJob;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('project with 0 project_competencies → participant errore, no EvaluationCompleted', function (): void {
    Event::fake([EvaluationCompleted::class, EvaluationFailed::class]);

    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active', 'language' => 'en']);

    // No competencies attached to project (0 project_competencies)
    expect($project->competencies()->count())->toBe(0);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'zero-comp-'.uniqid(),
        'display_name' => 'Zero Competencies Test',
        'status' => 'in_valutazione',
    ]);
    $participant->save();
    $participant = $participant->fresh();

    // Run the job
    $job = new ScoreEvaluationJob($participant->id);
    $job->handle();

    // Participant should be 'errore' (invariant violated → cannot evaluate gate)
    $updatedParticipant = Participant::withoutGlobalScopes()->find($participant->id);
    expect($updatedParticipant->status)->toBe('errore');

    // EvaluationCompleted must NOT be emitted
    Event::assertNotDispatched(EvaluationCompleted::class);
});
