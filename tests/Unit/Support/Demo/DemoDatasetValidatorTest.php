<?php

declare(strict_types=1);

/**
 * DemoDatasetValidator — checks the hand-authored fixture against the LIVE
 * framework catalog before `beai:demo-seed` writes anything (design D4/D5).
 *
 * This is the guard that makes the legacy `DemoSeeder` defects structurally
 * impossible rather than merely avoided by discipline:
 *   - Defect F (`array_slice($scores, 0, $indicators->count())`, silently
 *     truncating a mismatched vector) — arity must match EXACTLY.
 *   - Defect E (a `potential` project with a non-null `role_code`) —
 *     asserted even though every demo project is `standard` (D10).
 *   - Excerpts are only ever a slice of an authored answer's real sentences —
 *     asserted here so the writer can never slice past the string it quotes.
 */

use App\Support\Demo\DemoDataset;
use App\Support\Demo\DemoDatasetValidator;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

test('the authored dataset validates cleanly against the real catalog', function (): void {
    expect(DemoDatasetValidator::validate())->toBe([]);
});

test('assertValid() does not throw for the real dataset', function (): void {
    DemoDatasetValidator::assertValid();
})->throwsNoExceptions();

test('the real dataset validates cleanly, and the arity guard actually FIRES on a deliberately corrupted vector', function (): void {
    // Real dataset first, as a control: proves the fabricated case below is
    // being rejected BECAUSE it is wrong, not because the guard rejects
    // everything.
    expect(DemoDatasetValidator::validate())->toBe([]);

    $reflection = new ReflectionClass(DemoDatasetValidator::class);
    $method = $reflection->getMethod('validateScoreArity');
    $method->setAccessible(true);

    // ICO/PRS has exactly 3 catalog indicators (asserted independently by
    // the pre-existing SeededCountCorrectnessTest). 2 is short — legacy
    // Defect F (`array_slice($scores, 0, $indicators->count())`) would have
    // silently accepted this by trimming the catalog side instead of
    // rejecting the fixture; this guard must not repeat that.
    $corruptedParticipants = [
        ['key' => 'fabricated-short', 'project_key' => 'P1', 'scores' => ['PRS' => [5, 3]]],
    ];
    $realProjects = DemoDataset::projects();

    $errors = $method->invoke(null, $corruptedParticipants, $realProjects);

    expect($errors)->not->toBe([])
        ->and($errors[0])->toContain('PRS')
        ->and($errors[0])->toContain('EXACT arity required');

    // A too-LONG vector must be rejected too — not just a short one.
    $corruptedParticipants[0]['scores']['PRS'] = [5, 3, 5, 3];
    $errors = $method->invoke(null, $corruptedParticipants, $realProjects);

    expect($errors)->not->toBe([])
        ->and($errors[0])->toContain('EXACT arity required');
});

test('every excerpt_sentence index resolves to a real sentence in its answer', function (): void {
    foreach (DemoDataset::competencyContent() as $language => $competencies) {
        foreach ($competencies as $competencyCode => $answers) {
            foreach ($answers as $answer) {
                $sentences = DemoDatasetValidator::splitSentences($answer['text']);

                expect($sentences)->toHaveKey($answer['excerpt_sentence']);
            }
        }
    }
});

test('every real demo project is standard, and the Defect-E guard actually FIRES on a fabricated potential project with a non-null role_code', function (): void {
    // Control: every REAL project is `standard` under D10, so the guard's
    // `potential` branch never fires against the real fixture — which is
    // exactly why it must be proven against fabricated data below, not
    // merely by iterating the real (always-standard) list.
    foreach (DemoDataset::projects() as $project) {
        expect($project['assessment_type'])->toBe('standard');
    }

    $reflection = new ReflectionClass(DemoDatasetValidator::class);
    $method = $reflection->getMethod('validatePotentialRoleCodeGuard');
    $method->setAccessible(true);

    // Legacy Defect E: a `potential` project shipped with role_code='FLL' —
    // `potential` requires role_code=null (SsoExchangeController.php:215-219).
    $corruptedProjects = [
        ['key' => 'fabricated-potential', 'assessment_type' => 'potential', 'role_code' => 'FLL'],
    ];

    $errors = $method->invoke(null, $corruptedProjects);

    expect($errors)->not->toBe([])
        ->and($errors[0])->toContain('fabricated-potential')
        ->and($errors[0])->toContain('potential requires role_code=null');

    // A `potential` project with role_code=null (the legal shape) must NOT
    // be flagged — the guard rejects the violation, not the assessment type.
    $legalProjects = [
        ['key' => 'fabricated-potential-legal', 'assessment_type' => 'potential', 'role_code' => null],
    ];

    expect($method->invoke(null, $legalProjects))->toBe([]);
});

test('expectedCensus derives every count from the fixture, matching 4 projects and 9 participants', function (): void {
    $census = DemoDatasetValidator::expectedCensus();

    expect($census['projects'])->toBe(4)
        ->and($census['participants'])->toBe(9)
        ->and($census['framework_versions'])->toBe(1)
        ->and($census['avatar_templates'])->toBe(2)
        ->and($census['avatar_templates_active'])->toBe(1)
        ->and($census['interview_sessions'])->toBeGreaterThan(0)
        ->and($census['evaluations'])->toBe(5)
        ->and($census['snapshots'])->toBeGreaterThan(0);
});
