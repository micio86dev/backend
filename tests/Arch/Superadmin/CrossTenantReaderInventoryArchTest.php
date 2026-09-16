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

/**
 * Pins the SEPARATE `audit_logs`-direct-write bypass to exactly one named
 * class (framework-catalogue-authoring PR8, design D13).
 *
 * `PlatformAuditWriter` writes a platform-scoped (`organization_id = NULL`)
 * audit row via `DB::table('audit_logs')->insert(...)` — no Eloquent, so no
 * global scope is even in play, which is exactly why the test above (scoped
 * to `withoutGlobalScopes(`) is blind to this bypass. A second file writing
 * `audit_logs` directly could stamp a NULL org, or any other value, with no
 * `TenantScoped` guard watching it at all — this test is what keeps that
 * inventory at exactly one file.
 *
 * REQ: audit-log — "Catalogue Mutations Are Audited"
 */
test('the audit_logs-direct-write inventory under app/Support/Superadmin/ is exactly PlatformAuditWriter', function (): void {
    $directory = base_path('app/Support/Superadmin');

    $callPattern = "/DB::table\\(\\s*['\"]audit_logs['\"]\\s*\\)/";

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

    expect($violators)->toBe(['PlatformAuditWriter.php'], 'The `audit_logs`-direct-write inventory under '
        .'App\Support\Superadmin\ MUST be exactly this one named class. Found: '.implode(', ', $violators));
})->group('arch');
