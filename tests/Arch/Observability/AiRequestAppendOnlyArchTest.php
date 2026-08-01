<?php

declare(strict_types=1);

/**
 * Architecture guard: `ai_requests` rows are never mutated (C13, design D4).
 *
 * A cost record that can be edited is not a cost record. The table has no
 * `updated_at` by design, and business logic must only ever append to it.
 *
 * This is a guard rather than a convention for a specific reason visible in
 * this very change: C9 recorded its same-transaction invariant in prose and a
 * docblock, never wrote the test the docblock advertised, and the invariant was
 * then violated on four failure paths for months without anyone noticing.
 * Append-only gets an enforcement point instead.
 */

use App\Models\AiRequest;

test('no business logic mutates an ai_requests row', function (): void {
    $violations = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

        // The model file itself is allowed to mention these — it defines them.
        if ($relative === 'app/Models/AiRequest.php') {
            continue;
        }

        foreach (['AiRequest::query()->update(', 'AiRequest::where(', 'AiRequest::find('] as $needle) {
            if (str_contains($source, $needle)) {
                $violations[] = "{$relative} contains {$needle}";
            }
        }
    }

    expect($violations)->toBe([], sprintf(
        "ai_requests is append-only — read it through a dedicated reader, never mutate it:\n  - %s",
        implode("\n  - ", $violations)
    ));
});

test('the model declares no updated_at', function (): void {
    // Eloquent would happily maintain one if timestamps were enabled, and the
    // column does not exist — the write would fail at the DB rather than at
    // review.
    expect((new AiRequest)->timestamps)->toBeFalse();
});

test('the model exposes the cost conformance fields', function (): void {
    // Pins the field set the observability spec promises. Without provider and
    // estimated_cost_usd, "estimated cost per provider and model" is not
    // computable from these records alone — which is exactly the gap C11 hit.
    $fillable = (new AiRequest)->getFillable();

    foreach (['provider', 'estimated_cost_usd', 'success', 'failure_reason'] as $field) {
        expect($fillable)->toContain($field);
    }
});
