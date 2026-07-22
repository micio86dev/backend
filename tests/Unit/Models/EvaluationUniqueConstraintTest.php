<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Schema-level assertions for the `evaluations` table (C9 D1 Task 6.1).
 *
 * Verifies:
 * - unique(participant_id) constraint exists (single unique constraint on evaluations).
 * - (organization_id, participant_id) composite index is a performance index, NOT unique.
 * - evaluated_at is nullable.
 *
 * REQ: Evaluation schema constraints (C9 D1)
 */
test('evaluations table has a unique index on participant_id', function (): void {
    // The unique(participant_id) constraint must exist — it is the ONLY unique
    // constraint on evaluations (globally unique per C6 participant_id mint).
    $indexes = Schema::getIndexes('evaluations');

    $uniqueOnParticipant = collect($indexes)->first(function (array $index): bool {
        return $index['unique'] === true
            && count($index['columns']) === 1
            && $index['columns'][0] === 'participant_id';
    });

    expect($uniqueOnParticipant)->not->toBeNull(
        'Expected a unique index on participant_id to exist on the evaluations table.'
    );
});

test('evaluations table (organization_id, participant_id) index is NOT unique', function (): void {
    // (organization_id, participant_id) is a performance composite index (D22 org-first),
    // NOT a second unique constraint. A second unique would silently break retry logic.
    $indexes = Schema::getIndexes('evaluations');

    $orgParticipantIndex = collect($indexes)->first(function (array $index): bool {
        $cols = $index['columns'];

        return count($cols) === 2
            && in_array('organization_id', $cols, true)
            && in_array('participant_id', $cols, true);
    });

    expect($orgParticipantIndex)->not->toBeNull(
        'Expected a composite index on (organization_id, participant_id).'
    );

    // This index MUST NOT be unique.
    expect($orgParticipantIndex['unique'])->toBeFalse(
        'The (organization_id, participant_id) index must be a performance index, NOT unique.'
    );
});

test('evaluations.evaluated_at is nullable', function (): void {
    // evaluated_at is null while status = processing; set on terminal transition.
    $columns = Schema::getColumns('evaluations');

    $evaluatedAt = collect($columns)->firstWhere('name', 'evaluated_at');

    expect($evaluatedAt)->not->toBeNull('evaluated_at column must exist on evaluations.');
    expect($evaluatedAt['nullable'])->toBeTrue('evaluated_at must be nullable.');
});
