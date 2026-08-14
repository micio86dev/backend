<?php

declare(strict_types=1);

/**
 * Architecture guard: single storage-disk resolution point (object-storage-fix, D2).
 *
 * D1 makes the disk name UNWRITABLE at the call site: application code under
 * `app/` calls `Storage::disk()` / `Storage::put()` with no argument, letting
 * Laravel resolve `getDefaultDriver()` from `config('filesystems.default')`
 * exactly once. This guard makes "writer and deleter disagree about the disk"
 * a syntactic impossibility rather than a convention two reviewers must remember.
 *
 * Mirrors the glob + file_get_contents + preg_match/str_contains shape of
 * tests/Arch/C11/AdminTenancySafetyArchTest.php (reuses its phpFilesUnder()
 * helper shape, redeclared here per-file — Pest arch test files do not share
 * a function namespace across test runs).
 *
 * Three rules over api/app/:
 * (a) no argument to disk( — enforces D1 directly.
 * (b) no quoted 's3' disk literal — catches Storage::build, config([...]),
 *     future creativity. Gotcha: must match the QUOTED literal specifically.
 *     A bare str_contains($source, 's3') false-positives on the `s3_key`
 *     column, which legitimately appears in all three files under guard (the
 *     rename is explicitly out of scope for this change).
 * (c) filesystems.default is never read in app/ — a second *resolution* point
 *     is a second place the writer and the purge could diverge.
 *
 * REQ: "Snapshot write and retention purge resolve the same disk by
 *      construction" (openspec/changes/object-storage-fix/specs/interview-session/spec.md)
 */

/**
 * Recursively list every .php file under $directory.
 *
 * @return list<string>
 */
function phpFilesUnderStorage(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    $files = [];

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

test('no argument is ever passed to disk( under app/', function (): void {
    $violations = [];

    // Matches Storage::disk(<something>) / $foo->disk(<something>) where
    // <something> is not immediately the closing paren — i.e. an argument
    // was supplied. `Storage::disk()` / `->disk()` (no argument) is exempt.
    $callPattern = '/(?:Storage::|->)disk\(\s*[^)\s]/';

    foreach (phpFilesUnderStorage(base_path('app')) as $file) {
        $source = file_get_contents($file);

        if ($source !== false && preg_match($callPattern, $source) === 1) {
            $violations[] = $file;
        }
    }

    expect($violations)->toBe([], 'disk( must never take an argument under app/ — the disk name must be '
        .'unwritable at the call site (D1). Violations: '.implode(', ', $violations));
})->group('arch');

test('no quoted "s3" disk literal exists under app/', function (): void {
    $violations = [];

    // Matches the QUOTED literal only ('s3' or "s3") — a bare str_contains
    // would false-positive on the `s3_key` column name, which legitimately
    // appears in SnapshotController, PurgeExpiredDataCommand and
    // SessionReviewController (the column rename is out of scope here).
    $literalPattern = '/[\'"]s3[\'"]/';

    foreach (phpFilesUnderStorage(base_path('app')) as $file) {
        $source = file_get_contents($file);

        if ($source !== false && preg_match($literalPattern, $source) === 1) {
            $violations[] = $file;
        }
    }

    expect($violations)->toBe([], 'A quoted disk-name literal (\'s3\') must never appear under app/ — it '
        .'reintroduces a second, writable way to name the disk (D1/D2). Violations: '.implode(', ', $violations));
})->group('arch');

test('filesystems.default is never read directly under app/', function (): void {
    $violations = [];

    foreach (phpFilesUnderStorage(base_path('app')) as $file) {
        $source = file_get_contents($file);

        if ($source !== false && str_contains($source, 'filesystems.default')) {
            $violations[] = $file;
        }
    }

    expect($violations)->toBe([], 'filesystems.default must never be read directly under app/ — a second '
        .'resolution point is a second place the writer and the purge could diverge (D1/D2). '
        .'Violations: '.implode(', ', $violations));
})->group('arch');
