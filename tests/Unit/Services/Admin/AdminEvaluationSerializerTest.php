<?php

declare(strict_types=1);

/**
 * RED — AdminEvaluationSerializer (C11, task 5.1, PR A2).
 *
 * Verifies:
 * (a) the SLF fixture (indicator scores 5,3,-1 → stored competency mean 4.0,
 *     reliability 0.67 → rendered "67%") matches
 *     esempio-report-valutazione.json:374-392.
 * (b) an all-(-1) competency → score is null, never 0 (CompetencyResult.php:39,67).
 * (c) a -1 indicator score is NEVER emitted as the literal -1 — it is a
 *     sentinel meaning "unassessable", not a value on the {1,3,5} scale, and
 *     renders null instead (orchestrator ruling, C11 PR A2).
 * (d) competencies are ordered by project_competencies.position (D6), reusing
 *     Project::competencies() (a pure collaborator), never reimplemented.
 * (e) reliability is emitted verbatim as a percent string — never mapped to a
 *     High/Medium/Low band (no band formula exists; open product decision #1).
 *
 * REQ: Evaluation Serializer Is Scoped, Not Copied From the Webhook Assembler
 *      (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 */

use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\Admin\AdminEvaluationSerializer;
use App\Support\Tenancy\TenantResolver;

function evalSerializerOrg(): Organization
{
    return Organization::factory()->create();
}

/**
 * @param  array<string, int>  $competencyPositions  competency code => pivot position
 */
function evalSerializerProject(Organization $org, array $competencyPositions): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);

    foreach ($competencyPositions as $code => $position) {
        $competency = Competency::factory()->create(['code' => $code]);
        $project->competencies()->attach($competency->id, ['position' => $position]);
    }

    return $project->fresh();
}

function evalSerializerParticipant(Project $project, string $status = 'completato'): Participant
{
    return Participant::factory()->forProject($project)->withStatus($status)->create();
}

test('SLF fixture: indicator scores 5,3,-1 -> competency mean 4.0, reliability "67%", -1 indicator renders null (never literal -1)', function (): void {
    $org = evalSerializerOrg();
    $project = evalSerializerProject($org, ['SLF' => 0]);
    $participant = evalSerializerParticipant($project);

    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    $competencyResult = CompetencyResult::factory()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'SLF',
        'score' => 4.0,
        'reliability' => 0.67,
        'valid' => true,
        'unscorable_reason' => null,
    ]);

    IndicatorScore::factory()->create([
        'competency_result_id' => $competencyResult->id,
        'position' => 0,
        'indicator_text' => 'Describe products and services accurately',
        'score' => 5,
        'explanation' => 'exp-5',
        'excerpts' => ['excerpt-5'],
    ]);
    IndicatorScore::factory()->create([
        'competency_result_id' => $competencyResult->id,
        'position' => 1,
        'indicator_text' => 'Link own arguments to customer needs and priorities',
        'score' => 3,
        'explanation' => 'exp-3',
        'excerpts' => ['excerpt-3'],
    ]);
    IndicatorScore::factory()->unassessable()->create([
        'competency_result_id' => $competencyResult->id,
        'position' => 2,
        'indicator_text' => 'Negotiate to reach solutions that meet the primary interests of customers',
        'explanation' => 'exp-unassessable',
    ]);

    $serializer = new AdminEvaluationSerializer;
    $result = $serializer->serialize($participant);

    expect($result)->toHaveKey('SLF');
    expect($result['SLF']['score'])->toBe(4.0);
    expect($result['SLF']['reliability'])->toBe('67%');
    expect($result['SLF']['reliability'])->not->toBeIn(['High', 'Medium', 'Low']);
    expect($result['SLF']['behaviors'])->toHaveCount(3);
    expect($result['SLF']['behaviors'][2]['score'])->toBeNull();
    expect($result['SLF']['behaviors'][2]['indicator'])
        ->toBe('Negotiate to reach solutions that meet the primary interests of customers');

    // -1 must never appear as a literal value anywhere in the serialized output.
    $scores = collect($result['SLF']['behaviors'])->pluck('score');
    expect($scores->filter(fn ($s) => $s === -1))->toBeEmpty();
});

