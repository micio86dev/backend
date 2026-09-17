<?php

declare(strict_types=1);

/**
 * R3-harvest-teardown-ref-split (review finding).
 *
 * `start()`'s resume branch harvests the OUTGOING provider session's
 * transcript (`harvestOutgoingTranscript()`, called with the ref captured
 * BEFORE `createOrResumeSession()` re-queries the row) while the teardown
 * that ends that same outgoing session happens later, inside
 * `handleResumeInCorso()`, which reads `provider_session_ref` off a
 * SEPARATELY re-fetched `InterviewSession` instance. Two reads of "the old
 * ref" from two different places is exactly the shape that can drift if a
 * write ever lands between them.
 *
 * Traced end to end (`InterviewController::start()`,
 * `createOrResumeSession()`, `handleResumeInCorso()`,
 * `harvestOutgoingTranscript()`, `HeygenProvider::issue()`): nothing writes
 * `provider_session_ref` on this row between the harvest's capture and the
 * teardown's — `issue()` returns a brand-new `ProviderToken` without ever
 * touching the `InterviewSession` it was handed, and the only intervening
 * `save()` (the primary-questions/follow-up-budget sync) never touches this
 * column. NOT a real defect today. This test pins the invariant it relies
 * on: harvest and teardown target the SAME provider session — the one that
 * was live before the resume issued a fresh one — so a future change that
 * lets them drift fails loudly here instead of silently orphaning a billed
 * provider session or discarding a transcript.
 */

use App\Models\InterviewSession;
use App\Models\ProjectQuestion;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * A HeyGen fake that hands out a NEW `session_id` per `/sessions/token` call
 * and records, in call order, which ref every `/transcript` GET and every
 * teardown DELETE targeted.
 *
 * @return object{tokens: list<string>, transcriptRefs: list<string>, teardownRefs: list<string>}
 */
function refSplitFake(): object
{
    $calls = new class
    {
        /** @var list<string> */
        public array $tokens = [];

        /** @var list<string> */
        public array $transcriptRefs = [];

        /** @var list<string> */
        public array $teardownRefs = [];
    };

    Http::fake(function ($request) use ($calls) {
        $url = $request->url();

        if (str_contains($url, '/contexts')) {
            return Http::response(['data' => ['id' => 'ctx-'.uniqid()]], 200);
        }

        if (str_contains($url, '/sessions/token')) {
            $ref = 'heygen-refsplit-'.(count($calls->tokens) + 1);
            $calls->tokens[] = $ref;

            return Http::response(['data' => [
                'session_id' => $ref,
                'session_token' => 'tok-'.count($calls->tokens),
            ]], 200);
        }

        if (str_contains($url, '/transcript')) {
            // .../sessions/{ref}/transcript
            preg_match('#/sessions/([^/]+)/transcript#', $url, $m);
            $calls->transcriptRefs[] = $m[1];

            return Http::response(['data' => ['transcript_data' => [
                ['role' => 'assistant', 'transcript' => 'Ciao! Come ti chiami?', 'time_ms' => 1000],
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

test('a resume /start harvests and tears down the SAME outgoing provider_session_ref', function (): void {
    Queue::fake();
    $calls = refSplitFake();

    $org = casOrg();
    [$project, $comps] = casProject($org, 1);
    ProjectQuestion::where('project_id', $project->id)
        ->where('competency_id', $comps[0]->id)
        ->update(['text' => ['en' => 'Ciao! Come ti chiami?']]);

    $participant = casParticipant($org, $project, 'in_attesa');
    $bearer = casBearer($participant);

    // First /start: issues the OUTGOING session, ref #1.
    $first = test()->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    app(TenantResolver::class)->setOrgId($org->id);
    app(TenantResolver::class)->setBypass(false);

    $sessionId = (int) $first->json('session_id');
    $outgoingRef = InterviewSession::findOrFail($sessionId)->provider_session_ref;
    expect($outgoingRef)->not->toBeNull();

    // Second /start on the SAME in_corso competency: the resume branch.
    // Harvests the outgoing ref, issues a FRESH one, tears the outgoing one
    // down.
    $resumed = test()->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201)
        ->assertJsonPath('session_id', $sessionId);

    $freshRef = InterviewSession::findOrFail($sessionId)->provider_session_ref;

    expect($calls->tokens)->toHaveCount(2)
        ->and($outgoingRef)->toBe($calls->tokens[0])
        ->and($freshRef)->toBe($calls->tokens[1])
        ->and($freshRef)->not->toBe($outgoingRef)
        // Harvest fetched the OUTGOING session's transcript — never the fresh one.
        ->and($calls->transcriptRefs)->toBe([$outgoingRef])
        // Teardown tore down the SAME outgoing ref the harvest just read —
        // never the freshly issued one, and never left untorn-down.
        ->and($calls->teardownRefs)->toBe([$outgoingRef]);
});
