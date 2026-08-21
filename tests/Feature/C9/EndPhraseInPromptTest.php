<?php

declare(strict_types=1);

/**
 * The avatar must be TOLD the phrase it is supposed to speak.
 *
 * The composed system prompt instructed it to "Speak end_phrase ONLY when all
 * coverage topics have been addressed" — but `end_phrase` was a literal
 * placeholder. The actual sentence ("Passiamo alla prossima domanda.") never
 * entered the prompt, and `compose()` did not even receive it.
 *
 * Completion detection is `matchesEndPhrase()` against the avatar's transcript,
 * so an avatar that never says the sentence never ends its question. Every
 * competency then ran to the 5-minute cap, and on HeyGen the session hit
 * MAX_DURATION_REACHED first and died — which surfaced to the candidate as
 * "an error occurred" at the end of a question they had answered fully.
 */

use App\Models\InterviewSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/** The `prompt` field of the ONE /contexts call, or null. */
function promptBody(): ?string
{
    foreach (Http::recorded() as [$request, $response]) {
        if (str_contains($request->url(), '/contexts')) {
            return $request->data()['prompt'] ?? null;
        }
    }

    return null;
}

test('the composed prompt contains the LITERAL end phrase, not the token', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 2);
    casInTenant($org, fn () => $project->forceFill(['language' => 'it'])->save());
    $participant = casParticipant($org, $project);

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    $prompt = promptBody();
    expect($prompt)->not->toBeNull('the context call must carry a prompt');

    $endPhrase = trans('interview.end_phrase', [], 'it');
    // NOTE: toContain() takes multiple NEEDLES, not a failure message. Passing an
    // explanation as a second argument silently asserts the prompt contains that
    // sentence too — which is how this test first failed against correct code.
    expect($prompt)->toContain($endPhrase);
});

test('the LAST competency is given the final phrase instead', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project, $comps] = casProject($org, 1);
    casInTenant($org, fn () => $project->forceFill(['language' => 'it'])->save());
    $participant = casParticipant($org, $project);

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    $prompt = promptBody();
    expect($prompt)->toContain(trans('interview.final_phrase', [], 'it'));
    expect($comps)->toHaveCount(1);
});

test('the phrase in the prompt is the one the client matches against', function (): void {
    // The two must be the same string or completion silently never fires: the
    // prompt tells the avatar one sentence and the client watches for another.
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 2);
    casInTenant($org, fn () => $project->forceFill(['language' => 'en'])->save());
    $participant = casParticipant($org, $project);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    $advertised = $response->json('question_context.end_phrase');
    expect(promptBody())->toContain($advertised);

    expect(InterviewSession::query()->count())->toBe(1);
});
