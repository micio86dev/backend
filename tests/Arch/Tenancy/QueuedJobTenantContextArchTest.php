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
 * REQ: Queued-Job Tenant Context Establishment (openspec/specs/tenancy/spec.md)
 */

use Illuminate\Contracts\Queue\ShouldQueue;

test('every ShouldQueue job references TenantContextScope or is explicitly allowlisted', function (): void {
    // Allowlist entries MUST carry a written justification — verified, not assumed.
    $allowlist = [
        // FinalizeInterview performs zero tenant-scoped writes (design D3): it only
        // dispatches ScoreEvaluationJob (which IS covered below) and mutates
        // Participant, a plain Model (does NOT extend TenantModel — see
        // app/Models/Participant.php:22-25).
        'App\\Jobs\\FinalizeInterview' => 'Zero tenant-scoped writes (design D3) — only touches Participant, a plain (non-TenantModel) Model.',
    ];

    $jobFiles = glob(base_path('app/Jobs/*.php')) ?: [];

    $violations = [];

    foreach ($jobFiles as $file) {
        $class = 'App\\Jobs\\'.basename($file, '.php');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->implementsInterface(ShouldQueue::class)) {
            continue;
        }

        if (array_key_exists($class, $allowlist)) {
            continue;
        }

        $source = file_get_contents($file);

        if ($source === false || ! str_contains($source, 'TenantContextScope::')) {
            $violations[] = $class;
        }
    }

    expect($violations)
        ->toBe([], 'The following ShouldQueue jobs neither reference TenantContextScope:: nor are '
            .'allowlisted with a justification: '.implode(', ', $violations));
})->group('arch');
