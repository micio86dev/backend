<?php

declare(strict_types=1);

/**
 * A Feature test that touches the database must be wrapped in a transaction.
 *
 * `pest()->extend(TestCase::class)->in('Feature')` applies recursively, but
 * `RefreshDatabase` deliberately does NOT — `Feature/HealthTest.php` sits
 * directly under `Feature/` and is DB-free by design. So each test picks it up
 * one of two ways: its directory is named in `tests/Pest.php`, or the file
 * declares `uses(RefreshDatabase::class)` itself. Both are fine. NEITHER is a
 * defect that hides for a whole run.
 *
 * A test that writes rows outside a transaction passes the first time and
 * poisons the second: the rows are still there, and an unrelated test fails
 * with "the email has already been taken" pointing at code that is correct.
 * That cost real time to diagnose once, which is why this is a test rather
 * than a paragraph somebody is expected to have read.
 *
 * The heuristic is "does the file use a model factory". It is deliberately
 * narrow — it will not catch a test that inserts through the query builder —
 * but it catches the shape every test in this suite actually uses, with no
 * false positives to suppress.
 */
test('every Feature test that uses a factory gets RefreshDatabase from somewhere', function (): void {
    $pest = (string) file_get_contents(base_path('tests/Pest.php'));

    $offenders = [];

    /** @var array<int, string> $files */
    $files = glob(base_path('tests/Feature/**/*.php'), GLOB_BRACE) ?: [];
    $files = array_merge($files, glob(base_path('tests/Feature/*.php')) ?: []);

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        if (! str_contains($source, '::factory()')) {
            continue;
        }

        // Declared in the file itself — the other legitimate way.
        if (str_contains($source, 'RefreshDatabase')) {
            continue;
        }

        $relative = str_replace(base_path('tests/Feature/'), '', $file);
        $directory = str_contains($relative, '/') ? dirname($relative) : null;

        if ($directory !== null && str_contains($pest, "->in('Feature/{$directory}')")) {
            continue;
        }

        $offenders[] = $relative;
    }

    sort($offenders);

    expect($offenders)->toBe([], sprintf(
        "These Feature tests create rows with a factory and are NOT wrapped in a transaction, so their rows outlive the run: %s.\n".
        "Fix by adding the directory to tests/Pest.php:\n\n    pest()->use(RefreshDatabase::class)\n        ->in('Feature/<name>');\n\n".
        'or by declaring `uses(RefreshDatabase::class);` in the file itself.',
        implode(', ', $offenders)
    ));
});
