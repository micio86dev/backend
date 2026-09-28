<?php

declare(strict_types=1);

/**
 * Architecture guard: every PHP file under app/ declares strict_types (D1/D2,
 * strict-types-everywhere).
 *
 * `RecursiveDirectoryIterator`, NOT `glob('app/**\/*.php', GLOB_BRACE)` — verified
 * during design that PHP's glob `**` does not recurse arbitrarily deep in this
 * environment: it finds only 125 of the real 516 `.php` files under `app/`. A
 * glob-based version of this test would silently undercount and could report a false
 * green while missing un-annotated files outside its shallow reach.
 *
 * Mirrors the glob+file_get_contents+str_contains shape of
 * tests/Arch/ScoringFormulaIsolationTest.php / tests/Arch/C11/AdminTenancySafetyArchTest.php
 * — this codebase's established arch-test convention (no pest-plugin-arch dependency) —
 * substituting the recursive iterator only where a flat/shallow glob would be wrong.
 */
test('every file under app/ declares strict_types', function (): void {
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (! str_contains($source, 'declare(strict_types=1);')) {
            $offenders[] = str_replace(app_path().'/', '', $file->getPathname());
        }
    }

    sort($offenders);

    expect($offenders)->toBe([], sprintf(
        "These files under app/ do not declare(strict_types=1): %s.\n".
        'Add `declare(strict_types=1);` right after the opening <?php tag, before the namespace.',
        implode(', ', $offenders)
    ));
});
