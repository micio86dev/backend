<?php

declare(strict_types=1);

/**
 * Dispatching a job must not strip the tenant context off the REQUEST.
 *
 * `TenancyServiceProvider` resets the tenant resolver and the Spatie team
 * context before every queued job, so HTTP tenancy cannot bleed into a worker.
 * That is right and stays. What was missing was the other half: under the
 * `sync` driver — a supported configuration, and the one the test suite runs —
 * the job executes INSIDE the dispatching request, so the reset landed on the
 * request's own context and nothing put it back.
 *
 * Everything after a `dispatch()` then ran with no tenant and no team,
 * including response serialization. `POST /api/users` created the user, set the
 * role, dispatched the invitation, and returned `"role": null` — correct in the
 * database, wrong on the wire, and silent.
 *
 * Asserted end to end rather than on the provider, because what broke was the
 * INTERACTION between a queue hook and a response renderer, and a unit test of
 * either half would have stayed green throughout.
 */

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

test('the response still knows the tenant after a job has been dispatched inside it', function (): void {
    $org = Organization::factory()->create();
    $admin = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

    foreach (['admin', 'operator', 'viewer'] as $name) {
        SpatieRole::firstOrCreate(['name' => $name, 'guard_name' => 'api', 'team_id' => $org->id]);
    }

    $admin->assignRole(SpatieRole::where('name', 'admin')->where('team_id', $org->id)->firstOrFail());
    app(TenantResolver::class)->setOrgId($org->id);
    $token = auth('api')->login($admin);

    $response = $this->withToken($token)->postJson('/api/users', [
        'name' => 'Grace Hopper',
        'email' => 'grace-queue@example.test',
        'password' => 'a-very-long-temporary-password',
        'role' => 'operator',
    ]);

    $response->assertCreated();

    // The whole point. `role` is rendered by `getRoleNames()`, which reads the
    // Spatie team context — the exact thing the queue hook had nulled.
    $response->assertJsonPath('data.role', 'operator');
});

test('a job that THROWS still gives the context back', function (): void {
    // The case an `after`-only hook silently misses, and the one where leaving
    // a request half-scoped does the most damage.
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

    try {
        dispatch(function (): void {
            throw new RuntimeException('this job fails');
        });
    } catch (Throwable) {
        // The sync driver rethrows. What matters is the state afterwards.
    }

    expect(app(TenantResolver::class)->getOrgId())->toBe($org->id)
        ->and(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($org->id);
});

test('the job ITSELF still runs with no inherited tenant', function (): void {
    // The reset is not weakened — a job must re-establish tenancy from its own
    // aggregate root, never inherit whatever the dispatching request had.
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

    // Recorded through the CONTAINER, not through a `use (&$ref)`. A queued
    // closure is serialized even by the sync driver, and a by-reference
    // binding does not survive that — the closure ran and the assertion read
    // the untouched local, which looks exactly like the closure never running.
    app()->instance('queue-context-probe', new stdClass);

    dispatch(function (): void {
        $probe = app('queue-context-probe');
        $probe->orgId = app(TenantResolver::class)->getOrgId();
        $probe->teamId = app(PermissionRegistrar::class)->getPermissionsTeamId();
        $probe->ran = true;
    });

    $probe = app('queue-context-probe');

    expect($probe->ran ?? false)->toBeTrue('the job did not run at all')
        ->and($probe->orgId)->toBeNull()
        ->and($probe->teamId)->toBeNull();

    // ...and the request got its own context back.
    expect(app(TenantResolver::class)->getOrgId())->toBe($org->id);
});
