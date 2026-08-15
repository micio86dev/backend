<?php

declare(strict_types=1);

/**
 * ProfileVsUserManagementBoundaryTest (user-profile-self-service).
 *
 * REQ: Self-Service Coexists Without Weakening Admin-Only Policy
 * (openspec/changes/user-profile-self-service/specs/user-management/spec.md)
 */

use App\Models\Organization;
use App\Models\User;

test('an operator still cannot modify another user through /api/users/{id}', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'operator');
    $target = User::factory()->create(['organization_id' => $org->id]);

    $this->withToken($token)->patchJson("/api/users/{$target->id}", ['name' => 'Hacked'])
        ->assertForbidden();
});

test('an operator using /api/profile leaves every other user row byte-identical', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'operator');
    $target = User::factory()->create(['organization_id' => $org->id, 'name' => 'Untouched']);
    $targetAttributesBefore = $target->fresh()->getAttributes();

    $this->withToken($token)->patchJson('/api/profile', ['name' => 'My Own New Name'])
        ->assertOk();

    expect($target->fresh()->getAttributes())->toBe($targetAttributesBefore);
});
