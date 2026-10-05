<?php

declare(strict_types=1);

/**
 * Characterization pin: the composed `standard` prompt is byte-identical.
 *
 * Change `potential-assessment-interview` widens `SystemPromptComposer::compose()`
 * to a nullable role and branches the controller on the assessment type. Neither
 * may change a single byte of what a `standard` interview is told. Existing C8
 * tests assert substrings, not bytes, so this pins the exact output of fixed
 * inputs as a sha256 plus a length, captured from the code BEFORE that change.
 *
 * A mismatch means the composed text moved. If the move is deliberate (template
 * or lang edit), update the constants in the same commit as the `prompt_version`
 * bump; otherwise it is a regression. To see the drift, dump `$prompt->text`
 * locally and diff it against the previous commit.
 */

use App\DTOs\Conversation\SpokenOpening;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Role;
use App\Services\Conversation\BarsIndicatorLoader;
use App\Services\Conversation\SystemPromptComposer;

/**
 * Compose the fixed `standard` prompt for one locale.
 */
function characterizeStandardPrompt(string $locale): string
{
    config([
        'conversation.min_questions' => 4,
        'conversation.prompt_version' => 'characterization-v1',
    ]);

    $role = Role::factory()->create(['code' => 'CHAR_ROLE']);
    $competency = Competency::factory()->create(['code' => 'CHAR_COMP']);

    foreach ([0, 1, 2] as $position) {
        $indicator = new BarsIndicator;
        $indicator->forceFill([
            'role_id' => $role->id,
            'competency_id' => $competency->id,
            'text' => ['en' => "Fixed indicator {$position}", 'it' => "Indicatore fisso {$position}"],
            'anchor_5' => ['en' => "Fixed anchor five {$position}", 'it' => "Ancora fissa cinque {$position}"],
            'anchor_3' => ['en' => "Fixed anchor three {$position}", 'it' => "Ancora fissa tre {$position}"],
            'anchor_1' => ['en' => "Fixed anchor one {$position}", 'it' => "Ancora fissa uno {$position}"],
            'position' => $position,
        ]);
        $indicator->save();
    }

    $prompt = (new SystemPromptComposer(new BarsIndicatorLoader))->compose(
        competencyCode: 'CHAR_COMP',
        roleId: $role->id,
        competencyId: $competency->id,
        projectLocale: $locale,
        followUpBudget: 4,
        nudgeMinChars: 120,
        advancePhrase: 'That is all for this topic.',
        minQuestions: 4,
        primaryQuestions: ['First fixed primary question?', 'Second fixed primary question?'],
        spokenOpening: SpokenOpening::primary(1),
    );

    return $prompt->text;
}

test('standard composed prompt is byte-identical for en', function (): void {
    $text = characterizeStandardPrompt('en');

    expect(hash('sha256', $text))->toBe('1b1aa512d023c73256212bb67528f6bcbed5cd5d0be44e250c6278f8dd2a62ac');
    expect(strlen($text))->toBe(4320);
});

test('standard composed prompt is byte-identical for it', function (): void {
    $text = characterizeStandardPrompt('it');

    expect(hash('sha256', $text))->toBe('ef778652792cd0a45e17769c299a0938a1274c9e7a842c2ea95b1bbb38d93e11');
    expect(strlen($text))->toBe(4323);
});
