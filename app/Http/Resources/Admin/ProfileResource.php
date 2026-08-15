<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ProfileResource (user-profile-self-service).
 *
 * Serializes the singular, self-resolving /api/profile resource. `role` and
 * `organization` are READ-ONLY here — this resource is returned by `show`
 * and `update`, and neither ever writes them (role changes remain
 * exclusively an admin action on `user-management`; UpdateProfileRequest
 * never declares either field).
 *
 * @mixin User
 */
class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'role' => $user->getRoleNames()->first(),
            'organization' => $user->organization !== null ? [
                'id' => $user->organization->id,
                'name' => $user->organization->name,
            ] : null,
        ];
    }
}
