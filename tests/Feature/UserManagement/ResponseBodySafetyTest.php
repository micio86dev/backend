<?php

declare(strict_types=1);

/**
 * No response from any /api/users* endpoint may ever contain a password or
 * password-hash-like key (backoffice-missing-pages D4).
 *
 * REQ: Passwords Never Returned; Role Cache Cleared On Write
 *      (openspec/changes/backoffice-missing-pages/specs/user-management/spec.md)
 */

use App\Models\Organization;
use App\Models\User;
use Illuminate\Testing\TestResponse;

function assertNoPasswordLeak(TestResponse $response): void
{
    $body = $response->json();
    $flat = json_encode($body);

    expect($flat)->not->toContain('"password"');
    expect($flat)->not->toContain('"password_hash"');

    $data = $body['data'] ?? $body;
    $rows = array_is_list($data) ? $data : [$data];
    foreach ($rows as $row) {
        if (! is_array($row)) {
            continue;
        }
        expect(array_key_exists('password', $row))->toBeFalse();
        expect(array_key_exists('password_hash', $row))->toBeFalse();
    }
}

test('GET /api/users never leaks a password field', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    User::factory()->create(['organization_id' => $org->id]);

    $response = $this->withToken($token)->getJson('/api/users');
    $response->assertOk();
    assertNoPasswordLeak($response);
});

test('POST /api/users never leaks the password it was just given', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    $response = $this->withToken($token)->postJson('/api/users', [
        'name' => 'No Leak',
        'email' => 'no-leak@example.test',
        'password' => 'super-secret-password-1',
        'role' => 'viewer',
    ]);

    $response->assertCreated();
    assertNoPasswordLeak($response);
    expect($response->getContent())->not->toContain('super-secret-password-1');
});

test('PATCH /api/users/{id} never leaks a password field', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    $target = User::factory()->create(['organization_id' => $org->id]);

    $response = $this->withToken($token)->patchJson("/api/users/{$target->id}", ['name' => 'Renamed']);
    $response->assertOk();
    assertNoPasswordLeak($response);
});
