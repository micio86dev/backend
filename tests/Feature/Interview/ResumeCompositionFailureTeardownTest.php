<?php

declare(strict_types=1);

/**
 * A resume that answers 422 must not leave the outgoing provider session
 * running.
 *
 * `start()`'s resume branch harvests the outgoing transcript BEFORE it
 * composes, and a composition failure answers 422 without issuing a fresh
 * provider session. The outgoing one is still live at that point: HeyGen and
 * Tavus bill live conversation minutes, so a session nobody tears down keeps
 * billing until the provider's own idle ceiling, for an interview the
 * candidate was just refused.
 *
 * The 422 path therefore runs the same tail `/suspend` runs — close the live
 * period, tear the provider session down, forget the ref — leaving the row
 * `in_corso` with a NULL ref, which is exactly the state the next `/start`
 * resumes once the catalogue is fixed.
 *
 * Uses the shared `cas*()` fixtures (`tests/Helpers/C9Fixtures.php`).
 */

use App\Models\BarsIndicator;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLivePeriod;
use App\Models\ProjectQuestion;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * A HeyGen fake that hands out a NEW `session_id` per `/sessions/token` call
 * and records which ref every teardown DELETE targeted.
 *
 * @return object{tokens: list<string>, teardownRefs: list<string>}
 */
function compositionTeardownFake(): object
{
    $calls = new class
    {
        /** @var list<string> */
        public array $tokens = [];

        /** @var list<string> */
        public array $teardownRefs = [];
    };

    Http::fake(function ($request) use ($calls) {
        $url = $request->url();

        if (str_contains($url, '/contexts')) {
            return Http::response(['data' => ['id' => 'ctx-'.uniqid()]], 200);
        }

        if (str_contains($url, '/sessions/token')) {
            $ref = 'heygen-composition-'.(count($calls->tokens) + 1);
            $calls->tokens[] = $ref;

            return Http::response(['data' => [
                'session_id' => $ref,
                'session_token' => 'tok-'.count($calls->tokens),
            ]], 200);
        }

        if (str_contains($url, '/transcript')) {
            return Http::response(['data' => ['transcript_data' => [
                ['role' => 'assistant', 'transcript' => 'Ciao! Come ti chiami?', 'time_ms' => 2000],
                ['role' => 'user', 'transcript' => 'Marco.', 'time_ms' => 1000],
            ]]], 200);
        }

        if ($request->method() === 'DELETE' && str_contains($url, '/sessions/')) {
            // .../sessions/{ref} — teardown. Excludes the two URL shapes
            // above, both already matched and returned by this point.
            preg_match('#/sessions/([^/]+)$#', $url, $m);
            $calls->teardownRefs[] = $m[1];

            return Http::response([], 200);
        }

        return Http::response([], 200);
    });

    return $calls;
}

test('a composition failure on a resume with a live ref tears the outgoing provider session down', function (): void {
    Queue::fake();
    $calls = compositionTeardownFake();

    $org = casOrg();
    [$project, $comps] = casProject($org, 1);
    ProjectQuestion::where('project_id', $project->id)
        ->where('competency_id', $comps[0]->id)
        ->update(['text' => ['en' => 'Ciao! Come ti chiami?']]);

    $participant = casParticipant($org, $project, 'in_attesa');
    $bearer = casBearer($participant);

    // First /start: issues the OUTGOING provider session, ref #1.
    $first = test()->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    app(TenantResolver::class)->setOrgId($org->id);
    app(TenantResolver::class)->setBypass(false);

    $sessionId = (int) $first->json('session_id');
    $outgoingRef = InterviewSession::findOrFail($sessionId)->provider_session_ref;
    expect($outgoingRef)->not->toBeNull();

    // The competency loses its indicators between the two calls, so the
    // resume reaches composition and fails there. NO /suspend in between:
    // the session is still `in_corso` WITH a live provider ref, which is the
    // whole point of this test.
    BarsIndicator::where('competency_id', $comps[0]->id)->delete();

    test()->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(422)
        ->assertJsonPath('error', 'composition_error');

    $session = InterviewSession::findOrFail($sessionId);
    $period = InterviewSessionLivePeriod::where('interview_session_id', $sessionId)->firstOrFail();

    expect($calls->tokens)->toHaveCount(1)
        // The outgoing session was torn down exactly once — not left running,
        // and not torn down twice by a retried request path.
        ->and($calls->teardownRefs)->toBe([$outgoingRef])
        // The row is left in the state /suspend leaves: resumable, pointing at
        // no provider session, with its live stretch closed.
        ->and($session->status)->toBe('in_corso')
        ->and($session->provider_session_ref)->toBeNull()
        ->and($period->ended_at)->not->toBeNull();
});
