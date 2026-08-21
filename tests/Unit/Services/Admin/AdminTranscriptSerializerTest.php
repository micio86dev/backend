<?php

declare(strict_types=1);

/**
 * RED — AdminTranscriptSerializer (C11, task 5.2, PR A2; DTO + partial marker
 * per operator-participant-visibility D2, task 1.9).
 *
 * A participant has ONE InterviewSession per competency (design D6,
 * InterviewSession.php:48-49) — this is new assembly logic. TranscriptAssembler
 * (C9, TranscriptAssembler.php:34) takes a SINGLE session; here we iterate ALL
 * of a participant's sessions.
 *
 * Verifies:
 * (a) sessions are grouped and ordered by question_index then session id.
 * (b) each session's utterances are ordered by ts then id (mirrors
 *     TranscriptAssembler.php:11-14's dual-sort discipline).
 * (c) `serialize()` returns an `AdminTranscript` DTO whose `isPartial` reuses
 *     the OLD (`in_valutazione`) threshold — turn assembly is unchanged (D2).
 *
 * REQ: Evaluation Serializer Is Scoped, Not Copied From the Webhook Assembler
 *      (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md — transcript half)
 *      Partial Transcript Disclosure Marker
 *      (openspec/changes/operator-participant-visibility/specs/admin-read-api/spec.md)
 */

use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Services\Admin\AdminTranscript;
use App\Services\Admin\AdminTranscriptSerializer;
use App\Support\Tenancy\TenantResolver;

function transcriptSerializerOrg(): Organization
{
    return Organization::factory()->create();
}

function transcriptSerializerProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function transcriptSerializerParticipant(Project $project, string $status = 'in_valutazione'): Participant
{
    return Participant::factory()->forProject($project)->withStatus($status)->create();
}

function transcriptSerializerSession(Project $project, Participant $participant, string $competencyCode, int $questionIndex): InterviewSession
{
    return InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => $questionIndex,
        'competency_code' => $competencyCode,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'status' => 'completed',
    ]);
}

test('sessions are grouped and ordered by question_index then session id; utterances within each session by ts then id', function (): void {
    $org = transcriptSerializerOrg();
    $project = transcriptSerializerProject($org);
    $participant = transcriptSerializerParticipant($project);

    // Session TWO (question_index=1) is created FIRST, session ONE
    // (question_index=0) SECOND — proves ordering is by question_index, not
    // insertion/id order.
    $sessionTwo = transcriptSerializerSession($project, $participant, 'COM', 1);
    $sessionOne = transcriptSerializerSession($project, $participant, 'COL', 0);

    // Session one utterances, inserted out of ts order.
    Utterance::create([
        'interview_session_id' => $sessionOne->id,
        'speaker' => 'Avatar',
        'text' => 'Session one, second utterance',
        'ts' => '2024-01-01 10:00:02',
    ]);
    Utterance::create([
        'interview_session_id' => $sessionOne->id,
        'speaker' => 'Candidate',
        'text' => 'Session one, first utterance',
        'ts' => '2024-01-01 10:00:01',
    ]);

    // Session two utterance.
    Utterance::create([
        'interview_session_id' => $sessionTwo->id,
        'speaker' => 'Candidate',
        'text' => 'Session two, first utterance',
        'ts' => '2024-01-01 11:00:00',
    ]);

    $serializer = new AdminTranscriptSerializer;
    $result = $serializer->serialize($participant);

    expect($result)->toBeInstanceOf(AdminTranscript::class);

    $sessions = $result->sessions;
    expect($sessions)->toHaveCount(2);

    expect($sessions[0]['session_id'])->toBe($sessionOne->id);
    expect($sessions[0]['competency_code'])->toBe('COL');
    expect($sessions[0]['utterances'])->toHaveCount(2);
    expect($sessions[0]['utterances'][0]['text'])->toBe('Session one, first utterance');
    expect($sessions[0]['utterances'][1]['text'])->toBe('Session one, second utterance');

    expect($sessions[1]['session_id'])->toBe($sessionTwo->id);
    expect($sessions[1]['competency_code'])->toBe('COM');
    expect($sessions[1]['utterances'])->toHaveCount(1);
});

test('ts tie: lower utterance id sorts first', function (): void {
    $org = transcriptSerializerOrg();
    $project = transcriptSerializerProject($org);
    $participant = transcriptSerializerParticipant($project);
    $session = transcriptSerializerSession($project, $participant, 'COL', 0);

    $sameTs = '2024-01-01 10:00:00';

    $u1 = Utterance::create([
        'interview_session_id' => $session->id,
        'speaker' => 'Avatar',
        'text' => 'Earlier id',
        'ts' => $sameTs,
    ]);
    $u2 = Utterance::create([
        'interview_session_id' => $session->id,
        'speaker' => 'Candidate',
        'text' => 'Later id',
        'ts' => $sameTs,
    ]);

    expect($u2->id)->toBeGreaterThan($u1->id);

    $serializer = new AdminTranscriptSerializer;
    $result = $serializer->serialize($participant);

    expect($result->sessions[0]['utterances'][0]['text'])->toBe('Earlier id');
    expect($result->sessions[0]['utterances'][1]['text'])->toBe('Later id');
});

// ── D2 — isPartial reuses the OLD (in_valutazione) threshold ──

test('isPartial is true for in_corso and errore, false for in_valutazione and completato', function (string $status, bool $expectedPartial): void {
    $org = transcriptSerializerOrg();
    $project = transcriptSerializerProject($org);
    $participant = transcriptSerializerParticipant($project, $status);
    transcriptSerializerSession($project, $participant, 'COL', 0);

    $serializer = new AdminTranscriptSerializer;
    $result = $serializer->serialize($participant);

    expect($result->isPartial)->toBe($expectedPartial);
})->with([
    'in_corso is partial' => ['in_corso', true],
    'errore is partial' => ['errore', true],
    'in_valutazione is complete' => ['in_valutazione', false],
    'completato is complete' => ['completato', false],
]);
