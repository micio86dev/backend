<?php

declare(strict_types=1);

/**
 * Golden pin: the `prompt` the provider receives on POST /candidate/interview/start.
 *
 * Where `SystemPromptGoldenTest` pins `compose()` in isolation, this pins what
 * the whole request path actually sends: the controller's choice of opening,
 * budget, nudge and, above all, which advance phrase the model is told to say.
 * Characterization of the pre-change tree, captured once through
 * `Tests\Support\PromptGolden` (no overwrite, no update mode):
 *
 *  - H1 standard `en`, fresh start, first of two competencies (intermediate phrase)
 *  - H2 standard `it`, resumed live session, first of two competencies
 *  - H3 potential `it`, the last (here only) competency: the FINAL phrase, never
 *    the intermediate one
 *  - H4 standard `en`, the SECOND of two competencies, the first already completed:
 *    the OPENING paragraph carries the no-greeting continuation clause (H1, H2 and
 *    H3 must not)
 *
 * Determinism: the catalogue codes, indicator and question texts are fixed and
 * the budget, minimum and nudge are set explicitly. The only environment-
 * dependent text is the real `interview.end_phrase` / `final_phrase` from the
 * lang files, which is deliberate: that is the sentence the client matches
 * against, so a lang edit MUST show up as a golden diff. Nothing else in the
 * prompt varies between runs, so no normalization is applied.
 *
 * Both prompt sources are pinned to the SAME fixtures: the tests above run on the
 * default source (`db`: the bootstrap migration's baseline set, resolved and
 * sealed), and the last test runs H1 to H4 again on the `baseline` break-glass. The
 * stored baseline set therefore reproduces the code baseline byte for byte.
 *
 * Capture (once, on the pre-change tree):
 *   PROMPT_GOLDEN_CAPTURE=1 PROMPT_GOLDEN_SOURCE_COMMIT=<commit> vendor/bin/pest tests/Feature/C8/InterviewStartPromptGoldenTest.php
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PromptGolden;

/**
 * Seed a fixed project, call /start, and return the provider `prompt`.
 *
 * @param  list<string>  $codes  Competency codes in interview order.
 * @param  int  $completed  How many leading competencies the candidate already finished.
 */
function goldenStartPrompt(string $type, string $locale, array $codes, int $primaries, bool $resume, int $completed = 0): string
{
    config(['conversation.min_questions' => 4, 'conversation.followup_budget' => 4]);
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    $standard = $type === 'standard';

    $project = casInTenant($org, fn (): Project => Project::factory()->create([
        'status' => 'active',
        'assessment_type' => $type,
        'role_code' => $standard ? 'GOLD_ROLE' : null,
        'language' => $locale,
        'nudge_min_chars' => 120,
    ]));
    $role = $standard ? Role::factory()->create(['code' => 'GOLD_ROLE']) : null;

    foreach ($codes as $position => $code) {
        $competency = Competency::factory()->create(['code' => $code]);
        DB::table('project_competencies')->insert([
            'project_id' => $project->id, 'competency_id' => $competency->id, 'position' => $position,
        ]);

        foreach ([0, 1] as $i) {
            $indicator = new BarsIndicator;
            $indicator->forceFill([
                'role_id' => $role?->id,
                'competency_id' => $competency->id,
                'text' => ['en' => "{$code} indicator {$i}", 'it' => "{$code} indicatore {$i}"],
                'anchor_5' => ['en' => "{$code} five {$i}", 'it' => "{$code} cinque {$i}"],
                'anchor_3' => ['en' => "{$code} three {$i}", 'it' => "{$code} tre {$i}"],
                'anchor_1' => ['en' => "{$code} one {$i}", 'it' => "{$code} uno {$i}"],
                'position' => $i,
            ]);
            $indicator->save();
        }

        for ($q = 0; $q < $primaries; $q++) {
            ProjectQuestion::create([
                'project_id' => $project->id,
                'competency_id' => $competency->id,
                'text' => ['en' => "{$code} question ".($q + 1).'?', 'it' => "{$code} domanda ".($q + 1).'?'],
                'position' => $q,
            ]);
        }
    }

    $participant = casParticipant($org, $project, $resume ? 'in_corso' : 'in_attesa');

    foreach (array_slice($codes, 0, $completed) as $position => $code) {
        InterviewSession::create([
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => $position,
            'competency_code' => $code,
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'heygen',
            'provider_session_ref' => 'golden-done-ref-'.$position,
            'status' => 'completed',
        ]);
    }

    if ($resume) {
        InterviewSession::create([
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => $completed,
            'competency_code' => $codes[$completed],
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'heygen',
            'provider_session_ref' => 'golden-old-ref',
            'status' => 'in_corso',
        ]);
    }

    test()->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    // Only the context CREATION carries the prompt; a context DELETE (the release
    // of an old session on resume) targets /contexts/{id} and must never be taken for it.
    $creations = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/contexts'))
        ->values();

    expect($creations)->toHaveCount(1, 'The /start call must create exactly one provider context.');

    return (string) ($creations->first()->data()['prompt'] ?? '');
}

