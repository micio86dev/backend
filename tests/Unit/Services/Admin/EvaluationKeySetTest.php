<?php

declare(strict_types=1);

/**
 * RED+GREEN — A4.3: EvaluationKeySetTest (C13, design.md D11).
 *
 * The drift guard C-C showed was missing: `AdminEvaluationSerializer` has no
 * generated type on the `backoffice` side (Scramble emits `EvaluationResource`
 * as `{[key: string]: unknown}` — it cannot infer a passthrough `toArray()`).
 * `useEvaluationReport.ts`'s hand-typed interface is edited BY HAND, and
 * nothing has ever named the omission when someone adds a field without
 * touching it. This test is that missing guard: it asserts the literal key
 * set of `serializeCompetencyResult()`'s output against an explicit expected
 * list, so a future field addition/removal fails HERE first.
 *
 * B3.2: `behaviors[]` now gains `unassessable_reason` — this file's second
 * test's expected list is updated accordingly.
 */

use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\Admin\AdminEvaluationSerializer;
use App\Support\Tenancy\TenantResolver;

test('serializeCompetencyResult() output key set equals the explicit expected list', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create();
    $competency = Competency::factory()->create(['code' => 'COL']);
    $project->competencies()->attach($competency->id, ['position' => 0]);

    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    $result = CompetencyResult::factory()->valid()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COL',
    ]);
    IndicatorScore::factory()->create(['competency_result_id' => $result->id]);

    $serialized = (new AdminEvaluationSerializer)->serialize($participant);
    $keys = array_keys($serialized['COL']);
    sort($keys);

    $expected = ['behaviors', 'reliability', 'score', 'unscorable_reason'];
    sort($expected);

    expect($keys)->toBe($expected, 'The serialized competency entry gained or lost a key — update this list AND the backoffice hand-typed interface together.');
});

test('each behaviors[] entry key set equals the explicit expected list', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create();
    $competency = Competency::factory()->create(['code' => 'COL']);
    $project->competencies()->attach($competency->id, ['position' => 0]);

    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    $result = CompetencyResult::factory()->valid()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COL',
    ]);
    IndicatorScore::factory()->create(['competency_result_id' => $result->id]);

    $serialized = (new AdminEvaluationSerializer)->serialize($participant);
    $behaviorKeys = array_keys($serialized['COL']['behaviors'][0]);
    sort($behaviorKeys);

    $expected = ['excerpts', 'explanation', 'indicator', 'score', 'unassessable_reason'];
    sort($expected);

    expect($behaviorKeys)->toBe($expected, 'A behaviors[] entry gained or lost a key — update this list AND the backoffice hand-typed interface together.');
});
