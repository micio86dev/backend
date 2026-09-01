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
        'email' => uniqid('cand-').'@example.test',
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

    // Dispatch (not handle()) — only the dispatch path triggers Queue::before,
    // matching production behavior (D5 dispatcher-based test discipline).
    ScoreEvaluationJob::dispatch($participantA->id);

    // The row the job actually created must carry org A — assert the row it
    // wrote, not merely the absence of an unrelated participant's row.
    $writtenEval = Evaluation::withoutGlobalScopes()
        ->where('participant_id', $participantA->id)
        ->first();

    expect($writtenEval)->not->toBeNull();
    expect($writtenEval->organization_id)->toBe($orgA->id,
        'ScoreEvaluationJob must stamp the participant\'s own org on the row it creates.'
    );

    // Org B must have no Evaluations.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgB->id);
    $resolver->setBypass(false);

    $orgBEvals = Evaluation::where('participant_id', $participantB->id)->count();
    expect($orgBEvals)->toBe(0, 'ScoreEvaluationJob for org A must not create Evaluation rows for org B.');
});

test('ScoreEvaluationJob creates Evaluation with correct organization_id from TenantScoped', function (): void {
    $orgA = crossTenantOrg();
    $orgB = crossTenantOrg();
    $projectA = crossTenantProject($orgA);
    $participantA = crossTenantParticipant($orgA, $projectA);

    // Hostile ambient: resolver holds a FOREIGN org (B), not org A, right before
    // dispatch. Queue::before resets it before handle() runs either way — this
    // proves the job never trusts leftover ambient state, foreign or not.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgB->id);
    $resolver->setBypass(false);

    ScoreEvaluationJob::dispatch($participantA->id);

    $eval = Evaluation::withoutGlobalScopes()
        ->where('participant_id', $participantA->id)
        ->first();

    expect($eval)->not->toBeNull();
    expect($eval->organization_id)->toBe($orgA->id,
        'Evaluation organization_id must match the participant organization, never the ambient (foreign) org.'
    );
});
