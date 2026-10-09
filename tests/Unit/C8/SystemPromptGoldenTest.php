<?php

declare(strict_types=1);

/**
 * Golden pin: the composed system prompt, byte for byte (cases G01 to G19).
 *
 * These are CHARACTERIZATION tests, captured from the composer BEFORE its
 * template text moves into the database. They are green the moment they are
 * captured: they describe what the code does, not what it should do. A later
 * diff under `tests/Fixtures/Conversation/prompts/` is byte drift of what the
 * avatar is told, to be rejected in review. Capture never overwrites and there
 * is no update mode (see `Tests\Support\PromptGolden`).
 *
 * `compose()` is called directly with fixed inputs, so nothing here depends on
 * the clock, randomness, the request path or the lang files. The phrases are
 * literals on purpose; the real `interview.*_phrase` strings are pinned at the
 * HTTP level by `InterviewStartPromptGoldenTest`.
 *
 * Coverage (each case exercises a distinct combination, the fragment keys that
 * a later change will extract do not exist yet, so there is no key recorder):
 *  - G01 no primaries, no nudge, no phrase      G02 nudge 0, blank phrase, minimum 1
 *  - G03 phrase, minimum clamped down to 2      G04 budget 0, clamped up to 1
 *  - G05 two primaries                          G06 six primaries
 *  - G07 `it`, the StandardPromptCharacterizationTest inputs (must equal its pin)
 *  - G08-G11 resumed(1,3) (2,4) (3,3) (0,2)     G12 fallback opening, resumed
 *  - G13 `it`, role-less (potential), final phrase
 *  - G14 operator text holding `:budget`, `{{budget}}`, quotes, multibyte, padding
 *  - G15 minimum 99 clamped                     G16 minimum -3 clamped
 *  - G17 one primary, resumed after it was asked
 *  - G18 `en`, a later competency (continuation: the no-greeting clause)
 *  - G19 `it`, the same, with the project locale `it` (the clause stays English)
 *
 * Capture (once, on the pre-change tree):
 *   PROMPT_GOLDEN_CAPTURE=1 PROMPT_GOLDEN_SOURCE_COMMIT=<commit> vendor/bin/pest tests/Unit/C8/SystemPromptGoldenTest.php
 */

use App\DTOs\Conversation\SpokenOpening;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Role;
use App\Services\Conversation\BarsIndicatorLoader;
use App\Services\Conversation\SystemPromptComposer;
use Tests\Support\PromptGolden;

const GOLD_PHRASE = 'Let us move on to the next question.';
const GOLD_FINAL_IT = 'Grazie, il colloquio è terminato.';

/**
 * @return array<string, array<string, mixed>> Overrides over the defaults in goldenCompose().
 */
