<?php

declare(strict_types=1);

/**
 * Architecture guard: tenancy-safety backstop for the admin read API (C11, task 2.3).
 *
 * D1 explicitly names AdminParticipantReader (a mandatory-argument typed reader)
 * as the MECHANISM — these two Pest arch tests are the acknowledged-weaker
 * backstop, kept because they are cheap and would have caught the exact
 * hazard already present at EvaluationPayloadAssembler.php:46
 * (withoutGlobalScopes() in a job-only-safe pattern).
 *
 * (a) no `withoutGlobalScopes(` anywhere under app/Http/ — that pattern is
 *     reserved for EvaluationPayloadAssembler's queued-job context, never HTTP
 *     (openspec/specs/tenancy/spec.md — "No withoutGlobalScopes() in HTTP controllers").
 * (b) no bare `Participant::` static call under app/Http/Controllers/Api —
 *     the sanctioned path is AdminParticipantReader::read() (D1); a direct
 *     static call bypasses both the org filter and the lifecycle gate.
 * (c) (task 5.3, PR A2) no `withoutGlobalScopes(` anywhere under
 *     app/Services/Admin — AdminEvaluationSerializer/AdminTranscriptSerializer
 *     MUST query Evaluation/CompetencyResult/IndicatorScore/InterviewSession/
 *     Utterance under the ambient TenantContext scope, never bypass it (spec
 *     scenario "Serializer never bypasses the tenant scope").
 *
 * Mirrors the glob+file_get_contents+str_contains shape of
 * tests/Arch/Tenancy/QueuedJobTenantContextArchTest.php.
 *
 * REQ: Cross-Tenant Isolation on Every Admin Read Endpoint
 *      (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md,
 *       openspec/specs/tenancy/spec.md)
 */

/**
 * Recursively list every .php file under $directory.
 *
 * @return list<string>
 */
function phpFilesUnder(string $directory): array
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

test('no withoutGlobalScopes() call exists anywhere under app/Http/', function (): void {
    $violations = [];

    // Matches an actual invocation (::withoutGlobalScopes( or ->withoutGlobalScopes()
    // only — NOT a bare string match, which would also flag documentation comments
    // that describe the anti-pattern in prose (e.g. SsoExchangeController.php:96,
    // which documents that ONLY the named 'tenant' scope may be stripped there,
    // via withoutGlobalScope() singular — never the plural, no-args form).
    $callPattern = '/(->|::)withoutGlobalScopes\(/';

    foreach (phpFilesUnder(base_path('app/Http')) as $file) {
        $source = file_get_contents($file);

        if ($source !== false && preg_match($callPattern, $source) === 1) {
            $violations[] = $file;
        }
    }

    expect($violations)->toBe([], 'withoutGlobalScopes() is reserved for the queued-job context '
        .'(EvaluationPayloadAssembler) and MUST NOT appear under app/Http/. Violations: '
        .implode(', ', $violations));
})->group('arch');

test('no withoutGlobalScopes() call exists anywhere under app/Services/Admin/ (task 5.3)', function (): void {
    $violations = [];

    $callPattern = '/(->|::)withoutGlobalScopes\(/';

    foreach (phpFilesUnder(base_path('app/Services/Admin')) as $file) {
        $source = file_get_contents($file);

        if ($source !== false && preg_match($callPattern, $source) === 1) {
            $violations[] = $file;
        }
    }

    expect($violations)->toBe([], 'withoutGlobalScopes() is reserved for the queued-job context '
        .'(EvaluationPayloadAssembler) and MUST NOT appear in the admin serializers under '
        .'app/Services/Admin. Violations: '.implode(', ', $violations));
})->group('arch');

test('no bare Participant:: static call exists under app/Http/Controllers/Api', function (): void {
    $violations = [];

    foreach (phpFilesUnder(base_path('app/Http/Controllers/Api')) as $file) {
        $source = file_get_contents($file);

        if ($source !== false && str_contains($source, 'Participant::')) {
            $violations[] = $file;
        }
    }

    expect($violations)->toBe([], 'A direct Participant:: static call bypasses AdminParticipantReader '
        .'(D1) — both the org filter and the lifecycle gate. Use AdminParticipantReader::read() instead. '
        .'Violations: '.implode(', ', $violations));
})->group('arch');
