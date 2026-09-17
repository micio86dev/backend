<?php

declare(strict_types=1);

/**
 * RED — Task 28.3 (framework-catalogue-authoring PR7, D8): the transcript
 * audit.
 *
 * "No hidden questions" is auditable after the fact, not merely asserted in
 * the prompt: primary and follow-up avatar turns are distinguishable in the
 * stored transcript, the primary-marked turns match the session's own
 * `primary_questions` snapshot 1:1 in order, and a session that never asked
 * one of its primaries reports that as a violation — never silently
 * reclassifying a follow-up as the missing primary (the conservative,
 * over-reporting direction OQ-C accepts as a disclosed residual).
 *
 * Uses the shared `cas*()` fixtures (`tests/Helpers/C9Fixtures.php`,
 * autoloaded) and the real `/start` + `/utterance` endpoints — both live
 * write paths `TurnClassifier` is wired into.
 *
 * REQ: interview-conversation — "Primary And Follow-Up Turns Are
 * Distinguishable In Transcript/Telemetry".
 */

use App\Models\InterviewSession;
use App\Models\ProjectQuestion;
use App\Models\Utterance;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * A one-competency project whose competency has TWO primary questions, with
 * distinctive marker text so a divergence is observable.
 *
 * @return array{0: int, 1: string} [participant bearer's session_id, bearer token]
 */
function transcriptAuditStart(string $primaryOneText, string $primaryTwoText): array
{
    Queue::fake();
    Http::fake(heygenOkFake());

    $org = casOrg();
    [$project, $comps] = casProject($org, 1);

    // casProject() already seeded ONE `project_questions` row at position 0.
    ProjectQuestion::where('project_id', $project->id)
        ->where('competency_id', $comps[0]->id)
        ->where('position', 0)
        ->update(['text' => ['en' => $primaryOneText]]);

    ProjectQuestion::create([
        'project_id' => $project->id,
        'competency_id' => $comps[0]->id,
        'text' => ['en' => $primaryTwoText],
        'position' => 1,
    ]);

    $participant = casParticipant($org, $project, 'in_attesa');
    $bearer = casBearer($participant);

    $start = test()->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start');
    $start->assertStatus(201);

    app(TenantResolver::class)->setOrgId($org->id);
    app(TenantResolver::class)->setBypass(false);

    return [(int) $start->json('session_id'), $bearer];
}

function transcriptAuditPostUtterance(string $bearer, int $sessionId, string $speaker, string $text): void
{
    test()->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => $sessionId,
            'speaker' => $speaker,
            'text' => $text,
            'ts' => now()->toIso8601String(),
        ])
        ->assertStatus(202);
}

test('primary and follow-up avatar turns are marked, and the primary-marked turns match primary_questions 1:1 in order', function (): void {
    [$sessionId, $bearer] = transcriptAuditStart('Audit primary one.', 'Audit primary two.');

    // Avatar asks primary 1, verbatim.
    transcriptAuditPostUtterance($bearer, $sessionId, 'avatar', 'Audit primary one.');
    // Candidate answers — never classified (not an avatar turn).
    transcriptAuditPostUtterance($bearer, $sessionId, 'candidate', 'It was a difficult migration.');
    // Avatar probes with an ordinary follow-up — matches no unmatched primary.
    transcriptAuditPostUtterance($bearer, $sessionId, 'avatar', 'What made it difficult?');
    // Avatar asks primary 2, verbatim.
    transcriptAuditPostUtterance($bearer, $sessionId, 'avatar', 'Audit primary two.');

    $session = InterviewSession::findOrFail($sessionId);

    expect($session->primary_questions)->toBe(['Audit primary one.', 'Audit primary two.']);

    $primaryMarked = Utterance::where('interview_session_id', $sessionId)
        ->where('speaker', 'avatar')
        ->where('turn_kind', 'primary')
        ->orderBy('id')
        ->pluck('text')
        ->all();

    expect($primaryMarked)->toBe(['Audit primary one.', 'Audit primary two.']);

    $followUpMarked = Utterance::where('interview_session_id', $sessionId)
        ->where('speaker', 'avatar')
        ->where('turn_kind', 'follow_up')
        ->pluck('text')
        ->all();

    expect($followUpMarked)->toBe(['What made it difficult?']);

    // The candidate's own turn carries no classification at all.
    $candidateTurnKind = Utterance::where('interview_session_id', $sessionId)
        ->where('speaker', 'candidate')
        ->value('turn_kind');

    expect($candidateTurnKind)->toBeNull();

    // The audit: matched primary count equals the full snapshot — no violation.
    expect(count($primaryMarked))->toBe(count($session->primary_questions));
});

test('a session that never asks one of its primaries reports a violation, never a silent reclassification', function (): void {
    [$sessionId, $bearer] = transcriptAuditStart(
        'Audit primary one, unreached second.',
        'Audit primary two, unreached.',
    );

    // The avatar only ever asks primary 1 — primary 2 is never spoken.
    transcriptAuditPostUtterance($bearer, $sessionId, 'avatar', 'Audit primary one, unreached second.');

    // A rephrased echo of primary 2 — NOT an exact match, so it does NOT
    // silently satisfy it (D8's conservative, over-reporting direction).
    transcriptAuditPostUtterance(
        $bearer,
        $sessionId,
        'avatar',
        'Something in the neighbourhood of the second primary, reworded.',
    );

    $session = InterviewSession::findOrFail($sessionId);

    $matchedCount = Utterance::where('interview_session_id', $sessionId)
        ->where('speaker', 'avatar')
        ->where('turn_kind', 'primary')
        ->count();

    // The audit: matched (1) < snapshot count (2) — a reported violation,
    // never silently reclassified to make the count agree.
    expect($matchedCount)->toBe(1);
    expect($matchedCount)->toBeLessThan(count($session->primary_questions));

    $followUpMarked = Utterance::where('interview_session_id', $sessionId)
        ->where('speaker', 'avatar')
        ->where('turn_kind', 'follow_up')
        ->pluck('text')
        ->all();

    // The reworded echo of the missing primary sits in follow_up, not
    // primary — nothing quietly promoted it to close the gap.
    expect($followUpMarked)->toBe(['Something in the neighbourhood of the second primary, reworded.']);
});
