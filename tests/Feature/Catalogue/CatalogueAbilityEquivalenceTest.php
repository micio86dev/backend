<?php

declare(strict_types=1);

/**
 * RED/GREEN — 10.5 (framework-catalogue-authoring PR3, D12): across
 * superadmin/admin/operator/viewer, `allows('manageCatalogue')` is true
 * IFF a catalogue-write route returns non-403.
 */

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function catalogueAbilityUser(Organization $org, string $role): array
{
    if ($role === 'superadmin') {
        $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

        return ['user' => $user, 'token' => auth('api')->login($user)];
    }

    $user = User::factory()->create(['organization_id' => $org->id]);
    app(TenantResolver::class)->setOrgId($org->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return ['user' => $user, 'token' => auth('api')->login($user)];
}

test('allows(manageCatalogue) is true iff a catalogue-write route returns non-403', function (string $role): void {
    $org = Organization::factory()->create();
    ['user' => $user, 'token' => $token] = catalogueAbilityUser($org, $role);

    $allowed = Gate::forUser($user)->allows('manageCatalogue');

    $response = $this->withToken($token)->postJson('/api/catalogue/roles', [
        'code' => 'EQTEST',
        'name' => ['en' => 'Equivalence Test'],
    ]);

    if ($allowed) {
        expect($response->status())->not->toBe(403);
    } else {
        expect($response->status())->toBe(403);
    }
})->with(['superadmin', 'admin', 'operator', 'viewer']);
