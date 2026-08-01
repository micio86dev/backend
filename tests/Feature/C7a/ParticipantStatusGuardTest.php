<?php

declare(strict_types=1);

/**
 * ParticipantStatusGuard middleware feature tests (C7a — Interview Session Mechanics).
 *
 * Asserts:
 * - participant.status = 'completato' → 403 on each of the 3 PR2 interview sub-routes.
 * - participant.status = 'errore'     → 403 on each of the 3 PR2 interview sub-routes.
 * - participant.status = 'in_attesa'  → guard passes (NOT 403) on each route.
 * - participant.status = 'in_corso'   → guard passes (NOT 403) on each route.
 * - Guard does NOT apply to GET /api/candidate/session — returns 200 for terminal participants (FIX-7).
 *
 * Note: /start and /end are deferred to PR3. These tests cover the 3 PR2 routes:
 *   POST /api/candidate/interview/utterance
 *   POST /api/candidate/interview/integrity
 *   POST /api/candidate/interview/snapshot
 *
 * Tasks: 6.1 (RED)
 * REQ: Status guard — block terminal participants (C7a)
 */

use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function guardOrg(): Organization
{
    return Organization::factory()->create();
}

function guardProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function guardParticipant(Organization $org, Project $project, string $status): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'guard-'.uniqid(),
        'display_name' => 'Guard Test',
        'status' => $status,
    ]);
    $p->save();

    return $p->fresh();
}

function guardSession(Organization $org, Participant $participant, Project $project, string $status = 'in_corso'): InterviewSession
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'status' => $status,
    ]);
}

function guardBearer(Participant $participant): string
{
    return CandidateTokenFactory::mintCandidateToken($participant);
}

// ─── Terminal status → 403 on all 3 PR2 interview sub-routes ─────────────────

test('completato participant gets 403 on POST /utterance', function (): void {
    $org = guardOrg();
    $project = guardProject($org);
    $participant = guardParticipant($org, $project, 'completato');
    $session = guardSession($org, $participant, $project, 'completed');
    $token = guardBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => $session->id,
            'speaker' => 'candidate',
            'text' => 'Hello',
            'ts' => now()->toIso8601String(),
        ]);

    $response->assertForbidden();
});

test('completato participant gets 403 on POST /integrity', function (): void {
    $org = guardOrg();
    $project = guardProject($org);
    $participant = guardParticipant($org, $project, 'completato');
    $session = guardSession($org, $participant, $project, 'completed');
    $token = guardBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/integrity', [
            'session_id' => $session->id,
            'events' => [['kind' => 'tab_hidden', 'payload' => [], 'ts' => now()->toIso8601String()]],
        ]);

    $response->assertForbidden();
});

test('completato participant gets 403 on POST /snapshot', function (): void {
    $org = guardOrg();
    $project = guardProject($org);
    $participant = guardParticipant($org, $project, 'completato');
    $session = guardSession($org, $participant, $project, 'completed');
    $token = guardBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/snapshot', [
            'session_id' => $session->id,
            'image_base64' => base64_encode("\xFF\xD8\xFF".str_repeat('A', 10)),
        ]);

    $response->assertForbidden();
});

test('errore participant gets 403 on POST /utterance', function (): void {
    $org = guardOrg();
    $project = guardProject($org);
    $participant = guardParticipant($org, $project, 'errore');
    $session = guardSession($org, $participant, $project, 'error');
    $token = guardBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => $session->id,
            'speaker' => 'candidate',
            'text' => 'Hello',
            'ts' => now()->toIso8601String(),
        ]);

    $response->assertForbidden();
});

test('errore participant gets 403 on POST /integrity', function (): void {
    $org = guardOrg();
    $project = guardProject($org);
    $participant = guardParticipant($org, $project, 'errore');
    $session = guardSession($org, $participant, $project, 'error');
    $token = guardBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/integrity', [
            'session_id' => $session->id,
            'events' => [['kind' => 'tab_hidden', 'payload' => [], 'ts' => now()->toIso8601String()]],
        ]);

    $response->assertForbidden();
});

test('errore participant gets 403 on POST /snapshot', function (): void {
    $org = guardOrg();
    $project = guardProject($org);
    $participant = guardParticipant($org, $project, 'errore');
    $session = guardSession($org, $participant, $project, 'error');
    $token = guardBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/snapshot', [
            'session_id' => $session->id,
            'image_base64' => base64_encode("\xFF\xD8\xFF".str_repeat('A', 10)),
        ]);

    $response->assertForbidden();
});

// ─── Non-terminal status → guard passes (NOT 403) ────────────────────────────

test('in_attesa participant is NOT blocked by guard on POST /utterance (passes to controller)', function (): void {
    $org = guardOrg();
    $project = guardProject($org);
    $participant = guardParticipant($org, $project, 'in_attesa');
    $token = guardBearer($participant);

    // No session: controller will respond with 422 (validation) or 404 — not 403.
    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => 9999,
            'speaker' => 'candidate',
            'text' => 'Hello',
            'ts' => now()->toIso8601String(),
        ]);

    // Guard did not block: we get something other than 403 (controller responds with 404 for missing session)
    expect($response->status())->not->toBe(403);
});

test('in_corso participant is NOT blocked by guard on POST /utterance (passes to controller)', function (): void {
    $org = guardOrg();
    $project = guardProject($org);
    $participant = guardParticipant($org, $project, 'in_corso');
    $token = guardBearer($participant);

    // No session: controller will respond with 422 or 404 — not 403.
    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => 9999,
            'speaker' => 'candidate',
            'text' => 'Hello',
            'ts' => now()->toIso8601String(),
        ]);

    expect($response->status())->not->toBe(403);
});

// ─── Guard does NOT apply to GET /api/candidate/session (FIX-7) ──────────────

test('completato participant can still call GET /api/candidate/session (guard NOT applied there)', function (): void {
    $org = guardOrg();
    $project = guardProject($org);
    $participant = guardParticipant($org, $project, 'completato');
    $token = guardBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/candidate/session');

    // Must NOT be 403 — the guard is not on this route
    $response->assertOk();
});

test('errore participant can still call GET /api/candidate/session (guard NOT applied there)', function (): void {
    $org = guardOrg();
    $project = guardProject($org);
    $participant = guardParticipant($org, $project, 'errore');
    $token = guardBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/candidate/session');

    $response->assertOk();
});
