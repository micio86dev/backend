<?php

declare(strict_types=1);

/**
 * Superadmin fixtures, shared by every file under `tests/Feature/Superadmin/`.
 *
 * In `tests/Helpers/` and registered in `composer.json`'s
 * `autoload-dev.files`, which is the repo's stated rule for a helper used by
 * more than one test file. The rationale is ParaTest: it distributes TEST
 * FILES across workers, so a helper declared inside one is undefined in any
 * worker that did not receive that file.
 *
 * These were declared inside `ActingOrganizationTest.php` and called from
 * `ClientOverviewTest.php` as well — the shape the rule exists to prevent.
 * The full suite stayed green because both files happened to load together,
 * while running the newer file on its own died on "Call to undefined function
 * saProject()", which quietly turned a mutation check into a false positive.
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantContextScope;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * A superadmin and a JWT for them.
 *
 * @return array{user: User, token: string}
 */
function saSuperadmin(): array
{
    // No organization, and that is what makes them one: TenantContext grants
    // bypass ONLY for a null org WITH the flag, and fails closed otherwise.
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    return ['user' => $user, 'token' => auth('api')->login($user)];
}

/**
 * An ordinary admin of one organization, and a JWT for them.
 *
 * @return array{user: User, token: string}
 */
function saOrgAdmin(Organization $org): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return ['user' => $user, 'token' => auth('api')->login($user)];
}

function saProject(Organization $org, string $slug): Project
{
    return TenantContextScope::runFor($org->id, function () use ($org, $slug): Project {
        $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

        return Project::factory()->create([
            'organization_id' => $org->id,
            'framework_version_id' => $fv->id,
            'slug' => $slug,
        ]);
    });
}
