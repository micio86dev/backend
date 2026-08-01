<?php

/**
 * Mass-assignment guard test for is_superadmin (C2).
 *
 * Asserts that a crafted payload with is_superadmin=true does not persist
 * because the column is excluded from User::$fillable.
 */

use App\Models\User;

test('is_superadmin cannot be mass-assigned via User::create', function (): void {
    // Attempt to create a user with is_superadmin=true via mass-assignment
    $user = User::create([
        'name' => 'Attacker',
        'email' => 'attacker@example.com',
        'password' => bcrypt('password'),
        'is_superadmin' => true,  // should be silently ignored
    ]);

    // Reload from DB to confirm the actual persisted value
    $persisted = User::find($user->id);
    expect($persisted->is_superadmin)->toBeFalse();
});

test('is_superadmin can be set directly on the model (not mass-assignment)', function (): void {
    $user = User::factory()->create();

    // Trusted service code sets it directly — this is the allowed pattern
    $user->is_superadmin = true;
    $user->save();

    $persisted = User::find($user->id);
    expect($persisted->is_superadmin)->toBeTrue();
});
