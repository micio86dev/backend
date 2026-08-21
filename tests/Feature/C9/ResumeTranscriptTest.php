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
