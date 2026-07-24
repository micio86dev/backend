<?php

declare(strict_types=1);

/**
 * Cross-participant session ownership tests (C7a — Interview Session Mechanics).
 *
 * Task 14.2 (PR 2): candidate X (org O) calling /utterance, /integrity, /snapshot
 * with session_id belonging to candidate Y (org O) → 404 from resolveOwnedSession;
 * no data mutation.
 *
 * These tests verify that resolveOwnedSession enforces participant_id isolation
 * within the SAME organization for all 3 PR2 ingestion endpoints.
 *
 * Tasks: 14.2 (RED)
 * REQ: Session ownership enforced at every endpoint — same-org, different-participant
 */

use App\Models\IntegrityEvent;
use App\Models\InterviewSession;
use App\Models\InterviewSnapshot;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Storage;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function ownershipOrg(): Organization
{
    return Organization::factory()->create();
}

function ownershipProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function ownershipParticipant(Organization $org, Project $project, string $suffix = ''): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'own-'.($suffix ?: uniqid()),
        'display_name' => 'Ownership Test',
        'status' => 'in_corso',
    ]);
    $p->save();

    return $p->fresh();
}

function ownershipSession(Organization $org, Participant $participant, Project $project): InterviewSession
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
        'status' => 'in_corso',
    ]);
}

function ownershipBearer(Participant $participant): string
{
    return CandidateTokenFactory::mintCandidateToken($participant);
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('candidate X calling /utterance with session owned by candidate Y (same org) → 404; no Utterance persisted', function (): void {
    $org = ownershipOrg();
    $project = ownershipProject($org);
    $participantX = ownershipParticipant($org, $project, 'x');
    $participantY = ownershipParticipant($org, $project, 'y');

    // Session owned by Y
    $sessionY = ownershipSession($org, $participantY, $project);

    // X tries to post to Y's session
    $tokenX = ownershipBearer($participantX);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $countBefore = Utterance::where('interview_session_id', $sessionY->id)->count();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$tokenX])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => $sessionY->id,
            'speaker' => 'candidate',
            'text' => 'Cross-participant attempt.',
            'ts' => now()->toIso8601String(),
        ]);

    $response->assertNotFound();

    $countAfter = Utterance::where('interview_session_id', $sessionY->id)->count();
    expect($countAfter)->toBe($countBefore, 'No Utterance must be persisted for cross-participant /utterance');
});

test('candidate X calling /integrity with session owned by candidate Y (same org) → 404; no IntegrityEvent persisted', function (): void {
    $org = ownershipOrg();
    $project = ownershipProject($org);
    $participantX = ownershipParticipant($org, $project, 'x2');
    $participantY = ownershipParticipant($org, $project, 'y2');

    // Session owned by Y
    $sessionY = ownershipSession($org, $participantY, $project);

    // X tries to post to Y's session
    $tokenX = ownershipBearer($participantX);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $countBefore = IntegrityEvent::where('interview_session_id', $sessionY->id)->count();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$tokenX])
        ->postJson('/api/candidate/interview/integrity', [
            'session_id' => $sessionY->id,
            'events' => [
                ['kind' => 'tab_hidden', 'payload' => [], 'ts' => now()->toIso8601String()],
            ],
        ]);

    $response->assertNotFound();

    $countAfter = IntegrityEvent::where('interview_session_id', $sessionY->id)->count();
    expect($countAfter)->toBe($countBefore, 'No IntegrityEvent must be persisted for cross-participant /integrity');
});

test('candidate X calling /snapshot with session owned by candidate Y (same org) → 404; no InterviewSnapshot persisted', function (): void {
    Storage::fake('s3');

    $org = ownershipOrg();
    $project = ownershipProject($org);
    $participantX = ownershipParticipant($org, $project, 'x3');
    $participantY = ownershipParticipant($org, $project, 'y3');

    // Session owned by Y
    $sessionY = ownershipSession($org, $participantY, $project);

    // X tries to post to Y's session
    $tokenX = ownershipBearer($participantX);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $countBefore = InterviewSnapshot::where('interview_session_id', $sessionY->id)->count();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$tokenX])
        ->postJson('/api/candidate/interview/snapshot', [
            'session_id' => $sessionY->id,
            'image_base64' => base64_encode("\xFF\xD8\xFF\xE0".str_repeat('A', 100)),
        ]);

    $response->assertNotFound();

    // No S3 write
    Storage::disk('s3')->assertDirectoryEmpty('');

    $countAfter = InterviewSnapshot::where('interview_session_id', $sessionY->id)->count();
    expect($countAfter)->toBe($countBefore, 'No InterviewSnapshot must be persisted for cross-participant /snapshot');
});
