<?php

declare(strict_types=1);

/**
 * ProfileEmailUniquenessTest (user-profile-self-service).
 *
 * REQ: Email Uniqueness On Self-Update
 * (openspec/changes/user-profile-self-service/specs/user-self-service/spec.md)
 */

use App\Models\Organization;
use App\Models\User;

test('changing email to an address already in use is rejected', function (): void {
    $org = Organization::factory()->create();
    ['user' => $userA, 'token' => $token] = authUserAndTokenForRole($org, 'operator');
    User::factory()->create(['organization_id' => $org->id, 'email' => 'taken@example.test']);

    $response = $this->withToken($token)->patchJson('/api/profile', [
        'email' => 'taken@example.test',
    ]);

    $response->assertStatus(422);
    expect($userA->fresh()->email)->not->toBe('taken@example.test');
});

test('changing email to the callers own current email is allowed (unique ignoring self)', function (): void {
    $org = Organization::factory()->create();
    ['user' => $user, 'token' => $token] = authUserAndTokenForRole($org, 'operator');

    $response = $this->withToken($token)->patchJson('/api/profile', [
        'email' => $user->email,
        'name' => 'Still Me',
    ]);

    $response->assertOk();
});
