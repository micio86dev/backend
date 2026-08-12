<?php

declare(strict_types=1);

/**
 * New User Initial Password Set By Admin (backoffice-missing-pages,
 * resolved product decision 1 — no invite email, no token lifecycle).
 *
 * REQ: New User Initial Password Set By Admin
 *      (openspec/changes/backoffice-missing-pages/specs/user-management/spec.md)
 */

use App\Models\Organization;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

test('an admin-created user with an explicit password can log in immediately, and no email is sent', function (): void {
    Mail::fake();
    Notification::fake();

    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    $this->withToken($token)->postJson('/api/users', [
        'name' => 'Immediate Login',
        'email' => 'immediate-login@example.test',
        'password' => 'a-chosen-password-99',
        'role' => 'operator',
    ])->assertCreated();

    $this->postJson('/api/auth/login', [
        'email' => 'immediate-login@example.test',
        'password' => 'a-chosen-password-99',
    ])->assertOk()->assertJsonStructure(['access_token']);

    Mail::assertNothingSent();
    Notification::assertNothingSent();
});
