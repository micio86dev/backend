<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `POST /api/auth/reset-password` (self-service-password-reset AD-2).
 *
 * Validation runs BEFORE the token is presented to the broker, so a typo in the
 * new password — a mismatched confirmation, one character short — does NOT burn
 * the single-use token. A locked-out user gets one link; spending it on a typo
 * would send them back to the start.
 *
 * `min:8` matches the floor the admin path (`UpdateUserRequest`) and the
 * self-service path (`UpdatePasswordRequest`) already use. A stricter floor
 * here would be theatre: the same account can be given a shorter password
 * through either of those two routes.
 *
 * `exists:users,email` is absent here for the same reason as in
 * `ForgotPasswordRequest`, though the exposure is smaller — a caller must also
 * hold a valid token. The controller answers unknown-user, deactivated-user and
 * bad-token with ONE generic failure.
 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
