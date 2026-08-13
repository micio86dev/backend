<?php

declare(strict_types=1);

/**
 * GET /api/evaluations/summary — mean competency score per competency code
 * across the same filtered set the index endpoint describes
 * (backoffice-missing-pages D7).
 *
 * REQ: Evaluations Summary Endpoint (openspec/changes/backoffice-missing-pages/specs/admin-read-api/spec.md)
 */

use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;

function summaryCompletatoParticipantWithScore(Organization $org, float $score): void
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->create([
        'participant_id' => $participant->id,
        'status' => 'completed',
        'evaluated_at' => now(),
    ]);

    CompetencyResult::factory()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COL',
        'score' => $score,
        'reliability' => 0.9,
    ]);
}

test('summary computes the mean per competency code across the filtered set', function (): void {
    $org = Organization::factory()->create();
    summaryCompletatoParticipantWithScore($org, 5.0);
    summaryCompletatoParticipantWithScore($org, 3.0);
    summaryCompletatoParticipantWithScore($org, 4.0);

    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    app(TenantResolver::class)->setOrgId($org->id);

    $response = $this->withToken($token)->getJson('/api/evaluations/summary');

    $response->assertOk();
    $competencies = collect($response->json('data.competencies'))->keyBy('competency_code');
    expect((float) $competencies['COL']['mean_score'])->toBe(4.0);
    expect($competencies['COL']['scored_count'])->toBe(3);
    expect($competencies['COL']['result_count'])->toBe(3);
});

test('an all-NULL-score competency is excluded from the mean, not averaged as 0', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->create([
        'participant_id' => $participant->id,
        'status' => 'completed',
        'evaluated_at' => now(),
    ]);

    // Unscorable — score is NULL (CC2: all-indicators-unassessable).
    CompetencyResult::factory()->unscorable()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'PRS',
    ]);

    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    app(TenantResolver::class)->setOrgId($org->id);

    $response = $this->withToken($token)->getJson('/api/evaluations/summary');

    $response->assertOk();
    $competencies = collect($response->json('data.competencies'))->keyBy('competency_code');
    // scored_count is 0 (SQL count(score) skips NULLs); the row exists
    // (result_count 1) but contributes NOTHING to mean_score — never a
    // silently-substituted 0.
    expect($competencies['PRS']['scored_count'])->toBe(0);
    expect($competencies['PRS']['result_count'])->toBe(1);
    expect($competencies['PRS']['mean_score'])->toBeNull();
});

test('index and summary provably describe the same population for identical filters', function (): void {
    $org = Organization::factory()->create();
    summaryCompletatoParticipantWithScore($org, 5.0);
    summaryCompletatoParticipantWithScore($org, 3.0);

    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    app(TenantResolver::class)->setOrgId($org->id);

    $indexResponse = $this->withToken($token)->getJson('/api/evaluations');
    $summaryResponse = $this->withToken($token)->getJson('/api/evaluations/summary');

    $indexResponse->assertOk();
    $summaryResponse->assertOk();

    $indexCount = count($indexResponse->json('data'));
    $summaryCount = $summaryResponse->json('data.competencies.0.result_count');

    expect($indexCount)->toBe(2);
    expect($summaryCount)->toBe(2);
});
