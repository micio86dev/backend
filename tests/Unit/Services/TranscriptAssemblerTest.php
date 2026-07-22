<?php

declare(strict_types=1);

/**
 * RED — Task 14.1: TranscriptAssembler (C9 D3 CW3).
 *
 * Verifies:
 * (a) Utterances ordered by ts then id (tiebreaker); format "{speaker}: {text}" joined by \n.
 * (b) Timestamp tie: id=42 before id=43.
 *
 * Refs spec: D3 CW3 "Transcript assembled with explicit orderBy ts then id".
 */

use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Services\Scoring\TranscriptAssembler;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function assemblerOrg(): Organization
{
    return Organization::factory()->create();
}

function assemblerProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function assemblerParticipant(Organization $org, Project $project): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'ta-test-'.uniqid(),
        'display_name' => 'TA Test',
        'status' => 'in_valutazione',
    ]);
    $p->save();

    return $p->fresh();
}

function assemblerSession(Organization $org, Project $project, Participant $participant, string $competencyCode = 'COL'): InterviewSession
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $competencyCode,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'status' => 'completed',
    ]);
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('(a) utterances ordered by ts then id; serialized as "{speaker}: {text}" joined by newline', function (): void {
    $org = assemblerOrg();
    $project = assemblerProject($org);
    $participant = assemblerParticipant($org, $project);
    $session = assemblerSession($org, $project, $participant);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    // Insert out-of-order intentionally; ts ordering must produce alphabetical order here.
    $u3 = new Utterance;
    $u3->forceFill([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        'speaker' => 'Avatar',
        'text' => 'Third utterance',
        'ts' => '2024-01-01 10:00:03',
    ]);
    $u3->save();

    $u1 = new Utterance;
    $u1->forceFill([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        'speaker' => 'Candidate',
        'text' => 'First utterance',
        'ts' => '2024-01-01 10:00:01',
    ]);
    $u1->save();

    $u2 = new Utterance;
    $u2->forceFill([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        'speaker' => 'Candidate',
        'text' => 'Second utterance',
        'ts' => '2024-01-01 10:00:02',
    ]);
    $u2->save();

    $assembler = new TranscriptAssembler;
    $transcript = $assembler->assemble($session);

    $expected = "Candidate: First utterance\nCandidate: Second utterance\nAvatar: Third utterance";
    expect($transcript)->toBe($expected);
});

test('(b) timestamp tie: lower id comes before higher id', function (): void {
    $org = assemblerOrg();
    $project = assemblerProject($org);
    $participant = assemblerParticipant($org, $project);
    $session = assemblerSession($org, $project, $participant, 'COM');

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $sameTs = '2024-01-01 10:00:00';

    $u1 = new Utterance;
    $u1->forceFill([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        'speaker' => 'Avatar',
        'text' => 'Earlier id',
        'ts' => $sameTs,
    ]);
    $u1->save();
    $firstId = $u1->id;

    $u2 = new Utterance;
    $u2->forceFill([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        'speaker' => 'Candidate',
        'text' => 'Later id',
        'ts' => $sameTs,
    ]);
    $u2->save();
    $secondId = $u2->id;

    // Sanity: second was inserted after first → higher id.
    expect($secondId)->toBeGreaterThan($firstId);

    $assembler = new TranscriptAssembler;
    $transcript = $assembler->assemble($session);

    $expected = "Avatar: Earlier id\nCandidate: Later id";
    expect($transcript)->toBe($expected);
});
