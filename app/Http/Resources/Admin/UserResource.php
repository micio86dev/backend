<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserResource (backoffice-missing-pages D4).
 *
 * `role` is the single Spatie AUTHORIZATION role — never `role_code` (the
 * unrelated BEAI organizational role). `password`/`password_hash` are never
 * read here at all: User::$hidden already excludes `password` from any
 * array/JSON cast of the model, and this resource never references it.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * `role` is a union closed by `App\Enums\OrgRole` (`admin`|`operator`|`viewer`),
     * validated via `Rule::in(OrgRole::values())` and resolved through
     * `assignRole()`'s `firstOrFail()` — an unlisted role cannot be assigned.
     * `null` covers `getRoleNames()->first()` for a role-less user. `id` is
     * backed by an explicit `(int)` cast (design.md D1 — pdo_pgsql bigint).
     *
     * @return array{id: int, name: string, email: string, role: 'admin'|'operator'|'viewer'|null, is_deactivated: bool, created_at: string|null, updated_at: string|null}
     *
     * @scramble-return array{id: int, name: string, email: string, role: 'admin'|'operator'|'viewer'|null, is_deactivated: bool, created_at: string|null, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        /** @var 'admin'|'operator'|'viewer'|null $role */
        $role = $user->getRoleNames()->first();

        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role,
            'is_deactivated' => $user->isDeactivated(),
            'created_at' => $user->created_at?->toISOString(),
            'updated_at' => $user->updated_at?->toISOString(),
        ];
    }
}
