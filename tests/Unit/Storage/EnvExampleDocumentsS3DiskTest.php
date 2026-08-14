<?php

declare(strict_types=1);

/**
 * Docs guard (object-storage-fix, WARNING 2 — post-apply verification finding).
 *
 * REQ: "Disk selection and S3 credentials are documented"
 * (openspec/changes/object-storage-fix/specs/interview-session/spec.md) —
 * "every env() key referenced by [the s3 disk] block, plus FILESYSTEM_DISK,
 * appears" in `api/.env.example`.
 *
 * Deliberately cheap: extracts every `env('KEY'` occurrence from the `s3`
 * disk block in `config/filesystems.php` and asserts each one (plus
 * FILESYSTEM_DISK) is documented in `.env.example`. Catches a config key
 * being added to the `s3` disk block without a matching `.env.example` line —
 * the exact gap this change fixed once already (D6) — without hand-maintaining
 * a duplicate list of keys that would itself drift.
 */
test('.env.example documents every key the s3 disk block reads, plus FILESYSTEM_DISK', function (): void {
    $configSource = file_get_contents(config_path('filesystems.php'));
    expect($configSource)->not->toBeFalse();

    // Isolate the 's3' => [ ... ] block specifically — a bare env(...) scan
    // over the whole file would also pick up 'local' and 'public' disk keys
    // that have nothing to do with the s3 disk.
    expect(preg_match("/'s3'\s*=>\s*\[(.*?)\n\s*\],/s", (string) $configSource, $matches))->toBe(1);
    $s3Block = $matches[1];

    preg_match_all("/env\('([A-Z0-9_]+)'/", $s3Block, $envMatches);
    $requiredKeys = [...$envMatches[1], 'FILESYSTEM_DISK'];

    expect($requiredKeys)->not->toBeEmpty();

    $envExample = file_get_contents(base_path('.env.example'));
    expect($envExample)->not->toBeFalse();

    $missing = [];
    foreach ($requiredKeys as $key) {
        if (preg_match('/^'.preg_quote($key, '/').'=/m', (string) $envExample) !== 1) {
            $missing[] = $key;
        }
    }

    expect($missing)->toBe([], '.env.example is missing documentation for: '.implode(', ', $missing)
        .' — every key the s3 disk block reads (config/filesystems.php), plus FILESYSTEM_DISK, '
        .'must appear there (D6).');
});
