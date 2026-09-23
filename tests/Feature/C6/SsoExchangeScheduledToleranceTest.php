<?php

declare(strict_types=1);

/**
 * RED — SsoExchangeController tolerance for pre-created scheduled
 * participants (interview-scheduling PR-B2, design AD-11, tasks
 * T-B2-1/T-B2-2).
 *
 * Covers every row of AD-11's compatible/anomalous table:
 * - scheduling_status = Started    -> compatible, exchange succeeds unchanged
 * - scheduling_status = NoticeSent -> compatible (belt-and-suspenders), succeeds
 * - scheduling_status = Pending    -> anomalous, GENERIC_403 (no state disclosure)
 * - scheduling_status = Cancelled  -> anomalous, GENERIC_403
 * - scheduling_status = null       -> regression, immediate-path behavior unchanged
 * - cross-tenant: a Pending row in org A is never matched by a token whose
 *   claims resolve to org B's project (no leak, no cross-org misfire)
 * - concurrency: jti single-use consumption still governs a scheduled
 *   participant's link exactly like the immediate path — no new locking
 *   primitive is introduced by this guard (AD-11: "no change to Step 10 SQL")
 *
 * Correctness-critical zone (repo CLAUDE.md: candidate state machine held to
 * ~95% coverage, not the repo's 85% default) — every AD-11 branch gets its
 * own assertion, not just the happy path.
 *
 * REQ: SsoExchangeController tolerance for pre-created scheduled participants
 *      (sdd/interview-scheduling/design, Engram #2222, AD-11)
 */

use App\Enums\ParticipantSchedulingStatus;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;

// ---------------------------------------------------------------------------
// Helpers (uniquely named — every Feature/C6 test file loads into ONE PHP
// process for a full-suite run; PR-C's apply-progress notes the Pest
// "Cannot redeclare" collision risk across sibling test files in this dir).
// ---------------------------------------------------------------------------

function schedTolProject(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(array_merge([
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'goes_live_at' => null,
        'deadline_at' => null,
    ], $attrs));

    makeProjectInterviewable($project);

    return $project;
}

function schedTolParticipant(
    Project $project,
    Organization $org,
    string $ref,
    ?ParticipantSchedulingStatus $schedulingStatus,
): Participant {
    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => $ref,
        'display_name' => 'Pre-Scheduled',
        'email' => uniqid('sched-tol-').'@example.test',
        'status' => 'in_attesa',
        'scheduled_at' => $schedulingStatus !== null ? now()->addHour() : null,
        'scheduling_status' => $schedulingStatus,
    ]);
    $participant->save();

    return $participant->fresh();
}

function schedTolToken(Project $project, Organization $org, string $ref, array $overrides = []): string
{
    return CandidateTokenFactory::mintSsoLink(array_merge([
        'candidate_ref' => $ref,
        'display_name' => 'New Display Name',
        'email' => uniqid('sched-tol-new-').'@example.test',
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => $project->role_code,
        'lang' => 'en',
    ], $overrides));
}

// ---------------------------------------------------------------------------
// Compatible states — fall through to the existing upsert unchanged
// ---------------------------------------------------------------------------

test('scheduling_status=Started -> exchange succeeds and leaves scheduling columns untouched', function (): void {
    $org = Organization::factory()->create();
    $project = schedTolProject($org);
    $participant = schedTolParticipant($project, $org, 'sched-tol-started', ParticipantSchedulingStatus::Started);
    $originalScheduledAt = $participant->scheduled_at;

    $token = schedTolToken($project, $org, 'sched-tol-started');
    $response = $this->getJson('/api/sso/exchange?token='.$token);

    $response->assertOk()->assertJsonStructure(['access_token']);

    $fresh = $participant->fresh();
    expect($fresh->display_name)->toBe('New Display Name');
    expect($fresh->status)->toBe('in_attesa');
    expect($fresh->scheduling_status)->toBe(ParticipantSchedulingStatus::Started);
    expect($fresh->scheduled_at->equalTo($originalScheduledAt))->toBeTrue();
});

