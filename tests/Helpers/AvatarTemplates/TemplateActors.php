<?php

declare(strict_types=1);

namespace Tests\Helpers\AvatarTemplates;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\ActingOrganization;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bearer tokens for the principals the avatar-template tenancy tests exercise.
 *
 * `admin`, `operator` and `viewer` are members of `$org`; `acting` is a
 * superadmin acting as `$org`; `bare` is a superadmin with no organization
 * (bypass on).
 */
final class TemplateActors
{
    public const MEMBERS = ['admin', 'operator', 'viewer'];

    public static function token(string $actor, Organization $org): string
    {
        if (in_array($actor, self::MEMBERS, true)) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
            $user->assignRole(SpatieRole::firstOrCreate(['name' => $actor, 'guard_name' => 'api', 'team_id' => $org->id]));

            return auth('api')->login($user);
        }

        $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

        if ($actor === 'acting') {
            app(ActingOrganization::class)->set((int) $user->id, $org->id);
        }

        return auth('api')->login($user);
    }
}
