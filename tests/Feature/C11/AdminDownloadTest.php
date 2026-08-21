<?php

declare(strict_types=1);

/**
 * RED — Admin download endpoints (C11 PR A3, tasks 8.2, 9.5, D9).
 *
 * transcript/download -> text/plain, buffered; evaluation/download ->
 * application/json, buffered. Filename `beai-{type}-{candidate_ref}-{YYYYMMDD}.{ext}`;
 * candidate_ref is externally supplied and opaque (Participant.php:46) — it
 * MUST be Str::slug()-ed and emitted with BOTH an RFC 5987 `filename*=UTF-8''…`
 * form and an ASCII `filename=` fallback, never interpolated raw (header-
 * injection guard). Gate is identical to the corresponding read endpoint.
 *
 * REQ: Downloadable Artifacts Are Limited to Transcript and Evaluation
 *      (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 */

use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Models\Utterance;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function downloadTestAdminUser(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return auth('api')->login($user);
}

function downloadTestProjectIn(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    return Project::factory()->create(['framework_version_id' => $fv->id]);
}

test('GET .../transcript/download returns buffered text/plain with a safe filename', function (): void {
    $org = Organization::factory()->create();
    $token = downloadTestAdminUser($org);
    $project = downloadTestProjectIn($org);
    $participant = Participant::factory()->forProject($project)
        ->withStatus('in_valutazione')
        ->create(['candidate_ref' => 'ref-001']);

    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'COL',
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'status' => 'completed',
    ]);
    Utterance::create([
        'interview_session_id' => $session->id,
        'speaker' => 'Candidate',
        'text' => 'Downloadable line.',
        'ts' => '2024-01-01 10:00:00',
    ]);

    $response = $this->withToken($token)->get("/api/participants/{$participant->id}/transcript/download");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    expect($response->getContent())->toContain('Downloadable line.');

    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('attachment;');
    expect($disposition)->toContain('filename="beai-transcript-ref-001-');
    expect($disposition)->toContain("filename*=UTF-8''beai-transcript-ref-001-");
});

test('GET .../evaluation/download returns buffered application/json with the BARS report', function (): void {
    $org = Organization::factory()->create();
    $token = downloadTestAdminUser($org);
    $project = downloadTestProjectIn($org);
    $participant = Participant::factory()->forProject($project)
        ->withStatus('completato')
        ->create(['candidate_ref' => 'ref-002']);

    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);
    $competencyResult = CompetencyResult::factory()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COL',
        'score' => 4.0,
        'reliability' => 0.67,
    ]);
    IndicatorScore::factory()->create(['competency_result_id' => $competencyResult->id, 'position' => 0]);

    $response = $this->withToken($token)->get("/api/participants/{$participant->id}/evaluation/download");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/json');

    $body = json_decode($response->getContent(), true);
    expect($body['COL']['score'])->toBe(4.0);

    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('filename="beai-evaluation-ref-002-');
});

test('a candidate_ref containing quotes, CRLF, and non-ASCII never reaches Content-Disposition raw', function (): void {
    $org = Organization::factory()->create();
    $token = downloadTestAdminUser($org);
    $project = downloadTestProjectIn($org);
    $dangerousRef = "evil\"\r\nX-Injected: 1\r\n; héllo 😀";
    $participant = Participant::factory()->forProject($project)
        ->withStatus('in_valutazione')
        ->create(['candidate_ref' => $dangerousRef]);

    $response = $this->withToken($token)->get("/api/participants/{$participant->id}/transcript/download");

    $response->assertOk();
    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->not->toBeNull();
    expect($disposition)->not->toContain('"'.$dangerousRef);
    expect($disposition)->not->toContain("\r\n");
    expect($disposition)->not->toContain('X-Injected');
    expect($disposition)->toMatch('/^attachment; filename="[\x20-\x7E]+"; filename\*=UTF-8\'\'[\x21-\x7E]+$/');
});

test('a candidate_ref that slugs to empty (all-emoji/symbols) falls back to "candidate" in the filename', function (): void {
    $org = Organization::factory()->create();
    $token = downloadTestAdminUser($org);
    $project = downloadTestProjectIn($org);
    $participant = Participant::factory()->forProject($project)
        ->withStatus('in_valutazione')
        ->create(['candidate_ref' => '😀🎉✨']);

    $response = $this->withToken($token)->get("/api/participants/{$participant->id}/transcript/download");

    $response->assertOk();
    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('filename="beai-transcript-candidate-');
});

test('a gated download before its lifecycle threshold returns 409, never a file', function (): void {
    $org = Organization::factory()->create();
    $token = downloadTestAdminUser($org);
    $project = downloadTestProjectIn($org);
    // in_attesa is the only status still denied for Transcript after D1
    // loosened the minimum to in_corso (operator-participant-visibility).
    $participant = Participant::factory()->forProject($project)->withStatus('in_attesa')->create();

    $response = $this->withToken($token)->getJson("/api/participants/{$participant->id}/transcript/download");

    $response->assertStatus(409);
    $response->assertJsonPath('error', 'lifecycle_not_ready');
});
