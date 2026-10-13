<?php

declare(strict_types=1);

/**
 * `UserAbilities::forModel()` answers per-record abilities with the policy's own
 * verdict, so a client never re-derives them from a role name. A project may be
 * edited by an operator but only deleted by an admin: that difference must show.
 */

use App\Models\Organization;
use App\Models\Project;
use App\Support\Authorization\UserAbilities;
use App\Support\Tenancy\TenantContextScope;

test('each ability is answered by the policy for that user and record', function (string $role, array $expected): void {
    $org = Organization::factory()->create();
    ['user' => $user] = authUserAndTokenForRole($org, $role);
    $project = TenantContextScope::runFor($org->id, fn () => Project::factory()->create(['organization_id' => $org->id]));

    $answers = app(UserAbilities::class)->forModel($user, $project, ['view', 'update', 'delete']);

    expect($answers)->toBe($expected);
})->with([
    'admin' => ['admin', ['view' => true, 'update' => true, 'delete' => true]],
    'operator' => ['operator', ['view' => true, 'update' => true, 'delete' => false]],
    'viewer' => ['viewer', ['view' => true, 'update' => false, 'delete' => false]],
]);

test('an ability the policy does not define is simply false, and no ability asked for means an empty map', function (): void {
    $org = Organization::factory()->create();
    ['user' => $user] = authUserAndTokenForRole($org, 'admin');
    $project = TenantContextScope::runFor($org->id, fn () => Project::factory()->create(['organization_id' => $org->id]));
    $abilities = app(UserAbilities::class);

    expect($abilities->forModel($user, $project, ['teleport']))->toBe(['teleport' => false])
        ->and($abilities->forModel($user, $project, []))->toBe([]);
});