test('scheduling_status=NoticeSent -> exchange succeeds (belt-and-suspenders compatible state)', function (): void {
    $org = Organization::factory()->create();
    $project = schedTolProject($org);
    $participant = schedTolParticipant($project, $org, 'sched-tol-notice', ParticipantSchedulingStatus::NoticeSent);

    $token = schedTolToken($project, $org, 'sched-tol-notice');
    $response = $this->getJson('/api/sso/exchange?token='.$token);

    $response->assertOk()->assertJsonStructure(['access_token']);

    $fresh = $participant->fresh();
    expect($fresh->scheduling_status)->toBe(ParticipantSchedulingStatus::NoticeSent);
});

// ---------------------------------------------------------------------------
// Anomalous states — GENERIC_403, no state disclosure
// ---------------------------------------------------------------------------

test('scheduling_status=Pending -> generic 403, identical body to every other gate', function (): void {
    $org = Organization::factory()->create();
    $project = schedTolProject($org);
    schedTolParticipant($project, $org, 'sched-tol-pending', ParticipantSchedulingStatus::Pending);

    $token = schedTolToken($project, $org, 'sched-tol-pending');
    $response = $this->getJson('/api/sso/exchange?token='.$token);

    $response->assertStatus(403);
    $body = $response->json();
    expect($body['message'])->toBe('Access denied.');
    expect(array_keys($body))->not->toContain('reason');
    expect(array_keys($body))->not->toContain('status');
    expect(array_keys($body))->not->toContain('scheduling_status');
});

test('scheduling_status=Cancelled -> generic 403, identical body to every other gate', function (): void {
    $org = Organization::factory()->create();
    $project = schedTolProject($org);
    schedTolParticipant($project, $org, 'sched-tol-cancelled', ParticipantSchedulingStatus::Cancelled);

    $token = schedTolToken($project, $org, 'sched-tol-cancelled');
    $response = $this->getJson('/api/sso/exchange?token='.$token);

    $response->assertStatus(403);
    $body = $response->json();
    expect($body['message'])->toBe('Access denied.');
    expect(array_keys($body))->not->toContain('reason');
});

test('a Pending schedule is rejected with the SAME generic 403 body as a Cancelled one — no distinguishable status code or message', function (): void {
    $orgPending = Organization::factory()->create();
    $projectPending = schedTolProject($orgPending);
    schedTolParticipant($projectPending, $orgPending, 'sched-tol-pending-cmp', ParticipantSchedulingStatus::Pending);
    $tokenPending = schedTolToken($projectPending, $orgPending, 'sched-tol-pending-cmp');

    $orgCancelled = Organization::factory()->create();
    $projectCancelled = schedTolProject($orgCancelled);
    schedTolParticipant($projectCancelled, $orgCancelled, 'sched-tol-cancelled-cmp', ParticipantSchedulingStatus::Cancelled);
    $tokenCancelled = schedTolToken($projectCancelled, $orgCancelled, 'sched-tol-cancelled-cmp');

    $pendingResponse = $this->getJson('/api/sso/exchange?token='.$tokenPending);
    $cancelledResponse = $this->getJson('/api/sso/exchange?token='.$tokenCancelled);

    expect($pendingResponse->status())->toBe($cancelledResponse->status());
    expect($pendingResponse->json())->toBe($cancelledResponse->json());
});

// ---------------------------------------------------------------------------
// Regression — immediate path (scheduling_status = null) unchanged
// ---------------------------------------------------------------------------

test('scheduling_status=null (immediate-path participant, pre-existing row) -> exchange succeeds exactly as before this change', function (): void {
    $org = Organization::factory()->create();
    $project = schedTolProject($org);
    $participant = schedTolParticipant($project, $org, 'sched-tol-null-existing', null);

    $token = schedTolToken($project, $org, 'sched-tol-null-existing');
    $response = $this->getJson('/api/sso/exchange?token='.$token);

    $response->assertOk()->assertJsonStructure(['access_token']);
    expect($participant->fresh()->display_name)->toBe('New Display Name');
});

