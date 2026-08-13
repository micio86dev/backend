<?php

declare(strict_types=1);

/**
 * The lifecycle read-gate applied to `/api/evaluations` and
 * `/api/evaluations/summary` (backoffice-missing-pages D6, inherited from
 * C11's admin-read-api Lifecycle Read-Gate).
 *
 * Structured evaluation data (scores, reliability, competency means) is
 * readable ONLY for participants at `completato`. The gate is a JOIN
 * PREDICATE inside EvaluationIndexQuery — a below-threshold participant's
 * row is structurally ABSENT, never merely null-scored.
 *
 * REQ: Lifecycle Read-Gate Applies To The Evaluations Index And Summary
 *      (openspec/changes/backoffice-missing-pages/specs/admin-read-api/spec.md)
 */

use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;

test('a non-completato participant never leaks a score in the index or the summary mean', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    // in_valutazione: an evaluation may already exist mid-scoring, but the
    // participant has not yet reached completato.
    $participant = Participant::factory()->forProject($project)->withStatus('in_valutazione')->create([
        'candidate_ref' => 'not-yet-complete-ref',
    ]);
    $evaluation = Evaluation::factory()->create([
        'participant_id' => $participant->id,
        'status' => 'completed',
        'evaluated_at' => now(),
    ]);
    CompetencyResult::factory()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COL',
        'score' => 5.0,
        'reliability' => 0.99,
    ]);

    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    app(TenantResolver::class)->setOrgId($org->id);

    $indexResponse = $this->withToken($token)->getJson('/api/evaluations');
    $indexResponse->assertOk();
    expect($indexResponse->json('data'))->toBeEmpty();
    expect($indexResponse->getContent())->not->toContain('not-yet-complete-ref');

    $summaryResponse = $this->withToken($token)->getJson('/api/evaluations/summary');
    $summaryResponse->assertOk();
    expect($summaryResponse->json('data.competencies'))->toBeEmpty();
});
