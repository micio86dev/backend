<?php

declare(strict_types=1);

/**
 * Architecture guard: neither audit table is ever mutated after INSERT
 * (scoring-audit-jev design D11). Copied from
 * `AiRequestAppendOnlyArchTest.php`, including its raw-query-builder
 * needles — this codebase's own established convention for an append-only
 * table (see that file's docblock for why the raw-builder forms are banned
 * too, not only the Eloquent ones).
 *
 * GUARD task (P2.13): nothing under `app/` reads or writes either table yet
 * — `App\Support\Admin\AuditVerdictReader` is a P5 class named in the
 * exclusion list now so this guard does not need editing when it lands.
 * There is no meaningful RED for an invariant nothing has broken yet; this
 * is written once, verified green, and re-checked as a pass/fail gate on
 * every later slice that could regress it (P3a.23, P3b.19, P4.18, P5.19).
 */

use App\Models\IndicatorScoreAudit;
use App\Models\IndicatorScoreAuditRun;

test('no business logic mutates an indicator_score_audits or indicator_score_audit_runs row', function (): void {
    $violations = [];

    $bannedNeedles = [
        // Eloquent — qualified by class, so a caller elsewhere in the app
        // touching an unrelated model's ->save()/->update()/->delete() is
        // never a false positive (AiRequestAppendOnlyArchTest's own shape).
        'IndicatorScoreAudit::query()->update(',
        'IndicatorScoreAudit::where(',
        'IndicatorScoreAudit::find(',
        'IndicatorScoreAuditRun::query()->update(',
        'IndicatorScoreAuditRun::where(',
        'IndicatorScoreAuditRun::find(',
        // Raw query-builder mutation forms on both tables.
        "DB::table('indicator_score_audits')->update(",
        "DB::table('indicator_score_audits')->delete(",
        "DB::table('indicator_score_audits')->increment(",
        "DB::table('indicator_score_audits')->decrement(",
        "DB::table('indicator_score_audit_runs')->update(",
        "DB::table('indicator_score_audit_runs')->delete(",
        "DB::table('indicator_score_audit_runs')->increment(",
        "DB::table('indicator_score_audit_runs')->decrement(",
    ];

    // The two models and the (P5, not yet existing) reader are allowed to
    // mention these — they define/read them.
    $allowedFiles = [
        'app/Models/IndicatorScoreAudit.php',
        'app/Models/IndicatorScoreAuditRun.php',
        'app/Support/Admin/AuditVerdictReader.php',
    ];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

        if (in_array($relative, $allowedFiles, true)) {
            continue;
        }

        foreach ($bannedNeedles as $needle) {
            if (str_contains($source, $needle)) {
                $violations[] = "{$relative} contains {$needle}";
            }
        }
    }

    expect($violations)->toBe([], sprintf(
        "Both audit tables are append-only — read them through AuditVerdictReader, never mutate:\n  - %s",
        implode("\n  - ", $violations)
    ));
});

test('IndicatorScoreAuditRun declares no updated_at', function (): void {
    expect((new IndicatorScoreAuditRun)->timestamps)->toBeFalse();
});

test('IndicatorScoreAudit declares no updated_at', function (): void {
    expect((new IndicatorScoreAudit)->timestamps)->toBeFalse();
});
