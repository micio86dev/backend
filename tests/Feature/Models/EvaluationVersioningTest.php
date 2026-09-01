<?php

declare(strict_types=1);

/**
 * Evaluation versioning field tests (C9 spec, Task 6.5).
 *
 * Verifies:
 * - framework_version_id, model_version, prompt_version are non-null on a created Evaluation.
 * - evaluated_at is null while status = processing.
 *
 * REQ: Evaluation Versioning requirement (C9 spec)
 */

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;

function versioningOrg(): Organization
{
    return Organization::factory()->create();
}

function versioningProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function versioningParticipant(Organization $org, Project $project): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'ver-'.uniqid(),
        'display_name' => 'Versioning Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_valutazione',
    ]);
    $p->save();

    return $p->fresh();
}

test('Evaluation versioning fields (framework_version_id, model_version, prompt_version) are non-null', function (): void {
    $org = versioningOrg();
    $project = versioningProject($org);
    $participant = versioningParticipant($org, $project);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $evaluation = Evaluation::create([
        'participant_id' => $participant->id,
        'status' => EvaluationStatus::Processing->value,
        'framework_version_id' => $project->framework_version_id,
        'model_version' => 'test-model-v1',
        'prompt_version' => '1.0.0',
        'evaluated_at' => null,
        'retry_attempt' => false,
    ]);

    $fresh = Evaluation::withoutGlobalScopes()->find($evaluation->id);

    expect($fresh->framework_version_id)->not->toBeNull('framework_version_id must not be null.');
    expect($fresh->model_version)->not->toBeNull()->not->toBeEmpty('model_version must not be null or empty.');
    expect($fresh->prompt_version)->not->toBeNull()->not->toBeEmpty('prompt_version must not be null or empty.');
});

test('evaluated_at is null while Evaluation status is processing', function (): void {
    $org = versioningOrg();
    $project = versioningProject($org);
    $participant = versioningParticipant($org, $project);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $evaluation = Evaluation::create([
        'participant_id' => $participant->id,
        'status' => EvaluationStatus::Processing->value,
        'framework_version_id' => $project->framework_version_id,
        'model_version' => 'test-model-v1',
        'prompt_version' => '1.0.0',
        'evaluated_at' => null,
        'retry_attempt' => false,
    ]);

    $fresh = Evaluation::withoutGlobalScopes()->find($evaluation->id);

    expect($fresh->status)->toBe(EvaluationStatus::Processing);
    expect($fresh->evaluated_at)->toBeNull('evaluated_at must be null while status is processing.');
});
