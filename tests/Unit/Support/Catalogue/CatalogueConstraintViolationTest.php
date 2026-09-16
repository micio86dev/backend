<?php

declare(strict_types=1);

/**
 * Z4 (R3-validate-then-lock-500, REQUIRED BEFORE ARCHIVE): unit coverage for
 * the mapping `CatalogueConstraintViolation` provides — every constraint a
 * catalogue-write controller can legitimately hit under a concurrent-write
 * race maps to the SAME stable error code its own FormRequest-layer check
 * already uses; anything else is left for the caller to rethrow.
 */

use App\Support\Catalogue\CatalogueConstraintViolation;
use Illuminate\Database\QueryException;

function fakeCatalogueQueryException(string $message): QueryException
{
    return new QueryException('pgsql', 'insert into "x" ...', [], new RuntimeException($message));
}

test('Z4: maps the role code uniqueness constraint', function (): void {
    $e = fakeCatalogueQueryException(
        'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "framework_roles_revision_code_unique"'
    );

    expect(CatalogueConstraintViolation::toErrorCode($e))->toBe('code_taken');
});

test('Z4: maps the competency code uniqueness constraint', function (): void {
    $e = fakeCatalogueQueryException(
        'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "framework_competencies_revision_code_unique"'
    );

    expect(CatalogueConstraintViolation::toErrorCode($e))->toBe('code_taken');
});

test('Z4: maps the role-scoped bars indicator position uniqueness constraint', function (): void {
    $e = fakeCatalogueQueryException(
        'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "framework_bars_indicators_rev_role_comp_position_unique"'
    );

    expect(CatalogueConstraintViolation::toErrorCode($e))->toBe('position_taken_for_pair');
});

test('Z4: maps the role-less bars indicator position uniqueness constraint', function (): void {
    $e = fakeCatalogueQueryException(
        'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "framework_bars_indicators_roleless_position_unique"'
    );

    expect(CatalogueConstraintViolation::toErrorCode($e))->toBe('position_taken_for_pair');
});

test('Z4: maps the default question position uniqueness constraint', function (): void {
    $e = fakeCatalogueQueryException(
        'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "framework_default_questions_rev_competency_position_unique"'
    );

    expect(CatalogueConstraintViolation::toErrorCode($e))->toBe('position_taken_for_competency');
});

test('Z4: maps the role-scoped pair-cap trigger exception', function (): void {
    $e = fakeCatalogueQueryException(
        'SQLSTATE[23514]: Check violation: 7 ERROR: framework_bars_indicators_role_pair_cap: role 1 / competency 2 in revision 3 would carry 4 indicators, at most 3 allowed'
    );

    expect(CatalogueConstraintViolation::toErrorCode($e))->toBe('bars_indicator_pair_full');
});

test('Z4: maps the role-less pair-cap trigger exception', function (): void {
    $e = fakeCatalogueQueryException(
        'SQLSTATE[23514]: Check violation: 7 ERROR: framework_bars_indicators_roleless_pair_cap: competency 2 in revision 3 would carry 4 role-less indicators, at most 3 allowed'
    );

    expect(CatalogueConstraintViolation::toErrorCode($e))->toBe('bars_indicator_pair_full');
});

test('Z4: an unrecognized constraint violation maps to null — the caller must rethrow', function (): void {
    $e = fakeCatalogueQueryException(
        'SQLSTATE[23503]: Foreign key violation: 7 ERROR: insert or update on table "framework_bars_indicators" violates foreign key constraint "some_unrelated_fk"'
    );

    expect(CatalogueConstraintViolation::toErrorCode($e))->toBeNull();
});