function goldenCases(): array
{
    $questions = static fn (int $n): array => array_map(static fn (int $i): string => "Primary question {$i}?", range(1, $n));

    return [
        'G01' => [],
        'G02' => ['nudge' => 0, 'phrase' => '   ', 'min' => 1, 'primaries' => $questions(1)],
        'G03' => ['budget' => 2, 'nudge' => 100, 'phrase' => GOLD_PHRASE, 'min' => 4],
        'G04' => ['budget' => 0, 'nudge' => 50, 'phrase' => GOLD_PHRASE],
        'G05' => ['nudge' => 120, 'phrase' => GOLD_PHRASE, 'primaries' => $questions(2)],
        'G06' => ['budget' => 2, 'nudge' => 120, 'phrase' => GOLD_PHRASE, 'primaries' => $questions(6)],
        'G07' => [
            'locale' => 'it', 'nudge' => 120, 'phrase' => 'That is all for this topic.', 'min' => 4,
            'primaries' => ['First fixed primary question?', 'Second fixed primary question?'],
            'opening' => SpokenOpening::primary(1),
        ],
        'G08' => ['nudge' => 120, 'phrase' => GOLD_PHRASE, 'primaries' => $questions(3), 'opening' => SpokenOpening::resumed(1, 3)],
        'G09' => ['nudge' => 120, 'phrase' => GOLD_PHRASE, 'primaries' => $questions(4), 'opening' => SpokenOpening::resumed(2, 4)],
        'G10' => ['nudge' => 120, 'phrase' => GOLD_PHRASE, 'primaries' => $questions(3), 'opening' => SpokenOpening::resumed(3, 3)],
        'G11' => ['nudge' => 120, 'phrase' => GOLD_PHRASE, 'primaries' => $questions(2), 'opening' => SpokenOpening::resumed(0, 2)],
        'G12' => ['phrase' => GOLD_PHRASE, 'opening' => SpokenOpening::fallback(true)],
        'G13' => [
            'locale' => 'it', 'roleless' => true, 'nudge' => 120, 'phrase' => GOLD_FINAL_IT,
            'primaries' => ['Domanda potenziale?'],
        ],
        'G14' => [
            'nudge' => 120,
            'phrase' => '  Re:think "this": {{advance_phrase}} :budget — è l\'ultima  ',
            'primaries' => [
                '  Why :budget and {{budget}} matter?  ',
                '',
                'Re:think "quotes" — dash, caffè, 日本語, 🙂?',
            ],
        ],
        'G15' => ['min' => 99, 'phrase' => GOLD_PHRASE, 'primaries' => $questions(2)],
        'G16' => ['min' => -3, 'phrase' => GOLD_PHRASE, 'primaries' => $questions(2)],
        'G17' => ['phrase' => GOLD_PHRASE, 'primaries' => $questions(1), 'opening' => SpokenOpening::resumed(1, 1)],
        'G18' => ['nudge' => 120, 'phrase' => GOLD_PHRASE, 'primaries' => $questions(2), 'opening' => SpokenOpening::primary(1, continuation: true)],
        'G19' => [
            'locale' => 'it', 'nudge' => 120, 'phrase' => GOLD_PHRASE, 'primaries' => $questions(2),
            'opening' => SpokenOpening::primary(1, continuation: true),
        ],
    ];
}

/**
 * Compose one case against fixed catalogue rows: competency CHAR_COMP (with a
 * role) or CHAR_POT (role-less), three indicators in `en` and `it`.
 *
 * @param  array<string, mixed>  $case
 */
function goldenCompose(array $case): string
{
    config(['conversation.min_questions' => 4, 'conversation.prompt_version' => 'characterization-v1']);

    $case += [
        'locale' => 'en', 'roleless' => false, 'budget' => 4, 'nudge' => null,
        'phrase' => null, 'min' => null, 'primaries' => [], 'opening' => null,
    ];

    $role = $case['roleless'] ? null : Role::factory()->create(['code' => 'CHAR_ROLE']);
    $competency = Competency::factory()->create(['code' => $case['roleless'] ? 'CHAR_POT' : 'CHAR_COMP']);

    foreach ([0, 1, 2] as $position) {
        $indicator = new BarsIndicator;
        $indicator->forceFill([
            'role_id' => $role?->id,
            'competency_id' => $competency->id,
            'text' => ['en' => "Fixed indicator {$position}", 'it' => "Indicatore fisso {$position}"],
            'anchor_5' => ['en' => "Fixed anchor five {$position}", 'it' => "Ancora fissa cinque {$position}"],
            'anchor_3' => ['en' => "Fixed anchor three {$position}", 'it' => "Ancora fissa tre {$position}"],
            'anchor_1' => ['en' => "Fixed anchor one {$position}", 'it' => "Ancora fissa uno {$position}"],
            'position' => $position,
        ]);
        $indicator->save();
    }

    return (new SystemPromptComposer(new BarsIndicatorLoader))->compose(
        competencyCode: $competency->code,
        roleId: $role?->id,
        competencyId: $competency->id,
        projectLocale: $case['locale'],
        followUpBudget: $case['budget'],
        nudgeMinChars: $case['nudge'],
        advancePhrase: $case['phrase'],
        minQuestions: $case['min'],
        primaryQuestions: $case['primaries'],
        spokenOpening: $case['opening'],
    )->text;
}

/**
 * Run a callback against an empty throwaway fixtures directory.
 */
