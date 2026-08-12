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
            'role' => $user->getRoleNames()->first(),
            'is_deactivated' => $user->isDeactivated(),
            'created_at' => $user->created_at?->toISOString(),
            'updated_at' => $user->updated_at?->toISOString(),
        ];
    }
}
