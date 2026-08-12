<?php

declare(strict_types=1);

/**
 * EvaluationIndexQuery — the ONE shared builder behind both `/evaluations`
 * and `/evaluations/summary` (backoffice-missing-pages D6/D7).
 *
 * The lifecycle gate is a JOIN PREDICATE: a participant below `completato`
 * is structurally ABSENT from the result set, never merely null-scored.
 *
 * REQ: Lifecycle Read-Gate Applies To The Evaluations Index And Summary
 *      (openspec/changes/backoffice-missing-pages/specs/admin-read-api/spec.md)
 */

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Admin\EvaluationIndexFilters;
use App\Support\Admin\EvaluationIndexQuery;
use App\Support\Tenancy\TenantResolver;

function evalIndexParticipant(Project $project, string $status): Participant
{
    return Participant::factory()->forProject($project)->withStatus($status)->create();
}

function evalIndexEvaluation(Participant $participant, string $status): Evaluation
{
    return Evaluation::factory()->create([
        'participant_id' => $participant->id,
        'status' => $status,
        'evaluated_at' => $status === EvaluationStatus::Processing->value ? null : now(),
    ]);
}

function evalIndexProject(Organization $org): Project
{
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    return Project::factory()->create(['framework_version_id' => $fv->id]);
}

test('a participant below completato is absent from the built query result set', function (string $status): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = evalIndexProject($org);
    $participant = evalIndexParticipant($project, $status);
    evalIndexEvaluation($participant, 'completed');

    $query = new EvaluationIndexQuery($resolver);
    $results = $query->build(new EvaluationIndexFilters)->get();

    expect($results->pluck('participant_id'))->not->toContain($participant->id);
})->with(['in_attesa', 'in_corso', 'in_valutazione', 'errore']);

test('a completato participant with a completed evaluation IS present', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = evalIndexProject($org);
    $participant = evalIndexParticipant($project, 'completato');
    evalIndexEvaluation($participant, 'completed');

    $query = new EvaluationIndexQuery($resolver);
    $results = $query->build(new EvaluationIndexFilters)->get();

    expect($results->pluck('participant_id'))->toContain($participant->id);
});

test('an evaluations.status = processing row is absent even for a completato participant', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = evalIndexProject($org);
    $participant = evalIndexParticipant($project, 'completato');
    evalIndexEvaluation($participant, 'processing');

    $query = new EvaluationIndexQuery($resolver);
    $results = $query->build(new EvaluationIndexFilters)->get();

    expect($results->pluck('participant_id'))->not->toContain($participant->id);
});

test('assessment_type, role_code, status, and date-range filters each narrow the result set', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = evalIndexProject($org);

    $standardParticipant = evalIndexParticipant($project, 'completato');
    $standardParticipant->forceFill(['role_code' => 'ICO'])->save();
    $standardEvaluation = evalIndexEvaluation($standardParticipant, 'completed');
    $standardEvaluation->forceFill(['evaluated_at' => now()->subDays(10)])->save();

    $potentialProject = evalIndexProject($org);
    $potentialProject->forceFill(['assessment_type' => 'potential'])->save();
    $potentialParticipant = evalIndexParticipant($potentialProject, 'completato');
    $potentialParticipant->forceFill(['role_code' => null])->save();
    $pendingEvaluation = evalIndexEvaluation($potentialParticipant, 'pending');
    $pendingEvaluation->forceFill(['evaluated_at' => now()])->save();

    $query = new EvaluationIndexQuery($resolver);

    // assessment_type narrows to only the potential-project row.
    $byType = $query->build(new EvaluationIndexFilters(assessmentType: 'potential'))->get();
    expect($byType->pluck('participant_id'))->toContain($potentialParticipant->id);
    expect($byType->pluck('participant_id'))->not->toContain($standardParticipant->id);

    // role_code narrows to only the ICO participant.
    $byRole = $query->build(new EvaluationIndexFilters(roleCode: 'ICO'))->get();
    expect($byRole->pluck('participant_id'))->toContain($standardParticipant->id);
    expect($byRole->pluck('participant_id'))->not->toContain($potentialParticipant->id);

    // status narrows to only the pending evaluation.
    $byStatus = $query->build(new EvaluationIndexFilters(status: 'pending'))->get();
    expect($byStatus->pluck('participant_id'))->toContain($potentialParticipant->id);
    expect($byStatus->pluck('participant_id'))->not->toContain($standardParticipant->id);

    // evaluated_from excludes the 10-day-old standard evaluation.
    $byFrom = $query->build(new EvaluationIndexFilters(evaluatedFrom: now()->subDay()->toDateString()))->get();
    expect($byFrom->pluck('participant_id'))->toContain($potentialParticipant->id);
    expect($byFrom->pluck('participant_id'))->not->toContain($standardParticipant->id);

    // evaluated_to excludes today's pending evaluation, keeping only the old one.
    $byTo = $query->build(new EvaluationIndexFilters(evaluatedTo: now()->subDays(5)->toDateString()))->get();
    expect($byTo->pluck('participant_id'))->toContain($standardParticipant->id);
    expect($byTo->pluck('participant_id'))->not->toContain($potentialParticipant->id);
});

test('a cross-org project_id filter returns an empty result set, never org B rows', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $resolverA = app(TenantResolver::class);
    $resolverA->setOrgId($orgA->id);
    $resolverA->setBypass(false);
    evalIndexParticipant(evalIndexProject($orgA), 'completato');

    app(TenantResolver::class)->setOrgId($orgB->id);
    $projectB = evalIndexProject($orgB);
    $participantB = evalIndexParticipant($projectB, 'completato');
    evalIndexEvaluation($participantB, 'completed');

    // Back to org A's context for the query under test.
    app(TenantResolver::class)->setOrgId($orgA->id);
    $query = new EvaluationIndexQuery(app(TenantResolver::class));
    $results = $query->build(new EvaluationIndexFilters(projectId: $projectB->id))->get();

    expect($results)->toBeEmpty();
});
