<?php

declare(strict_types=1);

/**
 * PasswordThrottleTest (user-profile-self-service, design.md D5).
 *
 * REQ: Password Change Requires The Current Password — rate limited so the
 * endpoint is not a current-password oracle for a stolen bearer token.
 * (openspec/changes/user-profile-self-service/specs/user-self-service/spec.md)
 */

use App\Models\Organization;

test('the 7th wrong-current_password attempt in a minute is throttled', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'operator');

    for ($i = 0; $i < 6; $i++) {
        $this->withToken($token)->putJson('/api/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertStatus(422);
    }

    $this->withToken($token)->putJson('/api/profile/password', [
        'current_password' => 'wrong-password',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertStatus(429);
});
