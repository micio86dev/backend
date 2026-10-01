<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Internal notes, not published (Scramble exports a request class docblock as the schema description):
// Validates `POST /api/auth/forgot-password` (self-service-password-reset AD-3).
//
// `exists:users,email` is DELIBERATELY ABSENT and must never be added. It would
// make the validator itself the account-enumeration oracle this whole flow is
// built to avoid — a 422 for "unknown" and a 202 for "known" is a cleaner
// signal than any timing difference.
//
// Only the FORMAT is validated. That is safe: it says something about the
// string the caller typed, never about whether an account exists behind it.
/**
 * The body of `POST /api/auth/forgot-password`. Only the format of the email address is
 * validated, so the response never reveals whether an account exists.
 */
class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public by definition: the caller cannot log in — that is why they
        // are here.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
