<?php

declare(strict_types=1);

/**
 * UtteranceController feature tests (C7a — Interview Session Mechanics).
 *
 * Tests POST /api/candidate/interview/utterance
 *
 * Asserts:
 * - Valid utterance into in_corso session → HTTP 202; Utterance row persisted.
 * - Utterance into completed session → HTTP 409; no row persisted (atomic guard).
 * - session_id from different participant (same org) → HTTP 404.
 * - session_id from different org → HTTP 404.
 * - TOCTOU atomic guard: session completed concurrently → 409, not 202 or 500.
 *
 * Tasks: 10.1 (RED)
 * REQ: POST /utterance — best-effort live transcript ingestion (C7a)
 */

use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function utteranceOrg(): Organization
{
    return Organization::factory()->create();
}

function utteranceProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function utteranceParticipant(Organization $org, Project $project, string $status = 'in_corso', string $suffix = ''): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'utt-'.($suffix ?: uniqid()),
        'display_name' => 'Utterance Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => $status,
    ]);
    $p->save();

    return $p->fresh();
}

function utteranceSession(Organization $org, Participant $participant, Project $project, string $status = 'in_corso', string $code = 'PRS'): InterviewSession
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'status' => $status,
    ]);
}

function utteranceBearer(Participant $participant): string
{
    return CandidateTokenFactory::mintCandidateToken($participant);
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('POST /utterance with in_corso session → 202 and Utterance row persisted', function (): void {
    $org = utteranceOrg();
    $project = utteranceProject($org);
    $participant = utteranceParticipant($org, $project, 'in_corso');
    $session = utteranceSession($org, $participant, $project, 'in_corso');
    $token = utteranceBearer($participant);

    $ts = now()->toIso8601String();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => $session->id,
            'speaker' => 'candidate',
            'text' => 'This is my answer.',
            'ts' => $ts,
        ]);

    $response->assertStatus(202);

    // Utterance row must be persisted
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    expect(Utterance::where('interview_session_id', $session->id)->count())->toBe(1);

    $utterance = Utterance::where('interview_session_id', $session->id)->first();
    expect($utterance->speaker)->toBe('candidate');
    expect($utterance->text)->toBe('This is my answer.');
    expect($utterance->interview_session_id)->toBe($session->id);
});

test('POST /utterance with avatar speaker → 202 and Utterance row persisted', function (): void {
    $org = utteranceOrg();
    $project = utteranceProject($org);
    $participant = utteranceParticipant($org, $project, 'in_corso');
    $session = utteranceSession($org, $participant, $project, 'in_corso');
    $token = utteranceBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => $session->id,
            'speaker' => 'avatar',
            'text' => 'Tell me about yourself.',
            'ts' => now()->toIso8601String(),
        ]);

    $response->assertStatus(202);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    expect(Utterance::where('interview_session_id', $session->id)->count())->toBe(1);
    expect(Utterance::where('interview_session_id', $session->id)->first()->speaker)->toBe('avatar');
});

test('POST /utterance with completed session → 409 Conflict; no row persisted (atomic guard)', function (): void {
    $org = utteranceOrg();
    $project = utteranceProject($org);
    $participant = utteranceParticipant($org, $project, 'in_corso');
    $session = utteranceSession($org, $participant, $project, 'completed');
    $token = utteranceBearer($participant);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $countBefore = Utterance::where('interview_session_id', $session->id)->count();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => $session->id,
            'speaker' => 'candidate',
            'text' => 'Too late.',
            'ts' => now()->toIso8601String(),
        ]);

    $response->assertStatus(409);

    // No new row must be persisted
    $countAfter = Utterance::where('interview_session_id', $session->id)->count();
    expect($countAfter)->toBe($countBefore);
});

test('POST /utterance with timeout session → 409 Conflict; no row persisted', function (): void {
    $org = utteranceOrg();
    $project = utteranceProject($org);
    $participant = utteranceParticipant($org, $project, 'in_corso');
    $session = utteranceSession($org, $participant, $project, 'timeout');
    $token = utteranceBearer($participant);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => $session->id,
            'speaker' => 'candidate',
            'text' => 'Too late.',
            'ts' => now()->toIso8601String(),
        ]);

    $response->assertStatus(409);
    expect(Utterance::where('interview_session_id', $session->id)->count())->toBe(0);
});

test('POST /utterance with session_id from different participant (same org) → 404', function (): void {
    $org = utteranceOrg();
    $project = utteranceProject($org);
    $participantX = utteranceParticipant($org, $project, 'in_corso', 'x');
    $participantY = utteranceParticipant($org, $project, 'in_corso', 'y');

    // Session belongs to X
    $sessionX = utteranceSession($org, $participantX, $project, 'in_corso', 'PRS');

    // Authenticated as Y — different participant, same org
    $token = utteranceBearer($participantY);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $countBefore = Utterance::where('interview_session_id', $sessionX->id)->count();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => $sessionX->id,
            'speaker' => 'candidate',
            'text' => 'Unauthorized.',
            'ts' => now()->toIso8601String(),
        ]);

    $response->assertNotFound();

    $countAfter = Utterance::where('interview_session_id', $sessionX->id)->count();
    expect($countAfter)->toBe($countBefore, 'No Utterance must be persisted for cross-participant attempt');
});

test('POST /utterance with session_id from different org → 404', function (): void {
    $orgA = utteranceOrg();
    $orgB = utteranceOrg();
    $projectA = utteranceProject($orgA);
    $projectB = utteranceProject($orgB);
    $participantA = utteranceParticipant($orgA, $projectA, 'in_corso', 'a');
    $participantB = utteranceParticipant($orgB, $projectB, 'in_corso', 'b');

    // Session belongs to orgB/participantB
    $sessionB = utteranceSession($orgB, $participantB, $projectB, 'in_corso');

    // Authenticated as participantA (orgA)
    $token = utteranceBearer($participantA);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgB->id);
    $resolver->setBypass(false);
    $countBefore = Utterance::where('interview_session_id', $sessionB->id)->count();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => $sessionB->id,
            'speaker' => 'candidate',
            'text' => 'Cross-tenant attempt.',
            'ts' => now()->toIso8601String(),
        ]);

    $response->assertNotFound();

    $resolver->setOrgId($orgB->id);
    $countAfter = Utterance::where('interview_session_id', $sessionB->id)->count();
    expect($countAfter)->toBe($countBefore, 'No Utterance must be persisted for cross-tenant attempt');
});

test('POST /utterance TOCTOU: session status completed atomically means 409 not 202', function (): void {
    // This test verifies the atomic guard: we set the session to 'completed' BEFORE
    // the request, simulating a concurrent /end having already committed.
    // The conditional INSERT (WHERE status='in_corso') must return 0 rows → 409.
    $org = utteranceOrg();
    $project = utteranceProject($org);
    $participant = utteranceParticipant($org, $project, 'in_corso');
    $session = utteranceSession($org, $participant, $project, 'in_corso');

    // Simulate concurrent /end committed right before our /utterance reaches the DB
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $session->status = 'completed';
    $session->save();

    $token = utteranceBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => $session->id,
            'speaker' => 'candidate',
            'text' => 'Late utterance.',
            'ts' => now()->toIso8601String(),
        ]);

    // The atomic WHERE status='in_corso' condition fails → 409
    $response->assertStatus(409);
    expect(Utterance::where('interview_session_id', $session->id)->count())->toBe(0);
});
