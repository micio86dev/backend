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
 *
 * Determinism: the catalogue codes, indicator and question texts are fixed and
 * the budget, minimum and nudge are set explicitly. The only environment-
 * dependent text is the real `interview.end_phrase` / `final_phrase` from the
 * lang files, which is deliberate: that is the sentence the client matches
 * against, so a lang edit MUST show up as a golden diff. Nothing else in the
 * prompt varies between runs, so no normalization is applied.
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
 */
function goldenStartPrompt(string $type, string $locale, array $codes, int $primaries, bool $resume): string
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

    if ($resume) {
        InterviewSession::create([
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => 0,
            'competency_code' => $codes[0],
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'heygen',
            'provider_session_ref' => 'golden-old-ref',
            'status' => 'in_corso',
        ]);
    }

    test()->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    foreach (Http::recorded() as [$request]) {
        if (str_contains($request->url(), '/contexts')) {
            return (string) ($request->data()['prompt'] ?? '');
        }
    }

    throw new RuntimeException('The /start call sent no provider context.');
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

    expect($prompt)->toContain(trans('interview.end_phrase', [], 'en'))
        ->not->toContain(trans('interview.final_phrase', [], 'en'));
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
        ->not->toContain($intermediate);
    goldenAssertHttp('H3', $prompt);
});
