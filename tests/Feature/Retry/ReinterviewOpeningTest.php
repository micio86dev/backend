<?php

declare(strict_types=1);

/**
 * Which opening greeting `/start` speaks for a competency asked again by an
 * evaluation retry (scoring-retry-rt-b, slice PR2c).
 *
 * `reinterview` is the neutral greeting of a competency reset by an
 * authorized retry: no apology (nothing broke, the candidate did nothing
 * wrong) and no first-time greeting. Precedence, highest first:
 *   resume (a live in_corso session) > retry (provider-error re-offer)
 *   > reinterview (the evaluation carries retry_attempt) > first > next.
 *
 * Observed before this change: a retry-reset competency is `pending` with
 * the participant's `started_at` already set, so it opened as `next`, the
 * authored question verbatim with no cue that the interview was resuming.
 *
 * REQ: OpeningTextComposer Re-Interview Variant (Neutral)
 *      (openspec/changes/scoring-retry-rt-b/specs/interview-conversation/spec.md)
 */

use App\Models\Evaluation;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * A two-competency project mid-retry: competency 0 was valid and stays
 * `completed`, competency 1 is in `$secondStatus`. The participant is
 * `in_attesa` with `started_at` set, exactly as the authorization leaves it.
 *
 * @return array{org: Organization, project: Project, participant: Participant, bearer: string, session: InterviewSession}
 */
function reinterviewWorld(string $secondStatus, ?bool $retryAttempt = true, int $errorCount = 0): array
{
    Queue::fake();
    Http::fake(heygenOkFake());

    $org = casOrg();
    [$project, $comps] = casProject($org, 2);
    $participant = casParticipant($org, $project, 'in_attesa');

    $session = casInTenant($org, function () use ($org, $project, $participant, $comps, $secondStatus, $errorCount, $retryAttempt) {
        foreach ([0 => 'completed', 1 => $secondStatus] as $index => $status) {
            $s = new InterviewSession;
            $s->forceFill([
                'organization_id' => $org->id,
                'participant_id' => $participant->id,
                'project_id' => $project->id,
                'question_index' => $index,
                'competency_code' => $comps[$index]->code,
                'framework_version_id' => $project->framework_version_id,
                'provider' => 'heygen',
                'status' => $status,
                'ended_reason' => $status === 'error' ? 'error' : ($status === 'completed' ? 'completed' : null),
                'error_count' => $index === 1 ? $errorCount : 0,
                'started_at' => now()->subHour(),
                'ended_at' => $status === 'in_corso' || $status === 'pending' ? null : now()->subMinutes(30),
            ]);
            $s->save();
            $last = $s;
        }

        if ($retryAttempt !== null) {
            Evaluation::factory()->pending()->create([
                'participant_id' => $participant->id,
                'framework_version_id' => $project->framework_version_id,
                'retry_attempt' => $retryAttempt,
            ]);
        }

        return $last;
    });

    return [
        'org' => $org,
        'project' => $project,
        'participant' => $participant,
        'bearer' => casBearer($participant),
        'session' => $session,
    ];
}

/** The `opening_text` the provider was sent for the last `/contexts` call. */
function reinterviewSpokenOpening(): string
{
    $bodies = Http::recorded(fn ($request) => str_contains($request->url(), '/contexts'))
        ->map(fn (array $pair) => $pair[0]->data())
        ->values();

    expect($bodies)->not->toBeEmpty();

    return (string) $bodies->last()['opening_text'];
}

test('a competency reset to pending by a retry opens with the reinterview greeting', function (): void {
    $w = reinterviewWorld('pending');

    $this->withHeaders(['Authorization' => 'Bearer '.$w['bearer']])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    $expected = trans('interview.opening.reinterview_authored', ['question' => 'CAS fixture question 1'], $w['project']->language);

    expect(reinterviewSpokenOpening())->toBe($expected)
        ->and($expected)->not->toBe('CAS fixture question 1');
});

test('a provider-error re-offer inside the retry run keeps the retry greeting', function (): void {
    $w = reinterviewWorld('error', errorCount: 1);

    $this->withHeaders(['Authorization' => 'Bearer '.$w['bearer']])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    expect(reinterviewSpokenOpening())->toBe(
        trans('interview.opening.retry_authored', ['question' => 'CAS fixture question 1'], $w['project']->language)
    );
});

test('a retry-reset competency resumed mid-conversation keeps the resume greeting', function (): void {
    // I12: a live in_corso session re-issued at the provider re-asks the
    // pending primary verbatim; the retry flag does not change that.
    $w = reinterviewWorld('in_corso');

    $this->withHeaders(['Authorization' => 'Bearer '.$w['bearer']])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201)
        ->assertJsonPath('session_id', $w['session']->id);

    expect(reinterviewSpokenOpening())->toBe('CAS fixture question 1');
});

test('a participant with no evaluation retry never gets the reinterview greeting', function (?bool $retryAttempt): void {
    $w = reinterviewWorld('pending', $retryAttempt);

    $this->withHeaders(['Authorization' => 'Bearer '.$w['bearer']])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    // `next`: the authored question, verbatim.
    expect(reinterviewSpokenOpening())->toBe('CAS fixture question 1');
})->with([
    'no evaluation row' => [null],
    'evaluation without a retry authorization' => [false],
]);

test('another participant of the same organization being in a retry does not affect this one', function (): void {
    $w = reinterviewWorld('pending', retryAttempt: null);

    casInTenant($w['org'], function () use ($w): void {
        $other = casParticipant($w['org'], $w['project'], 'in_attesa');
        Evaluation::factory()->pending()->create([
            'participant_id' => $other->id,
            'framework_version_id' => $w['project']->framework_version_id,
            'retry_attempt' => true,
        ]);
    });

    $this->withHeaders(['Authorization' => 'Bearer '.$w['bearer']])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    expect(reinterviewSpokenOpening())->toBe('CAS fixture question 1');
});

test('a retry flag on an evaluation row of another organization does not switch the greeting', function (): void {
    $w = reinterviewWorld('pending');
    $otherOrg = Organization::factory()->create();
    DB::table('evaluations')->where('participant_id', $w['participant']->id)->update(['organization_id' => $otherOrg->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.$w['bearer']])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    expect(reinterviewSpokenOpening())->toBe('CAS fixture question 1');
});
