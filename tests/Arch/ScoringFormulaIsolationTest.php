<?php

declare(strict_types=1);

/**
 * Architecture guard: the scoring FORMULAS cannot see the reason (C13,
 * scoring-failure-containment D9, scoring-model's "Validation-Failure Reason
 * Is Excluded From Every Scoring Formula" requirement).
 *
 * `MeanCalculator`, `AssessableFractionReliability`, and `CompletionGate`
 * take pure `list<int>` / `int` — never a DTO or a model — so
 * `IndicatorScoreDTO` (and, transitively, the reason types) are banned from
 * all three outright. `IndicatorValidator` legitimately depends on
 * `IndicatorScoreDTO` (its established job is checking `$dto->score`'s
 * domain membership, unchanged by this feature) but MUST NOT branch on, or
 * even import, `IndicatorFailureReason`/`UnscorableReason` — its job stays
 * "is this score legal", never "why is this indicator unassessable".
 *
 * This is the STRONGEST of D9's three mechanisms: a diff check (or a
 * docblock) is a policy, not a property — it says nothing once someone
 * legitimately touches the file for an unrelated reason. This test states
 * the actual invariant: the reason is STRUCTURALLY UNREACHABLE from the
 * arithmetic, and it fails on the import, before anyone can write the branch.
 *
 * Mirrors the glob+file_get_contents+str_contains shape of
 * tests/Arch/C11/AdminTenancySafetyArchTest.php /
 * tests/Arch/Tenancy/QueuedJobTenantContextArchTest.php — this codebase's
 * established arch-test convention (no pest-plugin-arch dependency).
 */
test('the three formula classes never depend on a DTO or a reason type — list<int>/int only', function (): void {
    $bannedNeedles = ['IndicatorFailureReason', 'UnscorableReason', 'IndicatorScoreDTO'];

    $guardedFiles = [
        app_path('Services/Scoring/MeanCalculator.php'),
        app_path('Services/Scoring/AssessableFractionReliability.php'),
        app_path('Services/Scoring/CompletionGate.php'),
    ];

    $violations = [];

    foreach ($guardedFiles as $file) {
        expect($file)->toBeFile("Guarded file must exist: {$file}");

        $source = (string) file_get_contents($file);

        foreach ($bannedNeedles as $needle) {
            if (str_contains($source, $needle)) {
                $violations[] = "{$file} references {$needle}";
            }
        }
    }

    expect($violations)->toBe([], sprintf(
        "MeanCalculator/AssessableFractionReliability/CompletionGate take list<int>/int ONLY — never a DTO, never the reason (D9):\n  - %s",
        implode("\n  - ", $violations)
    ));
})->group('arch');

test('IndicatorValidator never imports the reason types — its job stays "is this score legal", not "why"', function (): void {
    $bannedNeedles = ['IndicatorFailureReason', 'UnscorableReason'];

    $file = app_path('Services/Scoring/IndicatorValidator.php');
    expect($file)->toBeFile("Guarded file must exist: {$file}");

    $source = (string) file_get_contents($file);

    $violations = array_values(array_filter(
        $bannedNeedles,
        static fn (string $needle): bool => str_contains($source, $needle)
    ));

    expect($violations)->toBe([], sprintf(
        'IndicatorValidator MUST NOT depend on the reason types — legitimately depends on IndicatorScoreDTO (checks $dto->score domain membership only). Found: %s',
        implode(', ', $violations)
    ));
})->group('arch');
