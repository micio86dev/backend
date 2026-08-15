<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdatePasswordRequest (user-profile-self-service, design D4).
 *
 * Validates PUT /api/profile/password. Its OWN request — the admin
 * `UpdateUserRequest` (`['sometimes','string','min:8']`, no current-password
 * check) MUST NOT be reused for this route.
 *
 * `current_password:api` is not decoration: the rule resolves
 * `auth()->guard($param)`, and the default guard is `env('AUTH_GUARD', 'api')`
 * (config/auth.php:19) — an env change would make the rule read the wrong
 * guard and fail closed on every request. `min:8` deliberately matches the
 * admin path floor — a stricter self-service floor would be theatre.
 *
 * REQ: Password Change Requires The Current Password
 * (openspec/changes/user-profile-self-service/specs/user-self-service/spec.md)
 */
class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:api'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ];
    }
}
