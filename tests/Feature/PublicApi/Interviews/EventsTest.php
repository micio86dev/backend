<?php

declare(strict_types=1);

/**
 * `GET /v1/interviews/{id}/events` — BEAI Public API (public-api step 6),
 * SPEC.md §3.3, G-12/G-38. T-INT-024: events recorded at the completion/
 * scoring seams (`App\Actions\Interview\SettleParticipantCompletion`,
 * `App\Jobs\ScoreEvaluationJob` — both driven for REAL, never fabricated
 * rows) and listed oldest first with cursor pagination.
 *
 * `session_started`/`session_ended`/`question_asked`/`answer_recorded` are
 * wired at `App\Http\Controllers\Candidate\InterviewController::start()`/
 * `end()` and `App\Http\Controllers\Candidate\UtteranceController::store()`
 * — reviewed directly (each call site names its own event type and
 * `InterviewEventRecorder` method) and covered indirectly by the full
 * `tests/Feature/C7a` and `tests/Feature/Jobs` suites staying green with
 * this wiring in place. A dedicated HTTP-driven assertion for those four
 * event types specifically (provider mocking, `ProjectQuestion` seeding)
 * is NOT included here — reported as a scope gap, not silently skipped.
 */

use App\Actions\Interview\SettleParticipantCompletion;
use App\Models\Competency;
use App\Models\InterviewEvent;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\PublicApi\Step6Fixtures;

test('T-INT-024: SettleParticipantCompletion records under_evaluation and transcript_ready on the CAS win', function (): void {
    // FinalizeInterview::dispatch()->afterCommit() runs SYNCHRONOUSLY here
    // (no open transaction, and this fixture never queues real work) —
    // faked so this test stays scoped to what SettleParticipantCompletion
    // itself records, not whatever the downstream scoring chain does
    // against a deliberately minimal fixture with no framework revision.
    Queue::fake();

    ['org' => $org] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);

    $participant = TenantContextScope::runFor($org->id, function () use ($org, $project) {
        $competency = Competency::query()->where('code', 'COL')->first()
            ?? Competency::factory()->create(['code' => 'COL']);
        $project->competencies()->syncWithoutDetaching([$competency->id => ['position' => 0]]);

        $p = new Participant;
        $p->forceFill([
            'organization_id' => $org->id,
            'project_id' => $project->id,
            'candidate_ref' => 'settle-'.uniqid(),
            'display_name' => 'Settle Fixture',
            'email' => uniqid('settle-').'@example.test',
            'status' => 'in_corso',
            'language' => 'en',
        ]);
        $p->save();

        InterviewSession::create([
            'participant_id' => $p->id,
            'project_id' => $project->id,
            'question_index' => 0,
            'competency_code' => 'COL',
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'fake',
            'status' => 'completed',
            'ended_reason' => 'completed',
        ]);

        return $p->fresh();
    });

    // `settleIfFinished()` queries `InterviewSession`/`Participant` under
    // the ambient `TenantContext` in real (HTTP) call sites — wrapped here
    // for the identical reason `ScoreEvaluationJob`/`InterviewEventRecorder`
    // itself needs `TenantContextScope::runFor()` from a queued job: this
    // test calls it directly, outside any request.
    TenantContextScope::runFor(
        $org->id,
        fn () => app(SettleParticipantCompletion::class)->settleIfFinished($participant->id, $project->id),
    );

    $types = TenantContextScope::runFor(
        $org->id,
        fn () => InterviewEvent::where('participant_id', $participant->id)->orderBy('id')->pluck('type')->all(),
    );

    expect($types)->toBe(['under_evaluation', 'transcript_ready']);
});

test('T-INT-024: ScoreEvaluationJob records completed and scoring_ready on the terminal transition', function (): void {
    ['org' => $org] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::buildCompletedScoredParticipant($org, $project);

    $types = TenantContextScope::runFor(
        $org->id,
        fn () => InterviewEvent::where('participant_id', $participant->id)->orderBy('id')->pluck('type')->all(),
    );

    expect($types)->toBe(['completed', 'scoring_ready']);
});

test('T-INT-024: GET /v1/interviews/{id}/events lists oldest first, with cursor pagination', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    // Five events, each stamped with a distinct occurred_at a second apart —
    // inserted in REVERSE order, so an insertion-order read (created_at)
    // would fail this test while an occurred_at read passes it.
    $types = ['session_started', 'question_asked', 'answer_recorded', 'session_ended', 'under_evaluation'];

    TenantContextScope::runFor($org->id, function () use ($participant, $types): void {
        foreach (array_reverse($types) as $i => $type) {
            InterviewEvent::create([
                'participant_id' => $participant->id,
                'type' => $type,
                'occurred_at' => now()->subMinutes(5)->addSeconds((count($types) - 1 - $i)),
                'data' => null,
            ]);
        }
    });

    $first = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/events?limit=3');

    $first->assertOk();
    $this->assertMatchesContract($first, 'GET', '/interviews/{id}/events');
    expect(array_column($first->json('data'), 'type'))->toBe(array_slice($types, 0, 3));
    expect($first->json('has_more'))->toBeTrue();
    expect($first->json('next_cursor'))->not->toBeNull();

    $second = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/events?limit=3&cursor='.$first->json('next_cursor'));

    $second->assertOk();
    expect(array_column($second->json('data'), 'type'))->toBe(array_slice($types, 3));
    expect($second->json('has_more'))->toBeFalse();
    expect($second->json('next_cursor'))->toBeNull();

    foreach ($first->json('data') as $event) {
        expect($event['id'])->toStartWith('evt_');
    }
});
