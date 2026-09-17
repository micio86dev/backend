<?php

declare(strict_types=1);

/**
 * RED — Task 26.2 (framework-catalogue-authoring PR7, D7): a single
 * resolution site for primary questions.
 *
 * `primaryQuestionsFor()` is called ONCE in `start()`; `$primaries[0]` feeds
 * `OpeningTextComposer` and the SAME `$primaries` array feeds
 * `SystemPromptComposer`. This is a divergence test: it fails if the two
 * composers are ever fed by two independent queries that could disagree —
 * exactly the dual-channel bug this PR closes (see the blockquote above PR 7
 * in tasks.md: the first authored question used to be spoken as the opening
 * AND listed in the prompt's must-ask section, so a candidate could be asked
 * it twice).
 *
 * Uses the shared `cas*()` fixtures (`tests/Helpers/C9Fixtures.php`,
 * autoloaded).
 *
 * REQ: interview-conversation — "Authored Primary Questions Are The
 * Complete Primary Set (No Hidden Questions)".
 */

use App\Models\ProjectQuestion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('OpeningTextComposer\'s opening question and SystemPromptComposer\'s primary 1 are the SAME array element', function (): void {
    Queue::fake();

    $capturedContextBody = [];
    Http::fake(function ($request) use (&$capturedContextBody) {
        if (str_contains($request->url(), '/contexts')) {
            $capturedContextBody = $request->data();

            return Http::response(['data' => ['id' => 'ctx-single-primaries']], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            return Http::response(['data' => [
                'session_id' => 'heygen-single-primaries',
                'session_token' => 'tok-single-primaries',
            ]], 200);
        }

        return Http::response([], 200);
    });

    $org = casOrg();
    [$project, $comps] = casProject($org, 1);

    // casProject() already seeded ONE `project_questions` row at position 0
    // — replace its text with a distinctive marker and add a second, so a
    // divergence between the two composers' resolution is observable.
    ProjectQuestion::where('project_id', $project->id)
        ->where('competency_id', $comps[0]->id)
        ->update(['text' => ['en' => 'Divergence-proof primary one.']]);

    ProjectQuestion::create([
        'project_id' => $project->id,
        'competency_id' => $comps[0]->id,
        'text' => ['en' => 'Divergence-proof primary two.'],
        'position' => 1,
    ]);

    $participant = casParticipant($org, $project, 'in_attesa');
    $bearer = casBearer($participant);

    test()->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    // The SPOKEN opening IS primary 1, verbatim — no template.
    expect($capturedContextBody['opening_text'])->toBe('Divergence-proof primary one.');

    // The composed prompt's primary-questions section lists the SAME
    // primary 1 at position "1." and primary 2 at "2." — the two composers
    // agree because they were fed the SAME array, not two independent
    // queries that could disagree.
    expect($capturedContextBody['prompt'])->toContain('1. Divergence-proof primary one.');
    expect($capturedContextBody['prompt'])->toContain('2. Divergence-proof primary two.');

    // The prompt states primary 1 was already spoken — the composer was
    // TOLD this, not left to infer it from the conversation so far.
    expect($capturedContextBody['prompt'])->toContain('Primary question 1 has ALREADY been spoken');
});

test('a RESUME never states primary 1 was already spoken, and never truncates the primary list', function (): void {
    // The opening template is unconditional for `resume` (OpeningTextComposer
    // ignores the authored question entirely on that variant, D9) — and
    // `openingSpokeFirstPrimary` must be false on that same variant, so the
    // full primary list is stated with no "already spoken" claim.
    Queue::fake();

    $capturedContextBody = [];
    Http::fake(function ($request) use (&$capturedContextBody) {
        if (str_contains($request->url(), '/contexts')) {
            $capturedContextBody = $request->data();

            return Http::response(['data' => ['id' => 'ctx-resume-primaries']], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            return Http::response(['data' => [
                'session_id' => 'heygen-resume-primaries',
                'session_token' => 'tok-resume-primaries',
            ]], 200);
        }
        if (str_contains($request->url(), '/transcript')) {
            return Http::response([
                'data' => ['transcript_data' => [
                    ['role' => 'assistant', 'transcript' => 'Resume-proof primary one.', 'time_ms' => 2000],
                ]],
            ], 200);
        }
        if (str_contains($request->url(), '/sessions/')) {
            return Http::response([], 200); // teardown
        }

        return Http::response([], 200);
    });

    $org = casOrg();
    [$project, $comps] = casProject($org, 1);

    ProjectQuestion::where('project_id', $project->id)
        ->where('competency_id', $comps[0]->id)
        ->update(['text' => ['en' => 'Resume-proof primary one.']]);

    $participant = casParticipant($org, $project, 'in_attesa');
    $bearer = casBearer($participant);

    // First /start — issues the session and moves it to in_corso.
    test()->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    // Second /start on the SAME still-in_corso session — a resume.
    test()->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    expect($capturedContextBody['prompt'])->not->toContain('ALREADY been spoken');
    expect($capturedContextBody['prompt'])->toContain('1. Resume-proof primary one.');
});
