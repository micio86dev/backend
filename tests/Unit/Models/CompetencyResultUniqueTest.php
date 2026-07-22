<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Schema-level assertions for the `competency_results` table (C9 D1 Task 6.2).
 *
 * Verifies:
 * - unique(evaluation_id, competency_code) constraint exists.
 *
 * REQ: CompetencyResult schema constraints (C9 D1)
 */
test('competency_results table has unique(evaluation_id, competency_code)', function (): void {
    $indexes = Schema::getIndexes('competency_results');

    $uniqueIndex = collect($indexes)->first(function (array $index): bool {
        $cols = $index['columns'];

        return $index['unique'] === true
            && count($cols) === 2
            && in_array('evaluation_id', $cols, true)
            && in_array('competency_code', $cols, true);
    });

    expect($uniqueIndex)->not->toBeNull(
        'Expected a unique composite index on (evaluation_id, competency_code) in competency_results.'
    );
});
