<?php

declare(strict_types=1);

/**
 * A RESUME must not destroy what the candidate already said.
 *
 * Observed in production (participant 17): two competencies whose only stored
 * turns were the resume greeting and a single word, while an un-resumed one
 * held 27. The candidate had answered all three.
 *
 * `handleResumeInCorso` issues a FRESH provider session with a new
 * `provider_session_ref` and tears the old one down WITHOUT reading its
 * transcript. At `/end`, `reconcileTranscript()` fetches only the surviving
 * session's turns and `replaceUtterances()` DELETEs everything before inserting
 * them — so every turn spoken before the resume is erased. The BARS evaluation
 * then scores the candidate on a fragment.
 */

use App\Models\InterviewSession;
use App\Models\Utterance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/** A transcript body for a given provider session ref. */
function transcriptFor(array $turns): array
{
    return ['data' => ['transcript_data' => array_map(
        fn ($t, $i) => ['role' => $t[0], 'transcript' => $t[1], 'time_ms' => 1000 * ($i + 1)],
        $turns,
        array_keys($turns),
    )]];
}

test('resuming an in_corso competency preserves the turns spoken before the resume', function (): void {
    Queue::fake();

    $refs = [];
    Http::fake(function ($request) use (&$refs) {
        $url = $request->url();

        if (str_contains($url, '/contexts')) {
            return Http::response(['data' => ['id' => 'ctx-'.uniqid()]], 200);
        }

        if (str_contains($url, '/sessions/token')) {
            $ref = 'ref-'.count($refs);
            $refs[] = $ref;

            return Http::response(['data' => ['session_id' => $ref, 'session_token' => 'tok']], 200);
        }

        if (str_contains($url, '/transcript')) {
            // The FIRST provider session holds the real conversation; the second,
            // freshly issued on resume, holds only its own greeting.
            return Http::response(str_contains($url, 'ref-0')
                ? transcriptFor([['assistant', 'First question?'], ['user', 'A long first answer.']])
                : transcriptFor([['assistant', 'Picking up where we left off.']]), 200);
        }

        return Http::response([], 200);
    });

    $org = casOrg();
    [$project] = casProject($org, 2);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    // First /start — the conversation happens in provider session ref-0.
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    $session = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->first());

    // Second /start on the same in_corso competency — the RESUME. ref-0 is torn
    // down and replaced by ref-1.
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => 'completed',
        ])->assertStatus(200);

    $texts = casInTenant($org, fn () => Utterance::where('interview_session_id', $session->id)
        ->orderBy('id')->pluck('text')->all());

    expect($texts)->toContain('A long first answer.');
    expect($texts)->toContain('Picking up where we left off.');
});

/**
 * The SECOND resume used to destroy the first provider session's turns.
 *
 * `harvestOutgoingTranscript()` deleted this session's stored rows before
 * inserting the provider's copy, unconditionally. On the first resume that is
 * correct — the rows are the live `/utterance` path's partial copy of the same
 * provider session, and the fetched copy is their superset. On the SECOND it is
 * not: the provider answers for the CURRENT `provider_session_ref` only, so the
 * stored rows belong to a session the fetch knows nothing about, and deleting
 * them erases turns the candidate actually spoke.
 *
 * The fix is the predicate `replaceUtterances()` already guards its own DELETE
 * with: delete on the first harvest, append after. This test is the mutation —
 * drop that predicate and it goes red on 'A long first answer.'.
 */
