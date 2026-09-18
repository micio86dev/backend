<?php

declare(strict_types=1);

/**
 * Architecture guard: AD-6 as a property, not a promise (scoring-audit-jev
 * design D11, C-C). `AuditSubject` is built from exactly four persisted
 * `IndicatorScore` columns; with no transcript type reachable from anywhere
 * under `app/Services/Audit/` or from `AuditEvaluationJob`, a second scoring
 * pass over raw transcript text is not something the code declines to do —
 * it is something the code cannot express.
 *
 * GUARD task (P2.15): `app/Jobs/AuditEvaluationJob.php` does not exist yet
 * (P3a) and `app/Services/Audit/` currently holds only the P1 judge seam,
 * none of which references these types — see
 * `AuditAppendOnlyArchTest.php`'s docblock for why this is written once and
 * re-checked as a pass/fail gate on every later slice.
 */
test('app/Services/Audit and AuditEvaluationJob never reference a transcript or participant-identity type', function (): void {
    $bannedNeedles = [
        'TranscriptAssembler',
        'Utterance',
        'InterviewSession',
        'Participant',
    ];

    $guardedFiles = [];

    if (is_dir(app_path('Services/Audit'))) {
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Services/Audit'))) as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $guardedFiles[] = $file->getPathname();
        }
    }

    $jobFile = app_path('Jobs/AuditEvaluationJob.php');
    if (is_file($jobFile)) {
        $guardedFiles[] = $jobFile;
    }

    $violations = [];

    foreach ($guardedFiles as $file) {
        $source = (string) file_get_contents($file);
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

        foreach ($bannedNeedles as $needle) {
            if (str_contains($source, $needle)) {
                $violations[] = "{$relative} references {$needle}";
            }
        }
    }

    expect($violations)->toBe([], sprintf(
        "Jev sees only the persisted verdict (AD-6, spec.md) — no file under app/Services/Audit/, and not AuditEvaluationJob, may reference a transcript or participant-identity type:\n  - %s",
        implode("\n  - ", $violations)
    ));
})->group('arch');
