<?php

declare(strict_types=1);

/**
 * Architecture guard: every ShouldQueue job that performs tenant-scoped writes
 * MUST establish tenant context via App\Support\Tenancy\TenantContextScope, or
 * be explicitly allowlisted below with a written justification.
 *
 * This is D6 net 2 (CI, heuristic): net 1 (TenantScoped::creating throwing
 * MissingTenantContextException) is the real, exact guarantee — this test
 * catches the omission before the job ever runs, so a future job author
 * cannot silently forget the boundary.
 *
 * Mirrors the reflection+glob+violations shape of
 * tests/Arch/C2/TenantModelArchTest.php:40-107.
 *
 * HARDENED (C10 PR5, orchestrator-verified finding): the original discovery used
 * `glob(base_path('app/Jobs/*.php'))`, which has two blind spots:
 *   1. A single `*` glob is NOT recursive — a ShouldQueue class in a subdirectory
 *      (e.g. `app/Jobs/Webhooks/DeliverWebhookJob.php`) would be silently skipped,
 *      pass CI, and throw MissingTenantContextException on its first production write.
 *   2. It only scanned `app/Jobs/` — a ShouldQueue class living in `app/Listeners/`
 *      or elsewhere in `app/` escaped entirely.
 * Discovery is now a recursive walk of the ENTIRE `app/` tree via
 * RecursiveDirectoryIterator, extracted into c10DiscoverShouldQueueViolations() so
 * the recursion itself can be proven correct against a controlled fixture tree
 * (see the proof test below) without touching the real `app/` directory.
 *
 * REQ: Queued-Job Tenant Context Establishment (openspec/specs/tenancy/spec.md)
 */

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Recursively scan $rootDir for .php files, resolve each to a fully-qualified class
 * name under $namespaceRoot (PSR-4: directory structure mirrors namespace), and
 * return the class names of every ShouldQueue implementor that neither references
 * TenantContextScope:: in its own source NOR appears in $allowlist.
 *
 * @param  array<string, string>  $allowlist  class-string => written justification
 * @return list<string>
 */
function c10DiscoverShouldQueueViolations(string $rootDir, string $namespaceRoot, array $allowlist): array
{
    if (! is_dir($rootDir)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS)
    );

    $violations = [];

    foreach ($iterator as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        if (! $fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
            continue;
        }

        $relativePath = ltrim(
            str_replace($rootDir, '', $fileInfo->getPathname()),
            DIRECTORY_SEPARATOR
        );
        $relativeClass = str_replace(
            ['/', DIRECTORY_SEPARATOR, '.php'],
            ['\\', '\\', ''],
            $relativePath
        );
        $class = rtrim($namespaceRoot, '\\').'\\'.$relativeClass;

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable() || ! $reflection->implementsInterface(ShouldQueue::class)) {
            continue;
        }

        if (array_key_exists($class, $allowlist)) {
            continue;
        }

        $source = file_get_contents($fileInfo->getPathname());

        if ($source === false || ! str_contains($source, 'TenantContextScope::')) {
            $violations[] = $class;
        }
    }

    return $violations;
}

test('every ShouldQueue job under app/ references TenantContextScope or is explicitly allowlisted', function (): void {
    // Allowlist entries MUST carry a written justification — verified, not assumed.
    $allowlist = [
        // FinalizeInterview performs zero tenant-scoped writes (design D3): it only
        // dispatches ScoreEvaluationJob (which IS covered below) and mutates
        // Participant, a plain Model (does NOT extend TenantModel — see
        // app/Models/Participant.php:22-25).
        'App\\Jobs\\FinalizeInterview' => 'Zero tenant-scoped writes (design D3) — only touches Participant, a plain (non-TenantModel) Model.',
        // SendPasswordResetLinkJob is CROSS-TENANT BY CONSTRUCTION and must
        // stay that way. It reads exactly one row — a User, a plain
        // (non-TenantModel) Model — matched on the globally unique
        // `users.email`, and writes only `password_reset_tokens`, which is
        // keyed on that same email and has no organization_id at all.
        //
        // Opening a tenant scope here would be worse than pointless: a
        // platform superadmin has `organization_id IS NULL`, and
        // TenantContextScope::runFor() refuses orgId < 1 outright — so the
        // one class of user with no other recovery path would be the one
        // class this job could not serve.
        'App\\Jobs\\SendPasswordResetLinkJob' => 'Cross-tenant by construction: reads User (a plain, non-TenantModel Model) by globally unique email and writes only password_reset_tokens, which has no organization_id. Must also serve platform superadmins (organization_id IS NULL), which TenantContextScope::runFor() cannot express.',
        // SendUserInvitationJob is the same shape and for the same reasons.
        // It reads ONE User by primary key — a plain, non-TenantModel Model —
        // and writes only `password_reset_tokens`, which is keyed on email and
        // has no organization_id. The tenant decision was already made and
        // authorized in `POST /api/users`; re-deriving it here from an id
        // would be a second, weaker copy of a check that already happened.
        //
        // The organization NAME it prints comes in as a constructor argument
        // rather than being read back through a scope, precisely so this job
        // performs no tenant-scoped read at all.
        'App\\Jobs\\SendUserInvitationJob' => 'Cross-tenant by construction: reads one User (a plain, non-TenantModel Model) by id and writes only password_reset_tokens, which has no organization_id. The organization name is passed in, never read back, so the job makes no tenant-scoped query.',
    ];

    $violations = c10DiscoverShouldQueueViolations(app_path(), 'App', $allowlist);

    expect($violations)
        ->toBe([], 'The following ShouldQueue jobs neither reference TenantContextScope:: nor are '
            .'allowlisted with a justification: '.implode(', ', $violations));
})->group('arch');

/**
 * Proof that the recursive/broadened discovery actually works — a guard that has
 * never been shown to fail is not a guard. Uses a controlled fixture tree under
 * tests/Fixtures/ArchGuardFixtures/ (never the real app/ directory) so this proof
 * is a permanent regression test, not a one-off manual check.
 */
test('c10DiscoverShouldQueueViolations recursively catches a ShouldQueue class in a nested subdirectory', function (): void {
    $fixtureRoot = base_path('tests/Fixtures/ArchGuardFixtures/Jobs');

    $violations = c10DiscoverShouldQueueViolations($fixtureRoot, 'Tests\\Fixtures\\ArchGuardFixtures\\Jobs', []);

    // The nested, non-compliant fixture MUST be caught — this is exactly the blind
    // spot a non-recursive glob('*.php') would have silently missed.
    expect($violations)->toContain('Tests\\Fixtures\\ArchGuardFixtures\\Jobs\\Nested\\NonCompliantNestedJob');

    // The root-level compliant fixture must NOT be flagged (it references
    // TenantContextScope:: in its source).
    expect($violations)->not->toContain('Tests\\Fixtures\\ArchGuardFixtures\\Jobs\\CompliantJob');

    // A plain (non-ShouldQueue) class must never be flagged, regardless of depth.
    expect($violations)->not->toContain('Tests\\Fixtures\\ArchGuardFixtures\\Jobs\\NotAJob');
})->group('arch');
