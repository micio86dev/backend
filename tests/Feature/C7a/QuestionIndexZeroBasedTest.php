<?php

declare(strict_types=1);

/**
 * The reader stops subtracting one (interview-question-index-offset, D1).
 *
 * `project_competencies.position` is 0-based at every writer
 * (`Api/ProjectController.php:98-99, 161-162`); `resolveNextCompetency()` used to
 * persist `question_index = position - 1`, so a project's FIRST competency stored
 * `-1`. Asserted through the REAL `/start` endpoint against a dense-from-0 project
 * (`casDenseProject()`), NOT a hand-written fixture — the existing coverage (e.g.
 * `InterviewStartTest.php:210`) writes `question_index => 0` directly on a
 * manually-constructed row and therefore cannot fail on this defect.
 *
 * REQ: interview-session "InterviewSession tenant model" — First competency's
 *      question_index is never negative.
 */

use App\Models\InterviewSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('POST /start on a project\'s first competency persists question_index = 0, not -1', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project, $competencies] = casDenseProject($org, 3);
    $participant = casParticipant($org, $project, 'in_attesa');

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    $session = casInTenant(
        $org,
        fn () => InterviewSession::where('participant_id', $participant->id)
            ->where('competency_code', $competencies[0]->code)
            ->firstOrFail(),
    );

    expect($session->question_index)
        ->toBe(0, 'the first competency (position = 0) must persist question_index = 0, never -1');
});

test('POST /start persists question_index = position for the second and third competencies too', function (): void {
    // The sibling test above pins only position 0, where the old `position - 1`
    // produced the eye-catching `-1`. But the defect shifted EVERY index down by
    // one, so a fix that special-cased the first competency would satisfy that
    // test and still be wrong for the rest. This drives the real /start -> /end ->
    // /start loop and pins the whole sequence.
    //
    // The backfill migration also proves non-zero positions, but through a
    // different code path — a raw UPDATE, not the controller. A write path is not
    // covered by a test of the repair path.
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project, $competencies] = casDenseProject($org, 3);
    $participant = casParticipant($org, $project, 'in_attesa');
    $token = casBearer($participant);

    $persisted = [];

    foreach ([0, 1, 2] as $expectedIndex) {
        $response = test()->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/candidate/interview/start');

        $response->assertStatus(201);

        $session = casInTenant(
            $org,
            fn () => InterviewSession::findOrFail($response->json('session_id')),
        );

        $persisted[$session->competency_code] = $session->question_index;

        test()->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/candidate/interview/end', [
                'session_id' => $session->id,
                'ended_reason' => 'completed',
            ])->assertSuccessful();
    }

    // Asserted per competency CODE rather than by iteration order: the sequence
    // the server hands out is its own decision, and pinning it here would make
    // this test fail for a reason it is not about.
    expect($persisted)->toBe([
        $competencies[0]->code => 0,
        $competencies[1]->code => 1,
        $competencies[2]->code => 2,
    ], 'every competency must persist question_index equal to its 0-based position');
});
