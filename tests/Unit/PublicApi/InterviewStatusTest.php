<?php

declare(strict_types=1);

/**
 * `App\Support\PublicApi\InterviewStatus` — public-api step 5, SPEC.md §3.3
 * "Interview status" table, G-06.
 *
 * Pins the 1:1 map between the public English status and the stored
 * (binding, Italian) `participants.status` value so a future edit on either
 * side of the map cannot silently drift without failing this test.
 */

use App\Support\PublicApi\InterviewStatus;

test('every public status maps to exactly the binding stored value', function (): void {
    expect(InterviewStatus::Pending->toStored())->toBe('in_attesa');
    expect(InterviewStatus::InProgress->toStored())->toBe('in_corso');
    expect(InterviewStatus::UnderEvaluation->toStored())->toBe('in_valutazione');
    expect(InterviewStatus::Completed->toStored())->toBe('completato');
    expect(InterviewStatus::Error->toStored())->toBe('errore');
});

test('fromStored() is the exact inverse of toStored() for every case', function (): void {
    foreach (InterviewStatus::cases() as $case) {
        expect(InterviewStatus::fromStored($case->toStored()))->toBe($case);
    }
});

test('fromStored() throws on a stored value outside the binding lifecycle', function (): void {
    expect(fn () => InterviewStatus::fromStored('not-a-real-status'))
        ->toThrow(ValueError::class);
});

test('the public enum has exactly the five contract values, no more, no less', function (): void {
    $values = array_map(fn (InterviewStatus $case): string => $case->value, InterviewStatus::cases());
    sort($values);

    expect($values)->toBe(['completed', 'error', 'in_progress', 'pending', 'under_evaluation']);
});
