<?php

declare(strict_types=1);

/**
 * The three guards on the utterance write paths, each asserted against the
 * mutation that would remove it.
 *
 * They exist for reasons the code states but nothing checked: a QueryException
 * out of an utterance insert carries the candidate's verbatim speech in
 * `getMessage()`, and a DELETE without `organization_id` is the one query on
 * these paths that is not tenant-scoped.
 */

use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Utterance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/** A provider transcript body built from [role, text] pairs. */
function guardTranscriptFor(array $turns): array
{
    return ['data' => ['transcript_data' => array_map(
        fn ($t, $i) => ['role' => $t[0], 'transcript' => $t[1], 'time_ms' => 1000 * ($i + 1)],
        $turns,
        array_keys($turns),
    )]];
}

/** Fake a provider whose transcript turns are the given [role, text] pairs. */
function guardHttpFake(array $perRef): void
{
    $refs = [];
    Http::fake(function ($request) use (&$refs, $perRef) {
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
            foreach ($perRef as $ref => $turns) {
                if (str_contains($url, (string) $ref)) {
                    return Http::response(guardTranscriptFor($turns), 200);
                }
            }

            return Http::response(guardTranscriptFor([]), 200);
        }

        return Http::response([], 200);
    });
}

test('a failed utterance insert never writes the candidate speech into the log', function (): void {
    Queue::fake();

    $secret = 'My salary at my previous employer was ninety thousand euro.';
    guardHttpFake(['ref-0' => [['user', $secret]]]);

    // Force a real QueryException out of the insert. Narrowing `speaker` is the
    // cheapest way in: the provider normalises the role to 'candidate'/'avatar',
    // so any width below that overflows. WHICH error fires does not matter —
    // QueryException::formatMessage() interpolates every binding into the message
    // on all of them, and the `text` binding is the candidate's speech.
    DB::statement('ALTER TABLE utterances ALTER COLUMN speaker TYPE varchar(3)');

    Log::spy();

    $org = casOrg();
    [$project] = casProject($org, 2);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    // /start, then a resume — the resume is what harvests the outgoing session.
    foreach (range(0, 1) as $ignored) {
        // 201, not 500: the candidate keeps the interview even though the
        // transcript could not be stored. issue() has already minted — and is
        // already billing for — a fresh provider session by this point.
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/candidate/interview/start')->assertStatus(201);
    }

    // The QueryException itself is logged HERE, inside insertUtterances(). This
    // is the only line that ever holds it: the outer handler receives the
    // RuntimeException rethrown in its place, whose message carries nothing.
    // Point the assertion at the log that can actually leak.
    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context) use ($secret): bool {
            return $message === 'C9: utterance insert failed'
                && ! str_contains(json_encode($context) ?: '', $secret);
        })
        ->once();

    // And the resume continued past it.
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => $message === 'C9: harvest insert failed, continuing the resume')
        ->once();
});

test('the harvest DELETE cannot reach another tenant rows', function (): void {
    Queue::fake();

    guardHttpFake([
        'ref-0' => [['assistant', 'First question?'], ['user', 'An answer.']],
    ]);

    $org = casOrg();
    [$project] = casProject($org, 2);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    $session = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->first());

    // A row belonging to ANOTHER organization, planted on this session id. The
    // id collision is contrived; the point is that only `organization_id` in the
    // predicate stands between the DELETE and a foreign tenant's row.
    $other = Organization::factory()->create();
    DB::table('utterances')->insert([
        'interview_session_id' => $session->id,
        'organization_id' => $other->id,
        'speaker' => 'candidate',
        'text' => 'A different tenant utterance.',
        'ts' => now()->toDateTimeString(),
    ]);

    // The resume harvests, and its DELETE must leave the foreign row alone.
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    $survived = casInTenant($other, fn () => Utterance::where('interview_session_id', $session->id)
        ->where('text', 'A different tenant utterance.')->count());

    expect($survived)->toBe(1);
});

test('a failed LIVE utterance insert never writes the candidate speech into the log', function (): void {
    Queue::fake();

    $secret = 'I left that role after a disagreement with my manager.';
    guardHttpFake(['ref-0' => []]);

    $org = casOrg();
    [$project] = casProject($org, 2);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    $session = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->first());

    // Force a real QueryException out of the live INSERT. This path binds the
    // candidate's speech directly and runs once per turn of every interview —
    // the highest-volume utterance write in the product.
    DB::statement('ALTER TABLE utterances ALTER COLUMN speaker TYPE varchar(3)');

    Log::spy();

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => $session->id,
            'speaker' => 'candidate',
            'text' => $secret,
            'ts' => now()->toIso8601String(),
        ]);

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context) use ($secret): bool {
            return $message === 'C7a: live utterance insert failed'
                && ! str_contains(json_encode($context) ?: '', $secret);
        })
        ->once();
});
