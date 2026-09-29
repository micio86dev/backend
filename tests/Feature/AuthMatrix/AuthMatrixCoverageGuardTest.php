<?php

declare(strict_types=1);

/**
 * Authorization matrix — route coverage guard.
 *
 * `Tests\Helpers\AuthMatrix\AuthMatrixCatalogue` declares, for EVERY `/api`
 * route, who may call it and what each actor must get back. That declaration
 * is only worth anything while it stays complete, so this guard fails — with
 * the exact route named — when the catalogue and the router disagree:
 *
 *   (a) a registered `/api` route has no catalogue entry (a new endpoint was
 *       shipped without anybody deciding who may call it);
 *   (b) a catalogue entry names a route that no longer exists (a stale
 *       expectation that would otherwise keep "passing" forever);
 *   (c) an entry is malformed, or an expectation is left `unresolved` without
 *       a matching KNOWN_QUESTIONS record (an open doubt nobody is tracking).
 *
 * The idiom is `ExposureTest` / `ExposureCatalogue`: hand-maintained on
 * purpose. Deriving the catalogue from the router would make (a) vacuous.
 */

use Tests\Helpers\AuthMatrix\AuthMatrix;
use Tests\Helpers\AuthMatrix\AuthMatrixCatalogue;

test('every registered /api route has an authorization matrix entry', function (): void {
    $missing = array_values(array_diff(
        AuthMatrixCatalogue::registeredRouteKeys(),
        array_keys(AuthMatrixCatalogue::entries()),
    ));

    expect($missing)->toBe(
        [],
        "These /api routes are registered but have no entry in tests/Helpers/AuthMatrix/AuthMatrixCatalogue.php.\n"
        ."Decide who may call each one (auth kind, tenancy kind, expected outcome per actor) and add it:\n  - "
        .implode("\n  - ", $missing),
    );
});

test('every authorization matrix entry refers to a route that still exists', function (): void {
    $stale = array_values(array_diff(
        array_keys(AuthMatrixCatalogue::entries()),
        AuthMatrixCatalogue::registeredRouteKeys(),
    ));

    expect($stale)->toBe(
        [],
        "These authorization matrix entries point at routes that are no longer registered.\n"
        ."Remove them (or fix the key if the route was renamed):\n  - ".implode("\n  - ", $stale),
    );
});

test('every authorization matrix entry is well-formed', function (): void {
    $problems = [];

    foreach (AuthMatrixCatalogue::entries() as $key => $entry) {
        if (! in_array($entry['auth'], AuthMatrix::AUTH_KINDS, true)) {
            $problems[] = "{$key}: unknown auth kind '{$entry['auth']}'";
        }
        if (! in_array($entry['tenancy'], AuthMatrix::TENANCY_KINDS, true)) {
            $problems[] = "{$key}: unknown tenancy kind '{$entry['tenancy']}'";
        }
        if (trim($entry['domain']) === '') {
            $problems[] = "{$key}: empty domain";
        }

        $expectedActors = AuthMatrix::ACTORS_BY_AUTH[$entry['auth']] ?? [];
        $actualActors = array_keys($entry['outcomes']);
        sort($expectedActors);
        sort($actualActors);

        if ($expectedActors !== $actualActors) {
            $problems[] = sprintf(
                "%s: outcomes must cover exactly the '%s' actors (%s), got (%s)",
                $key,
                $entry['auth'],
                implode(', ', $expectedActors),
                implode(', ', $actualActors),
            );
        }

        foreach ($entry['outcomes'] as $actor => $outcome) {
            if (! in_array($outcome, AuthMatrix::OUTCOMES, true)) {
                $problems[] = "{$key}: actor '{$actor}' has unknown outcome '{$outcome}'";
            }
        }
    }

    expect($problems)->toBe([], "Malformed authorization matrix entries:\n  - ".implode("\n  - ", $problems));
});

test('every unresolved expectation is tracked by exactly one KNOWN_QUESTIONS record, and vice versa', function (): void {
    $unresolved = [];
    foreach (AuthMatrixCatalogue::entries() as $key => $entry) {
        foreach ($entry['outcomes'] as $actor => $outcome) {
            if ($outcome === AuthMatrix::UNRESOLVED) {
                $unresolved["{$key} :: {$actor}"] = true;
            }
        }
    }

    $tracked = [];
    $duplicates = [];
    foreach (AuthMatrixCatalogue::knownQuestions() as $id => $question) {
        foreach ($question['cells'] as $key => $actors) {
            foreach ($actors as $actor) {
                $cell = "{$key} :: {$actor}";
                if (isset($tracked[$cell])) {
                    $duplicates[] = $cell;
                }
                $tracked[$cell] = $id;
            }
        }
    }

    $untracked = array_keys(array_diff_key($unresolved, $tracked));
    $orphaned = array_keys(array_diff_key($tracked, $unresolved));

    expect($untracked)->toBe([], "Unresolved cells with no KNOWN_QUESTIONS record:\n  - ".implode("\n  - ", $untracked))
        ->and($orphaned)->toBe([], "KNOWN_QUESTIONS cells that are not marked unresolved in the catalogue:\n  - ".implode("\n  - ", $orphaned))
        ->and($duplicates)->toBe([], "Cells listed by more than one KNOWN_QUESTIONS record:\n  - ".implode("\n  - ", $duplicates));
});
