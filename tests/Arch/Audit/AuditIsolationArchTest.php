<?php

declare(strict_types=1);

/**
 * Architecture guard: the SCORING pipeline never imports an audit type
 * (scoring-audit-jev design D11, AD-7). Extends `ScoringFormulaIsolationTest.php`'s
 * existing ban one class of metadata further — that file already forbids
 * the formula classes from seeing `unassessable_reason`'s vocabulary; this
 * one forbids the whole scoring pipeline from seeing the audit's existence
 * at all. `No Audit Result May Influence Any Scoring Value` (spec.md)
 * requires exactly this: an architecture test forbidding any scoring
 * formula class from importing an audit model.
 *
 * GUARD task (P2.14): nothing in the guarded files references an audit type
 * yet — see `AuditAppendOnlyArchTest.php`'s docblock for why this is written
 * once and re-checked as a pass/fail gate on every later slice.
 */
test('no scoring pipeline class imports an audit type', function (): void {
    $bannedNeedles = [
        'IndicatorScoreAudit',
        'IndicatorScoreAuditRun',
        'AuditJudge',
        'AuditEvaluationJob',
        'Services\Audit',
    ];

    $guardedFiles = [
        app_path('Services/Scoring/MeanCalculator.php'),
        app_path('Services/Scoring/AssessableFractionReliability.php'),
        app_path('Services/Scoring/CompletionGate.php'),
        app_path('Services/Scoring/IndicatorValidator.php'),
        app_path('Services/Scoring/ExcerptValidator.php'),
        app_path('Services/Scoring/EvaluationParser.php'),
        // design.md's File Changes table cites `app/Support/Prompting/
        // PromptBuilder.php` — the actual, current path (confirmed by this
        // test's own toBeFile() assertion) is app/Services/Scoring/
        // PromptBuilder.php, alongside the other formula classes. Same class
        // of path correction as C-A (SessionCostEstimator).
        app_path('Services/Scoring/PromptBuilder.php'),
        app_path('Jobs/ScoreEvaluationJob.php'),
        app_path('Services/Webhooks/EvaluationPayloadAssembler.php'),
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
        "The scoring pipeline is byte-unchanged by this capability (D11/AD-7) — none of these files may reference an audit type:\n  - %s",
        implode("\n  - ", $violations)
    ));
})->group('arch');
