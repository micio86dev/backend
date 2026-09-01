<?php

declare(strict_types=1);

/**
 * Cross-tenant isolation on every admin read endpoint (C11 PR A3, task 9.1).
 *
 * `Participant` is a plain Model (Participant.php:55, no global scope) — a
 * bare `Participant::findOrFail($id)` in a controller would return another
 * org's row with a 200. Every admin controller routes through
 * AdminParticipantReader (D1), whose org filter runs FIRST, so a cross-org
 * id 404s before RBAC or the lifecycle gate ever run.
 *
 * These tests exercise the already-wired controllers/routes end-to-end
 * (built in this same PR) rather than driving them into existence one at a
 * time — this file's RED signal was the 404 the earlier per-endpoint RED
 * tests already captured (the whole route group/controller set was absent).
 * Per this session's verification discipline, the "every id-based endpoint"
 * dataset test below was additionally proven with a live mutation of
 * AdminParticipantReader::read()'s org filter (dropped to Participant::query(),
 * simulating the exact hazard D1 exists to prevent) — confirmed to fail
 * (404 -> 200) with the mutation in place, then restored byte-identically
 * and confirmed green again. Full before/after command output is reported
 * in the apply-progress artifact and the phase return summary.
 *
 * REQ: Cross-Tenant Isolation on Every Admin Read Endpoint
 *      (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 */

use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function crossOrgReaderToken(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return auth('api')->login($user);
}

function crossOrgParticipantWithEvaluation(Organization $org): Participant
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create([
        'candidate_ref' => 'org-b-secret-ref',
        'display_name' => 'Org B Secret Candidate',
        'email' => uniqid('cand-').'@example.test',
    ]);

    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);
    $result = CompetencyResult::factory()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COL',
        'score' => 4.0,
        'reliability' => 0.9,
    ]);
    IndicatorScore::factory()->create(['competency_result_id' => $result->id, 'position' => 0]);

    return $participant;
}

test('every id-based endpoint 404s on a cross-org participant, leaking nothing', function (string $suffix): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $participantInOrgB = crossOrgParticipantWithEvaluation($orgB);
    $token = crossOrgReaderToken($orgA);
    app(TenantResolver::class)->setOrgId($orgA->id);

    $response = $this->withToken($token)->getJson("/api/participants/{$participantInOrgB->id}{$suffix}");

    $response->assertNotFound();
    $body = $response->getContent();
    expect($body)->not->toContain('org-b-secret-ref');
    expect($body)->not->toContain('Org B Secret Candidate');
})->with([
    'show' => [''],
    'transcript' => ['/transcript'],
    'evaluation' => ['/evaluation'],
    'transcript download' => ['/transcript/download'],
    'evaluation download' => ['/evaluation/download'],
]);

test('GET /api/participants (index) never lists a cross-org participant', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $participantInOrgB = crossOrgParticipantWithEvaluation($orgB);
    $token = crossOrgReaderToken($orgA);
    app(TenantResolver::class)->setOrgId($orgA->id);

    $response = $this->withToken($token)->getJson('/api/participants');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->not->toContain($participantInOrgB->id);
    expect($response->getContent())->not->toContain('org-b-secret-ref');
});

test('GET /api/dashboard/metrics never counts a cross-org participant or evaluation', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    crossOrgParticipantWithEvaluation($orgB);
    $token = crossOrgReaderToken($orgA);
    app(TenantResolver::class)->setOrgId($orgA->id);

    $response = $this->withToken($token)->getJson('/api/dashboard/metrics');

    $response->assertOk();
    $body = $response->json('data');
    expect(array_sum($body['participants_by_status']))->toBe(0);
    expect(array_sum($body['evaluations_by_status']))->toBe(0);
});
