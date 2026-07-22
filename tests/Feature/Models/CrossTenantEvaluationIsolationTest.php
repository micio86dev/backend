<?php

declare(strict_types=1);

/**
 * Cross-tenant Evaluation isolation tests (C9 spec, Task 6.6).
 *
 * Verifies: ScoreEvaluationJob for org A cannot read/write org B's
 * participants, sessions, or Evaluation rows.
 *
 * Correctness-critical zone (~95% coverage target for tenant scoping).
 *
 * REQ: Tenant Scoping requirement (C9 spec)
 */

use App\Enums\EvaluationStatus;
use App\Jobs\ScoreEvaluationJob;
use App\Models\Evaluation;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function crossTenantOrg(): Organization
{
    return Organization::factory()->create();
}

function crossTenantProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function crossTenantParticipant(Organization $org, Project $project, string $status = 'in_valutazione'): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'ct-'.uniqid(),
        'display_name' => 'Cross Tenant Test',
        'status' => $status,
    ]);
    $p->save();

    return $p->fresh();
}

function crossTenantEvaluation(Organization $org, Participant $participant, Project $project): Evaluation
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Evaluation::create([
        'participant_id' => $participant->id,
        'status' => EvaluationStatus::Processing->value,
        'framework_version_id' => $project->framework_version_id,
        'model_version' => 'test-model-v1',
        'prompt_version' => '1.0.0',
        'evaluated_at' => null,
        'retry_attempt' => false,
    ]);
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('Evaluation scoped to org A does NOT return org B evaluations', function (): void {
    $orgA = crossTenantOrg();
    $orgB = crossTenantOrg();

    $projectA = crossTenantProject($orgA);
    $projectB = crossTenantProject($orgB);
    $participantA = crossTenantParticipant($orgA, $projectA);
    $participantB = crossTenantParticipant($orgB, $projectB);

    $evalA = crossTenantEvaluation($orgA, $participantA, $projectA);
    $evalB = crossTenantEvaluation($orgB, $participantB, $projectB);

    // Verify the evaluations were created with correct organization_ids.
    $rawEvalA = Evaluation::withoutGlobalScopes()->find($evalA->id);
    $rawEvalB = Evaluation::withoutGlobalScopes()->find($evalB->id);

    expect($rawEvalA)->not->toBeNull();
    expect($rawEvalA->organization_id)->toBe($orgA->id,
        'Eval A must be stamped with org A id.'
    );
    expect($rawEvalB)->not->toBeNull();
    expect($rawEvalB->organization_id)->toBe($orgB->id,
        'Eval B must be stamped with org B id.'
    );

    // Switch to org A context.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    $visibleIds = Evaluation::all()->pluck('id');

    expect($visibleIds)->toContain($evalA->id);
    expect($visibleIds)->not->toContain($evalB->id);
});

test('Evaluation::find() for org B row returns null when scoped to org A', function (): void {
    $orgA = crossTenantOrg();
    $orgB = crossTenantOrg();

    $projectA = crossTenantProject($orgA);
    $projectB = crossTenantProject($orgB);
    $participantA = crossTenantParticipant($orgA, $projectA);
    $participantB = crossTenantParticipant($orgB, $projectB);

    $evalB = crossTenantEvaluation($orgB, $participantB, $projectB);

    // Switch to org A context.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    // Even with the correct ID, org A must not see org B evaluation.
    $found = Evaluation::find($evalB->id);
    expect($found)->toBeNull('org A must not find org B evaluations via find().');
});

test('ScoreEvaluationJob for org A participant does NOT create Evaluation for org B', function (): void {
    $orgA = crossTenantOrg();
    $orgB = crossTenantOrg();

    $projectA = crossTenantProject($orgA);
    $projectB = crossTenantProject($orgB);
    $participantA = crossTenantParticipant($orgA, $projectA);
    $participantB = crossTenantParticipant($orgB, $projectB);

    // Run job for org A participant.
    $job = new ScoreEvaluationJob($participantA->id);
    $job->handle();

    // Org B must have no Evaluations.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgB->id);
    $resolver->setBypass(false);

    $orgBEvals = Evaluation::where('participant_id', $participantB->id)->count();
    expect($orgBEvals)->toBe(0, 'ScoreEvaluationJob for org A must not create Evaluation rows for org B.');
});

test('ScoreEvaluationJob creates Evaluation with correct organization_id from TenantScoped', function (): void {
    $orgA = crossTenantOrg();
    $projectA = crossTenantProject($orgA);
    $participantA = crossTenantParticipant($orgA, $projectA);

    // Set resolver to org A before job runs.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    $job = new ScoreEvaluationJob($participantA->id);
    $job->handle();

    $eval = Evaluation::withoutGlobalScopes()
        ->where('participant_id', $participantA->id)
        ->first();

    expect($eval)->not->toBeNull();
    expect($eval->organization_id)->toBe($orgA->id,
        'Evaluation organization_id must match the participant organization.'
    );
});