function goldenAssertHttp(string $id, string $prompt): void
{
    if (PromptGolden::capturing()) {
        PromptGolden::fixtures()->capture($id, $prompt);
    }

    expect($prompt)->not->toBe('')
        ->and(PromptGolden::fixtures()->matches($id, $prompt))->toBeTrue("{$id} drifted from its fixture");
}

test('H1 standard en fresh start sends the pinned prompt', function (): void {
    $prompt = goldenStartPrompt('standard', 'en', ['GOLD_A', 'GOLD_B'], 2, false);

    // The advance phrase is embedded between double quotes: match the quoted form so a
    // phrase that merely contains the other one cannot give a false result.
    expect($prompt)->toContain('"'.trans('interview.end_phrase', [], 'en').'"')
        ->not->toContain('"'.trans('interview.final_phrase', [], 'en').'"');
    goldenAssertHttp('H1', $prompt);
});

test('H2 standard it resume sends the pinned prompt', function (): void {
    $prompt = goldenStartPrompt('standard', 'it', ['GOLD_A', 'GOLD_B'], 2, true);

    expect($prompt)->toContain('re-asked');
    goldenAssertHttp('H2', $prompt);
});

test('H3 potential it on the last competency sends the final phrase, not the intermediate one', function (): void {
    $prompt = goldenStartPrompt('potential', 'it', ['GOLD_MTG'], 1, false);

    $final = trans('interview.final_phrase', [], 'it');
    $intermediate = trans('interview.end_phrase', [], 'it');

    expect($final)->not->toBe($intermediate)
        ->and($prompt)->toContain('"'.$final.'"')
        ->not->toContain('"'.$intermediate.'"');
    goldenAssertHttp('H3', $prompt);
});

test('H4 standard en second competency sends the pinned prompt with the no-greeting clause', function (): void {
    $prompt = goldenStartPrompt('standard', 'en', ['GOLD_A', 'GOLD_B'], 2, false, completed: 1);

    expect($prompt)->toContain('GOLD_B')
        ->and($prompt)->toContain('primary question 1, word for word: "GOLD_B question 1?". Do NOT ask it again.')
        ->and($prompt)->toContain('Do NOT greet, welcome or introduce yourself again.');
    goldenAssertHttp('H4', $prompt);
});

test('the first competency never carries the no-greeting clause', function (): void {
    expect(goldenStartPrompt('standard', 'en', ['GOLD_A', 'GOLD_B'], 2, false))->not->toContain('Do NOT greet');
});

test('a resume of the second competency never carries the no-greeting clause', function (): void {
    expect(goldenStartPrompt('standard', 'en', ['GOLD_A', 'GOLD_B'], 2, true, completed: 1))->not->toContain('Do NOT greet');
});

test('the default prompt source is the database, so H1 to H4 above pin the stored baseline set', function (): void {
    expect(config('conversation.prompt_source'))->toBe('db');
});

test('H1 to H4 are byte-identical on the baseline break-glass source too', function (string $id, array $args): void {
    config(['conversation.prompt_source' => 'baseline']);

    goldenAssertHttp($id, goldenStartPrompt(...$args));
})->with([
    'H1' => ['H1', ['standard', 'en', ['GOLD_A', 'GOLD_B'], 2, false]],
    'H2' => ['H2', ['standard', 'it', ['GOLD_A', 'GOLD_B'], 2, true]],
    'H3' => ['H3', ['potential', 'it', ['GOLD_MTG'], 1, false]],
    'H4' => ['H4', ['standard', 'en', ['GOLD_A', 'GOLD_B'], 2, false, 1]],
]);
