<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of BEAI's own people (platform-user-management D2).
 *
 * Deliberately NOT `UserResource`. That one publishes a `role`
 * (`admin`|`operator`|`viewer`|null) because an organization's user has one;
 * a platform user does not, and there is no second platform role to pick
 * between — `is_superadmin` is the only platform identity the system has and
 * `Gate::before` grants such a user every ability.
 *
 * A `role` field here would therefore be a control with one option, and a lie
 * the moment somebody read it as an organization role. `organization_id` is
 * absent for the same reason: it is null on every row this resource can ever
 * describe, so publishing it would invite a consumer to branch on it.
 *
 * @mixin User
 */
class PlatformUserResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, email: string, is_deactivated: bool, created_at: string|null, updated_at: string|null}
     *
     * @scramble-return array{id: int, name: string, email: string, is_deactivated: bool, created_at: string|null, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_deactivated' => $user->isDeactivated(),
            'created_at' => $user->created_at?->toISOString(),
            'updated_at' => $user->updated_at?->toISOString(),
        ];
    }
}
