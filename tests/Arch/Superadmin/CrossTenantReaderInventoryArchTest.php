<?php

declare(strict_types=1);

/**
 * Pins the deliberate cross-tenant bypass inventory to exactly two named
 * classes under `App\Support\Superadmin\` (design D2).
 *
 * `AdminTenancySafetyArchTest` forbids `withoutGlobalScopes(` under
 * `app/Http/` and `app/Services/Admin/`, but its regex is blind to the
 * SINGULAR `withoutGlobalScope('tenant')` form — so "one auditable place" was,
 * until this test, a comment rather than an enforced invariant. A third file
 * appearing under `App\Support\Superadmin\` calling either form turns this
 * red, so the audit list cannot grow quietly.
 *
 * REQ: openspec/changes/superadmin-clients-console/specs/tenancy/spec.md
 *      "Cross-Tenant Aggregate Reads Are Confined To App\Support\Superadmin\"
 */
test('the bypass inventory under app/Support/Superadmin/ is exactly two named classes', function (): void {
    $directory = base_path('app/Support/Superadmin');

    $callPattern = '/(->|::)withoutGlobalScopes?\(/';

    $violators = [];

    if (is_dir($directory)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if ($source !== false && preg_match($callPattern, $source) === 1) {
                $violators[] = $file->getFilename();
            }
        }
    }

    sort($violators);

    expect($violators)->toBe(['ClientDirectory.php', 'ClientOverviewReader.php'], 'The cross-tenant bypass '
        .'inventory under App\Support\Superadmin\ MUST be exactly these two named classes. '
        .'Found: '.implode(', ', $violators));
})->group('arch');
