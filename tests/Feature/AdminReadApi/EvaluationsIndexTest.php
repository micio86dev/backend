<?php

declare(strict_types=1);

/**
 * GET /api/evaluations — the org-scoped, paginated evaluations index
 * (backoffice-missing-pages D6).
 *
 * REQ: Evaluations Index Endpoint (openspec/changes/backoffice-missing-pages/specs/admin-read-api/spec.md)
 */

use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;

function evalsIndexOrgFixture(Organization $org, string $participantStatus, string $evaluationStatus, float $reliability = 0.83): array
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id, 'name' => 'Fixture Project']);
    $participant = Participant::factory()->forProject($project)->withStatus($participantStatus)->create();

    $evaluation = Evaluation::factory()->create([
        'participant_id' => $participant->id,
        'status' => $evaluationStatus,
        'evaluated_at' => $evaluationStatus === 'processing' ? null : now(),
    ]);

    if ($evaluationStatus !== 'processing') {
        CompetencyResult::factory()->create([
            'evaluation_id' => $evaluation->id,
            'competency_code' => 'COL',
            'score' => 4.0,
            'reliability' => $reliability,
        ]);
    }

    return compact('project', 'participant', 'evaluation');
}

test('index returns only same-org rows', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    evalsIndexOrgFixture($orgA, 'completato', 'completed');
    evalsIndexOrgFixture($orgB, 'completato', 'completed');

    ['token' => $token] = authUserAndTokenForRole($orgA, 'admin');
    app(TenantResolver::class)->setOrgId($orgA->id);

    $response = $this->withToken($token)->getJson('/api/evaluations');

    $response->assertOk();
    $rows = $response->json('data');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['project_name'])->toBe('Fixture Project');
});

test('reliability renders as a verbatim percentage, never a High/Medium/Low label', function (): void {
    $org = Organization::factory()->create();
    evalsIndexOrgFixture($org, 'completato', 'completed', reliability: 0.83);

    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    app(TenantResolver::class)->setOrgId($org->id);

    $response = $this->withToken($token)->getJson('/api/evaluations');

    $response->assertOk();
    expect($response->json('data.0.reliability'))->toBe(83);
    expect($response->getContent())->not->toContain('"High"');
    expect($response->getContent())->not->toContain('"Medium"');
    expect($response->getContent())->not->toContain('"Low"');
});