test('a competency whose indicators are all unassessable has score null, never 0', function (): void {
    $org = evalSerializerOrg();
    $project = evalSerializerProject($org, ['COL' => 0]);
    $participant = evalSerializerParticipant($project);

    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    $competencyResult = CompetencyResult::factory()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COL',
        'score' => null,
        'reliability' => 0.0,
        'valid' => false,
        'unscorable_reason' => 'llm_parse_error',
    ]);

    IndicatorScore::factory()->unassessable()->create([
        'competency_result_id' => $competencyResult->id,
        'position' => 0,
        'indicator_text' => 'Work effectively with others',
        'explanation' => 'exp-unassessable-1',
    ]);
    IndicatorScore::factory()->unassessable()->create([
        'competency_result_id' => $competencyResult->id,
        'position' => 1,
        'indicator_text' => 'Willingly help colleagues in trouble',
        'explanation' => 'exp-unassessable-2',
    ]);

    $serializer = new AdminEvaluationSerializer;
    $result = $serializer->serialize($participant);

    expect($result['COL']['score'])->toBeNull();
    expect($result['COL']['score'])->not->toBe(0);
    expect($result['COL']['score'])->not->toBe(0.0);
});

// ─── unscorable_reason exposure (A4, design.md D11) ──────────────────────────

test('an unscorable competency exposes unscorable_reason', function (): void {
    $org = evalSerializerOrg();
    $project = evalSerializerProject($org, ['PRS' => 0]);
    $participant = evalSerializerParticipant($project);

    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    CompetencyResult::factory()->truncated()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'PRS',
    ]);

    $serializer = new AdminEvaluationSerializer;
    $result = $serializer->serialize($participant);

    expect($result['PRS'])->toHaveKey('unscorable_reason')
        ->and($result['PRS']['unscorable_reason'])->toBe('llm_truncated');
});

test('a scored competency carries a null unscorable_reason', function (): void {
    $org = evalSerializerOrg();
    $project = evalSerializerProject($org, ['SLF' => 0]);
    $participant = evalSerializerParticipant($project);

    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    CompetencyResult::factory()->valid()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'SLF',
    ]);

    $serializer = new AdminEvaluationSerializer;
    $result = $serializer->serialize($participant);

    expect($result['SLF'])->toHaveKey('unscorable_reason')
        ->and($result['SLF']['unscorable_reason'])->toBeNull();
});

test('unscorable_reason is byte-identical regardless of app locale (unlocalized, machine-facing)', function (): void {
    $org = evalSerializerOrg();
    $project = evalSerializerProject($org, ['PRS' => 0]);
    $participant = evalSerializerParticipant($project);

    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    CompetencyResult::factory()->truncated()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'PRS',
    ]);

    $serializer = new AdminEvaluationSerializer;

    app()->setLocale('it');
    $itResult = $serializer->serialize($participant);

    app()->setLocale('en');
    $enResult = $serializer->serialize($participant);

    expect($itResult['PRS']['unscorable_reason'])->toBe($enResult['PRS']['unscorable_reason'])
        ->and($itResult['PRS']['unscorable_reason'])->toBe('llm_truncated');
});

test('competencies are ordered by project_competencies.position, not by creation/DB order', function (): void {
    $org = evalSerializerOrg();
    // Position 0 = STG, position 1 = SLF — CompetencyResults are created below
    // in the REVERSE order, to prove the serializer orders by
    // project_competencies.position and not by creation/id order.
    $project = evalSerializerProject($org, ['STG' => 0, 'SLF' => 1]);
    $participant = evalSerializerParticipant($project);

    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    $slfResult = CompetencyResult::factory()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'SLF',
        'score' => 4.0,
        'reliability' => 0.67,
    ]);
    IndicatorScore::factory()->create(['competency_result_id' => $slfResult->id, 'position' => 0]);

    $stgResult = CompetencyResult::factory()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'STG',
        'score' => 3.67,
        'reliability' => 1.0,
    ]);
    IndicatorScore::factory()->create(['competency_result_id' => $stgResult->id, 'position' => 0]);

    $serializer = new AdminEvaluationSerializer;
    $result = $serializer->serialize($participant);

    expect(array_keys($result))->toBe(['STG', 'SLF']);
});