test('no pre-existing row at all (brand-new immediate-path candidate) -> exchange still creates it and succeeds', function (): void {
    $org = Organization::factory()->create();
    $project = schedTolProject($org);

    $token = schedTolToken($project, $org, 'sched-tol-brand-new');
    $response = $this->getJson('/api/sso/exchange?token='.$token);

    $response->assertOk()->assertJsonStructure(['access_token']);
    $participant = Participant::where('project_id', $project->id)
        ->where('candidate_ref', 'sched-tol-brand-new')
        ->firstOrFail();
    expect($participant->scheduling_status)->toBeNull();
    expect($participant->scheduled_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Cross-tenant isolation (T-B2-2)
// ---------------------------------------------------------------------------

test('a Pending schedule in org A is never matched by a token whose claims resolve to org B project (no leak, no misfire)', function (): void {
    $orgA = Organization::factory()->create();
    $projectA = schedTolProject($orgA);
    $sharedRef = 'sched-tol-shared-ref';
    $pendingInOrgA = schedTolParticipant($projectA, $orgA, $sharedRef, ParticipantSchedulingStatus::Pending);

    $orgB = Organization::factory()->create();
    $projectB = schedTolProject($orgB);
    $tokenForOrgB = schedTolToken($projectB, $orgB, $sharedRef);

    $response = $this->getJson('/api/sso/exchange?token='.$tokenForOrgB);

    // Org B has no pre-existing row for this candidate_ref — the immediate
    // path runs unmodified and succeeds, creating its OWN new participant.
    $response->assertOk()->assertJsonStructure(['access_token']);

    $newInOrgB = Participant::where('project_id', $projectB->id)
        ->where('candidate_ref', $sharedRef)
        ->firstOrFail();
    expect($newInOrgB->organization_id)->toBe($orgB->id);
    expect($newInOrgB->id)->not->toBe($pendingInOrgA->id);

    // Org A's Pending row is completely untouched by this unrelated exchange.
    $freshOrgA = $pendingInOrgA->fresh();
    expect($freshOrgA->scheduling_status)->toBe(ParticipantSchedulingStatus::Pending);
    expect($freshOrgA->display_name)->toBe('Pre-Scheduled');
});

// ---------------------------------------------------------------------------
// Concurrency (T-B2-2) — jti single-use consumption still governs a
// scheduled participant's link exactly like the immediate path; no new
// locking primitive is introduced by this guard (design AD-11's explicit
// "no change to Step 10 SQL").
// ---------------------------------------------------------------------------

test('a scheduled participant\'s link is single-use exactly like the immediate path — replay after success is 401, not a second 200', function (): void {
    $org = Organization::factory()->create();
    $project = schedTolProject($org);
    schedTolParticipant($project, $org, 'sched-tol-concurrency', ParticipantSchedulingStatus::Started);

    $token = schedTolToken($project, $org, 'sched-tol-concurrency');

    $this->getJson('/api/sso/exchange?token='.$token)->assertOk();
    $this->getJson('/api/sso/exchange?token='.$token)->assertUnauthorized();
});

test('a scheduled participant\'s link with an anomalous Pending status still burns the jti on the 403 (no free retry) — matches every other 403 gate', function (): void {
    $org = Organization::factory()->create();
    $project = schedTolProject($org);
    schedTolParticipant($project, $org, 'sched-tol-pending-jti', ParticipantSchedulingStatus::Pending);

    $token = schedTolToken($project, $org, 'sched-tol-pending-jti');

    $this->getJson('/api/sso/exchange?token='.$token)->assertStatus(403);
    $this->getJson('/api/sso/exchange?token='.$token)->assertUnauthorized();
});