test('a SECOND resume appends rather than destroying the first session turns', function (): void {
    Queue::fake();

    $refs = [];
    Http::fake(function ($request) use (&$refs) {
        $url = $request->url();

        if (str_contains($url, '/contexts')) {
            return Http::response(['data' => ['id' => 'ctx-'.uniqid()]], 200);
        }

        if (str_contains($url, '/sessions/token')) {
            $ref = 'ref-'.count($refs);
            $refs[] = $ref;

            return Http::response(['data' => ['session_id' => $ref, 'session_token' => 'tok']], 200);
        }

        // Each provider session answers with ITS OWN turns only — the real
        // single-session behaviour of `GET /sessions/{ref}/transcript`, and the
        // reason a blind DELETE loses the earlier ones.
        if (str_contains($url, '/transcript')) {
            return match (true) {
                str_contains($url, 'ref-0') => Http::response(transcriptFor([
                    ['assistant', 'First question?'],
                    ['user', 'A long first answer.'],
                ]), 200),
                str_contains($url, 'ref-1') => Http::response(transcriptFor([
                    ['assistant', 'Back again.'],
                    ['user', 'A second answer, after the first drop.'],
                ]), 200),
                default => Http::response(transcriptFor([
                    ['user', 'A third answer, after the second drop.'],
                ]), 200),
            };
        }

        return Http::response([], 200);
    });

    $org = casOrg();
    [$project] = casProject($org, 2);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    // /start, then TWO resumes on the same in_corso competency: ref-0 → ref-1 → ref-2.
    foreach (range(0, 2) as $ignored) {
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/candidate/interview/start')->assertStatus(201);
    }

    $session = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->first());

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => 'completed',
        ])->assertStatus(200);

    $texts = casInTenant($org, fn () => Utterance::where('interview_session_id', $session->id)
        ->orderBy('id')->pluck('text')->all());

    // The surviving set is the UNION of all three provider sessions, not the last one.
    expect($texts)->toContain('A long first answer.');
    expect($texts)->toContain('A second answer, after the first drop.');
    expect($texts)->toContain('A third answer, after the second drop.');

    // And EXACTLY the union: asserting presence alone passes just as happily on a
    // transcript that repeats every post-resume turn, which is what suppressing
    // the DELETE rather than bounding it produces.
    expect($texts)->toHaveCount(5);
    expect(array_count_values($texts))->each->toBe(1);
});

/**
 * The live `/utterance` path and the provider's copy describe the SAME turns.
 *
 * `UtteranceController` is provider-agnostic — its only predicate is
 * `status = 'in_corso'` — so every stretch has live rows already stored by the
 * time its provider copy is fetched. A harvest that appends without deleting
 * them stores each of those turns twice, and BARS scores the candidate repeating
 * themselves. Bounding the DELETE by the watermark is what keeps both true at
 * once: earlier stretches survive, the current one is replaced.
 */
test('live utterances of the current stretch are replaced, not duplicated, by the provider copy', function (): void {
    Queue::fake();

    $refs = [];
    Http::fake(function ($request) use (&$refs) {
        $url = $request->url();

        if (str_contains($url, '/contexts')) {
            return Http::response(['data' => ['id' => 'ctx-'.uniqid()]], 200);
        }

        if (str_contains($url, '/sessions/token')) {
            $ref = 'ref-'.count($refs);
            $refs[] = $ref;

            return Http::response(['data' => ['session_id' => $ref, 'session_token' => 'tok']], 200);
        }

        if (str_contains($url, '/transcript')) {
            return Http::response(str_contains($url, 'ref-0')
                ? transcriptFor([['user', 'Stretch one answer.']])
                : transcriptFor([['user', 'Stretch two answer.']]), 200);
        }

        return Http::response([], 200);
    });

    $org = casOrg();
    [$project] = casProject($org, 2);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);
    $headers = ['Authorization' => 'Bearer '.$token];

    $this->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertStatus(201);
    $session = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->first());

    // The live path stores stretch one as it is spoken.
    $this->withHeaders($headers)->postJson('/api/candidate/interview/utterance', [
        'session_id' => $session->id,
        'speaker' => 'candidate',
        'text' => 'Stretch one answer.',
        'ts' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Resume: the provider's copy of stretch one replaces the live row, not doubles it.
    $this->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertStatus(201);

    // The live path stores stretch two, on the NEW provider session.
    $this->withHeaders($headers)->postJson('/api/candidate/interview/utterance', [
        'session_id' => $session->id,
        'speaker' => 'candidate',
        'text' => 'Stretch two answer.',
        'ts' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->withHeaders($headers)->postJson('/api/candidate/interview/end', [
        'session_id' => $session->id,
        'ended_reason' => 'completed',
    ])->assertStatus(200);

    $texts = casInTenant($org, fn () => Utterance::where('interview_session_id', $session->id)
        ->orderBy('id')->pluck('text')->all());

    expect(array_count_values($texts))->toBe([
        'Stretch one answer.' => 1,
        'Stretch two answer.' => 1,
    ]);
});
