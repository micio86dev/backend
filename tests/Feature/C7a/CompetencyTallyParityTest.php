<?php

declare(strict_types=1);

/**
 * RED → GREEN — cross-surface parity between the candidate `/end` directive
 * and `CompetencyTally::ended()` (operator-participant-visibility D5, task
 * 2.2).
 *
 * The admin surface (future `ParticipantInterviewAggregator`) and the
 * candidate `/end` directive MUST report the exact same integer for "how
 * many competencies has this participant ended" — they are two callers of
 * ONE definition, `CompetencyTally::ended()`, never two implementations that
 * could silently disagree. This is the test that would have caught the
 * divergence D5 refuses to create: a fixture with a spent-retry `error`
 * session mixed with normally-ended ones — the exact case a naive
 * re-implementation would get wrong.
 *
 * REQ: Participant Detail Summary Fields
 *      (openspec/changes/operator-participant-visibility/specs/admin-read-api/spec.md)
 */

use App\Models\InterviewSession;
use App\Support\Interview\CompetencyTally;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('admin CompetencyTally::ended() and the candidate /end directive report the same integer, including a spent-retry error session', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project, $comps] = casProject($org, 3);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    casInTenant($org, function () use ($org, $project, $participant, $comps): void {
        $resolver = app(TenantResolver::class);
        $resolver->setOrgId($org->id);
        $resolver->setBypass(false);

        // Competency 0: already normally ended.
        InterviewSession::create([
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => 0,
            'competency_code' => $comps[0]->code,
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'heygen',
            'provider_session_ref' => 'ref-comp0',
            'status' => 'completed',
            'started_at' => now()->subMinutes(5),
            'ended_at' => now()->subMinutes(4),
        ]);

        // Competency 1: a spent-retry error — the one case a naive
        // re-implementation of the predicate would get wrong.
        $errorSession = InterviewSession::create([
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => 1,
            'competency_code' => $comps[1]->code,
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'heygen',
            'provider_session_ref' => 'ref-comp1',
            'status' => 'error',
        ]);
        DB::table('interview_sessions')
            ->where('id', $errorSession->id)
            ->update(['error_count' => InterviewSession::MAX_ERROR_ATTEMPTS]);
    });

    // Competency 2 is the only one with no session yet — /start resolves to
    // it (comp 0 is terminal, comp 1's spent-retry error is terminal too).
    $startResponse = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    expect($startResponse->json('question_context.competency_code'))->toBe($comps[2]->code);

    $sessionId = $startResponse->json('session_id');

    $endResponse = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $sessionId,
            'ended_reason' => 'completed',
        ])
        ->assertStatus(200);

    $candidateSideEnded = $endResponse->json('ended_competencies');

    $adminSideEnded = casInTenant($org, fn () => (new CompetencyTally)->ended($participant->id, $project->id));

    expect($candidateSideEnded)->toBe(3);
    expect($adminSideEnded)->toBe($candidateSideEnded);
});
