<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\User;
use App\Support\ProfilePhotoUrlSigner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ProfileResource (user-profile-self-service; photo_url added by
 * user-avatar-image, design D4/D9).
 *
 * Serializes the singular, self-resolving /api/profile resource. `role` and
 * `organization` are READ-ONLY here — this resource is returned by `show`
 * and `update`, and neither ever writes them (role changes remain
 * exclusively an admin action on `user-management`; UpdateProfileRequest
 * never declares either field). `photo_url` is READ-ONLY for the same
 * reason and additionally NEVER derived from request input — it is always
 * `ProfilePhotoUrlSigner::urlFor($user->profile_photo_path)`, so a caller
 * cannot influence which object gets presigned (design D2).
 *
 * design D9 — the Scramble local-assignment defect: `/** @var User $user *\/`
 * on the local `$user` assignment below is NOT honoured by Scramble when it
 * statically walks this method body, so every `$user->x` fetch degrades to
 * its inferred default (`role`/`locale`/`organization` all generated as
 * `unknown` — the reason `profile.vue:44-46` already coerces `role` with
 * `String(...)`). `@scramble-return` is the package-specific override tag
 * that replaces the inferred return type outright; `@return` is kept
 * alongside it for PHPStan/IDE tooling. Both declarative, zero runtime
 * change. Cost, stated: the OpenAPI diff also re-types `role`, `locale` and
 * `organization` more precisely than before — accepted, not a surprise.
 *
 * @mixin User
 */
class ProfileResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, email: string, locale: string|null, role: string|null, organization: array{id: int, name: string}|null, photo_url: string|null}
     *
     * @scramble-return array{id: int, name: string, email: string, locale: string|null, role: string|null, organization: array{id: int, name: string}|null, photo_url: string|null}
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
            // Explicit boolean, never an absent key. A UI that branches on
            // `undefined` treats "not sent" and "not a superadmin" the same,
            // and would start showing the client switcher the day this field
            // is renamed.
            'is_superadmin' => (bool) $user->is_superadmin,
            'organization' => $user->organization !== null ? [
                'id' => $user->organization->id,
                'name' => $user->organization->name,
            ] : null,
            'photo_url' => app(ProfilePhotoUrlSigner::class)->urlFor($user->profile_photo_path),
        ];
    }
}
