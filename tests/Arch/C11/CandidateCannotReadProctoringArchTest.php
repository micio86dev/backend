<?php

declare(strict_types=1);

/**
 * Architecture guard: proctoring evidence is never readable by a candidate
 * (C11 review surface, design D2).
 *
 * The integrity taxonomy is, read plainly, a list of behaviours being counted
 * and the thresholds at which they count. Handing that to the person being
 * measured tells them exactly what to avoid doing and for how long, which
 * defeats the measurement. The same applies to the webcam snapshots: they are
 * evidence collected about someone, held for an operator's judgement, not a
 * feed served back to the subject.
 *
 * The candidate guard therefore WRITES this data and must never READ it.
 * `Candidate\IntegrityController` and `Candidate\SnapshotController` are
 * store-only by design; this guard exists because that is a property a
 * well-meaning future change could erode without noticing — adding a `show()`
 * beside a `store()` looks like symmetry, not like a disclosure.
 *
 * Mirrors the glob + file_get_contents + str_contains shape of
 * tests/Arch/C11/AdminTenancySafetyArchTest.php.
 *
 * REQ: Admin session review — "Candidates can never read the review"
 */

/**
 * @return list<string>
 */
function candidateControllerFiles(): array
{
    $files = glob(app_path('Http/Controllers/Candidate/*.php')) ?: [];

    return array_values($files);
}

test('candidate controllers expose no read method for proctoring evidence', function (): void {
    $offenders = [];

    foreach (candidateControllerFiles() as $file) {
        $source = (string) file_get_contents($file);
        $name = basename($file);

        // Only the two controllers that touch proctoring evidence are in scope;
        // the others may legitimately have read methods of their own.
        if (! str_contains($name, 'Integrity') && ! str_contains($name, 'Snapshot')) {
            continue;
        }

        foreach (['function index', 'function show', 'function list'] as $reader) {
            if (str_contains($source, $reader)) {
                $offenders[] = "{$name} declares {$reader}()";
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('no candidate-guarded route reads integrity events or snapshots', function (): void {
    $routes = (string) file_get_contents(base_path('routes/api.php'));

    // The candidate block runs from its prefix to the end of the file; every
    // route inside it is reachable with a candidate token.
    $start = strpos($routes, "Route::prefix('candidate')");
    expect($start)->not->toBeFalse();

    $candidateBlock = substr($routes, (int) $start);

    $offenders = [];

    foreach (explode("\n", $candidateBlock) as $line) {
        if (! str_contains($line, 'Route::get')) {
            continue;
        }

        if (str_contains($line, 'integrity') || str_contains($line, 'snapshot') || str_contains($line, 'review')) {
            $offenders[] = trim($line);
        }
    }

    expect($offenders)->toBe([]);
});

test('the summarizer is never referenced from a candidate controller', function (): void {
    $offenders = [];

    foreach (candidateControllerFiles() as $file) {
        if (str_contains((string) file_get_contents($file), 'IntegritySummarizer')) {
            $offenders[] = basename($file);
        }
    }

    // Scoring belongs to the operator's view. A candidate controller reaching
    // for it means a score is on its way to the wrong audience, whatever the
    // route currently looks like.
    expect($offenders)->toBe([]);
});
