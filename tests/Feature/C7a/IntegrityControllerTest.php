<?php

declare(strict_types=1);

/**
 * IntegrityController feature tests (C7a — Interview Session Mechanics).
 *
 * Tests POST /api/candidate/interview/integrity
 *
 * Asserts:
 * - Batch of 3 valid kinds → HTTP 202; 3 IntegrityEvent rows persisted.
 * - Unknown kind 'unknown_event' → HTTP 422; no rows persisted.
 * - Mixed batch (1 valid + 1 unknown) → HTTP 422; no rows persisted (all-or-nothing).
 * - session_id from different participant → HTTP 404.
 *
 * All 14 canonical kinds are tested exhaustively via dataset.
 *
 * Tasks: 11.1 (RED)
 * REQ: POST /integrity — batch integrity-event ingestion (C7a)
 */

use App\Models\IntegrityEvent;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function integrityOrg(): Organization
{
    return Organization::factory()->create();
}

function integrityProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function integrityParticipant(Organization $org, Project $project, string $status = 'in_corso', string $suffix = ''): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'int-'.($suffix ?: uniqid()),
        'display_name' => 'Integrity Test',
        'status' => $status,
    ]);
    $p->save();

    return $p->fresh();
}

function integritySession(Organization $org, Participant $participant, Project $project, string $status = 'in_corso'): InterviewSession
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

function integrityBearer(Participant $participant): string
{
    return CandidateTokenFactory::mintCandidateToken($participant);
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('POST /integrity with 3 valid kinds → 202 and 3 IntegrityEvent rows persisted', function (): void {
    $org = integrityOrg();
    $project = integrityProject($org);
    $participant = integrityParticipant($org, $project, 'in_corso');
    $session = integritySession($org, $participant, $project, 'in_corso');
    $token = integrityBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/integrity', [
            'session_id' => $session->id,
            'events' => [
                ['kind' => 'tab_hidden',   'payload' => ['durationMs' => 3000], 'ts' => now()->toIso8601String()],
                ['kind' => 'focus_lost',   'payload' => [],                     'ts' => now()->toIso8601String()],
                ['kind' => 'face_absent',  'payload' => ['durationMs' => 1500], 'ts' => now()->toIso8601String()],
            ],
        ]);

    $response->assertStatus(202);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $events = IntegrityEvent::where('interview_session_id', $session->id)->get();
    expect($events)->toHaveCount(3);

    $kinds = $events->pluck('kind')->sort()->values()->all();
    expect($kinds)->toBe(['face_absent', 'focus_lost', 'tab_hidden']);
});

test('POST /integrity with unknown kind → 422; no rows persisted', function (): void {
    $org = integrityOrg();
    $project = integrityProject($org);
    $participant = integrityParticipant($org, $project, 'in_corso');
    $session = integritySession($org, $participant, $project, 'in_corso');
    $token = integrityBearer($participant);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $countBefore = IntegrityEvent::where('interview_session_id', $session->id)->count();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/integrity', [
            'session_id' => $session->id,
            'events' => [
                ['kind' => 'unknown_event', 'payload' => [], 'ts' => now()->toIso8601String()],
            ],
        ]);

    $response->assertStatus(422);

    $countAfter = IntegrityEvent::where('interview_session_id', $session->id)->count();
    expect($countAfter)->toBe($countBefore, 'No IntegrityEvent must be persisted for unknown kind');
});

test('POST /integrity with mixed batch (1 valid + 1 unknown) → 422; no rows persisted (all-or-nothing)', function (): void {
    $org = integrityOrg();
    $project = integrityProject($org);
    $participant = integrityParticipant($org, $project, 'in_corso');
    $session = integritySession($org, $participant, $project, 'in_corso');
    $token = integrityBearer($participant);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $countBefore = IntegrityEvent::where('interview_session_id', $session->id)->count();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/integrity', [
            'session_id' => $session->id,
            'events' => [
                ['kind' => 'tab_hidden',    'payload' => [], 'ts' => now()->toIso8601String()],
                ['kind' => 'cheat_attempt', 'payload' => [], 'ts' => now()->toIso8601String()],
            ],
        ]);

    $response->assertStatus(422);

    // All-or-nothing: even the valid event must not be persisted
    $countAfter = IntegrityEvent::where('interview_session_id', $session->id)->count();
    expect($countAfter)->toBe($countBefore, 'Even the valid event must NOT be persisted on a mixed-batch rejection');
});

test('POST /integrity with session_id from different participant → 404', function (): void {
    $org = integrityOrg();
    $project = integrityProject($org);
    $participantX = integrityParticipant($org, $project, 'in_corso', 'x');
    $participantY = integrityParticipant($org, $project, 'in_corso', 'y');

    // Session belongs to X
    $sessionX = integritySession($org, $participantX, $project, 'in_corso');

    // Authenticated as Y
    $token = integrityBearer($participantY);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $countBefore = IntegrityEvent::where('interview_session_id', $sessionX->id)->count();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/integrity', [
            'session_id' => $sessionX->id,
            'events' => [
                ['kind' => 'tab_hidden', 'payload' => [], 'ts' => now()->toIso8601String()],
            ],
        ]);

    $response->assertNotFound();

    $countAfter = IntegrityEvent::where('interview_session_id', $sessionX->id)->count();
    expect($countAfter)->toBe($countBefore, 'No IntegrityEvent must be persisted for cross-participant attempt');
});

// ─── Dataset: all 14 canonical kinds are accepted individually ────────────────

test('POST /integrity accepts all 14 canonical integrity kinds', function (string $kind) {
    $org = integrityOrg();
    $project = integrityProject($org);
    $participant = integrityParticipant($org, $project, 'in_corso');
    $session = integritySession($org, $participant, $project, 'in_corso');
    $token = integrityBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/integrity', [
            'session_id' => $session->id,
            'events' => [
                ['kind' => $kind, 'payload' => [], 'ts' => now()->toIso8601String()],
            ],
        ]);

    $response->assertStatus(202);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    expect(IntegrityEvent::where('interview_session_id', $session->id)->where('kind', $kind)->count())
        ->toBe(1, "Kind '{$kind}' must be accepted and persisted");
})->with([
    'tab_hidden',
    'focus_lost',
    'second_monitor',
    'face_absent',
    'looking_away',
    'looking_down',
    'too_far',
    'multiple_faces',
    'fullscreen_exit',
    'clipboard_copy',
    'clipboard_paste',
    'second_voice',
    'phone_detected',
    // Without this the api 422s the batch, and because validation is
    // ALL-OR-NOTHING a degraded client would lose every event it ever sent.
    'proctor_unavailable',
]);