function withGoldenSandbox(Closure $callback): void
{
    $dir = sys_get_temp_dir().'/prompt-golden-'.bin2hex(random_bytes(6));

    try {
        $callback(new PromptGolden($dir), $dir);
    } finally {
        array_map(unlink(...), glob($dir.'/*') ?: []);
        @rmdir($dir);
    }
}

const GOLD_SOURCE = 'e215c43fc4456a6be52c9c3fc2720ef4bba4dd08';

// ─── The golden cases ─────────────────────────────────────────────────────────

test('the composed prompt is byte-identical to its golden fixture', function (string $id): void {
    $text = goldenCompose(goldenCases()[$id]);

    if (PromptGolden::capturing()) {
        PromptGolden::fixtures()->capture($id, $text);
    }

    expect(PromptGolden::fixtures()->matches($id, $text))->toBeTrue("{$id} drifted from its fixture");
})->with(fn (): array => array_keys(goldenCases()));

test('G07 is the StandardPromptCharacterizationTest input and keeps its 4323-byte pin', function (): void {
    $text = goldenCompose(goldenCases()['G07']);

    expect(strlen($text))->toBe(4323)
        ->and(hash('sha256', $text))->toBe('ef778652792cd0a45e17769c299a0938a1274c9e7a842c2ea95b1bbb38d93e11');
});

test('the fixtures directory matches the pinned hash and its manifest', function (): void {
    expect(PromptGolden::fixtures()->manifestIsConsistent())->toBeTrue()
        ->and(PromptGolden::fixtures()->directoryHash())->toBe(PromptGolden::PINNED_DIRECTORY_SHA256);
});

// ─── The harness itself ───────────────────────────────────────────────────────

test('harness: mutating one byte of the output fails the comparison', function (): void {
    withGoldenSandbox(function (PromptGolden $golden): void {
        $golden->capture('X1', "line one\nline two", GOLD_SOURCE);

        expect($golden->matches('X1', "line one\nline two"))->toBeTrue()
            ->and($golden->matches('X1', "line one\nline twO"))->toBeFalse()
            ->and($golden->matches('X1', "line one\nline two\n"))->toBeFalse();
    });
});

test('harness: capture refuses to overwrite an existing fixture', function (): void {
    withGoldenSandbox(function (PromptGolden $golden): void {
        $golden->capture('X1', 'first', GOLD_SOURCE);

        expect(fn () => $golden->capture('X1', 'second', GOLD_SOURCE))->toThrow(RuntimeException::class, 'never overwrites')
            ->and($golden->expected('X1'))->toBe('first');
    });
});

test('harness: capture needs a source commit, and a missing fixture fails loudly', function (): void {
    withGoldenSandbox(function (PromptGolden $golden): void {
        expect(fn () => $golden->capture('X1', 'bytes', 'not-a-commit'))->toThrow(RuntimeException::class, 'PROMPT_GOLDEN_SOURCE_COMMIT')
            ->and(fn () => $golden->expected('X1'))->toThrow(RuntimeException::class, 'missing');
    });
});

test('harness: the directory hash rejects an added, removed or changed file and ignores creation order', function (): void {
    withGoldenSandbox(function (PromptGolden $golden, string $dir): void {
        $golden->capture('A', 'alpha', GOLD_SOURCE);
        $golden->capture('B', 'beta', GOLD_SOURCE);
        $base = $golden->directoryHash();

        file_put_contents($dir.'/C.txt', 'gamma');
        $added = $golden->directoryHash();
        unlink($dir.'/C.txt');

        file_put_contents($dir.'/A.txt', 'alphA');
        $changed = $golden->directoryHash();
        file_put_contents($dir.'/A.txt', 'alpha');

        $manifest = $dir.'/manifest.json';
        $kept = (string) file_get_contents($manifest);
        unlink($manifest);
        $removed = $golden->directoryHash();
        file_put_contents($manifest, $kept);

        expect([$added, $changed, $removed])->each->not->toBe($base)
            ->and($golden->directoryHash())->toBe($base);

        withGoldenSandbox(function (PromptGolden $reordered) use ($base): void {
            $reordered->capture('B', 'beta', GOLD_SOURCE);
            $reordered->capture('A', 'alpha', GOLD_SOURCE);

            expect($reordered->directoryHash())->toBe($base);
        });
    });
});
