<?php

/**
 * restrictOnDelete constraint test (C2).
 *
 * Verifies that deleting an Organization that has associated users fails at
 * the DB level due to the restrictOnDelete() FK constraint on users.organization_id.
 *
 * PostgreSQL aborts the current transaction on a constraint violation. To keep
 * the test clean we use a nested transaction (savepoint) so the assertion can
 * continue after the expected exception without corrupting the test's DB transaction.
 */

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('deleting an organization with users fails with a constraint violation', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    $exceptionThrown = false;

    // Use a nested transaction (savepoint) so that the FK constraint violation
    // does not abort the outer test transaction used by RefreshDatabase.
    DB::beginTransaction();
    try {
        $org->delete();
    } catch (QueryException $e) {
        $exceptionThrown = true;
        DB::rollBack();
    }

    expect($exceptionThrown)->toBeTrue('Expected QueryException from FK restrictOnDelete constraint');

    // The organization must still exist (outer transaction was not rolled back).
    expect(Organization::find($org->id))->not->toBeNull();

    // The user must still be associated with the org.
    expect(User::find($user->id)?->organization_id)->toBe($org->id);
});

test('deleting an organization with no users succeeds', function (): void {
    $org = Organization::factory()->create();

    // No users — delete must succeed.
    $org->delete();

    expect(Organization::find($org->id))->toBeNull();
});
