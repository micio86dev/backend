<?php

declare(strict_types=1);

/**
 * The COMPLETE abilities map, asserted per role.
 *
 * `UserAbilities` exists so the backoffice never writes `roles.includes
 * ('admin')`. That only holds while the map actually publishes every ability a
 * CTA needs: an action whose ability is MISSING from the map cannot be gated
 * from it, and the client falls back to the thing this whole mechanism exists
 * to prevent — a role string parsed client-side, or no check at all and a
 * button that 403s on click.
 *
 * Both had already happened. `avatar-templates` rendered edit/activate for an
 * admin who may do neither (managing templates became platform-only on
 * 2026-09-02) because the map stopped at `viewAny`/`create`; `projects` and
 * `participants` derived `canInvite`/`isViewer` from `profile.data.role`.
 *
 * So this asserts the map WHOLE, one exact expectation per role, rather than
 * spot-checking keys. A new ability that a policy gains and the map does not
 * fails here rather than in a click.
 *
 * The booleans below are not restated policy — they are what the SAME policies
 * answer, and `SettingsSurfaceTest` asserts the endpoints refuse in step.
 */

use App\Models\Organization;

/**
 * Every group/action the map must publish, with the answer each role gets.
 *
 * Read a column, not a row: `admin` is the client's most privileged role and
 * still may not manage avatar templates — that column is what makes the
 * platform/tenant boundary visible in one place.
 *
 * @return array<string, array<string, array<string, bool>>>
 */
function expectedAbilities(): array
{
    return [
        'admin' => [
            'organization' => ['view' => true, 'update' => true],
            'apiClients' => ['viewAny' => true, 'create' => true, 'delete' => true],
            'users' => ['viewAny' => true, 'create' => true, 'update' => true, 'deactivate' => true, 'activate' => true],
            'llmCredentials' => ['viewAny' => true, 'create' => true, 'update' => true, 'delete' => true],
            // Read yes, manage no. This row is the bug that started the sweep.
            'avatarTemplates' => ['viewAny' => true, 'create' => false, 'update' => false, 'activate' => false, 'delete' => false],
            'projects' => ['viewAny' => true, 'create' => true, 'update' => true, 'delete' => true],
            'participants' => ['viewAny' => true, 'create' => true, 'recover' => true],
        ],
        'operator' => [
            'organization' => ['view' => true, 'update' => false],
            'apiClients' => ['viewAny' => false, 'create' => false, 'delete' => false],
            'users' => ['viewAny' => false, 'create' => false, 'update' => false, 'deactivate' => false, 'activate' => false],
            'llmCredentials' => ['viewAny' => false, 'create' => false, 'update' => false, 'delete' => false],
            'avatarTemplates' => ['viewAny' => false, 'create' => false, 'update' => false, 'activate' => false, 'delete' => false],
            // Deletes a project? No. Editing its settings and deleting
            // everything beneath it stopped sharing one permission.
            'projects' => ['viewAny' => true, 'create' => true, 'update' => true, 'delete' => false],
            'participants' => ['viewAny' => true, 'create' => true, 'recover' => true],
        ],
        'viewer' => [
            'organization' => ['view' => true, 'update' => false],
            'apiClients' => ['viewAny' => false, 'create' => false, 'delete' => false],
            'users' => ['viewAny' => false, 'create' => false, 'update' => false, 'deactivate' => false, 'activate' => false],
            'llmCredentials' => ['viewAny' => false, 'create' => false, 'update' => false, 'delete' => false],
            'avatarTemplates' => ['viewAny' => false, 'create' => false, 'update' => false, 'activate' => false, 'delete' => false],
            'projects' => ['viewAny' => true, 'create' => false, 'update' => false, 'delete' => false],
            'participants' => ['viewAny' => true, 'create' => false, 'recover' => false],
        ],
    ];
}

test('the abilities map answers exactly what the policies do, for every role', function (string $role): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, $role);

    $response = $this->withToken($token)->getJson('/api/auth/me');

    $response->assertOk();
    // Whole-map equality rather than assertJsonPath per key: a group the map
    // stops publishing is as broken as one that answers wrongly, and only
    // equality catches the first.
    expect($response->json('abilities'))->toEqual(expectedAbilities()[$role]);
})->with(['admin', 'operator', 'viewer']);

test('the superadmin is told they may manage avatar templates', function (): void {
    // The platform column, which no tenant role occupies. `Gate::before`
    // serves it, so this also asserts the map is resolved THROUGH the gate
    // rather than by reading policy methods directly — a map built from
    // policies alone would answer false here and hide the only UI that can
    // author a template.
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');

    $response = $this->withToken($token)->getJson('/api/auth/me');

    $response->assertOk();
    expect($response->json('abilities.avatarTemplates'))->toEqual([
        'viewAny' => true,
        'create' => true,
        'update' => true,
        'activate' => true,
        'delete' => true,
    ]);
});
