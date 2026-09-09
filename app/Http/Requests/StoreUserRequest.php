<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrgRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreUserRequest (backoffice-missing-pages D4).
 *
 * Validates POST /api/users. `organization_id` and `is_superadmin` are
 * deliberately NOT rules here — they are never read from the request at
 * all (the controller only ever passes $request->safe()->only([...]) the
 * four whitelisted fields to User::create()), so a crafted value in either
 * key is ignored, never validated-then-rejected.
 *
 * `role` validates against the code-level OrgRole::values() allow-list —
 * NEVER `Rule::exists('roles', 'name')`, which would make the assignable
 * set DATA (any seeder/migration/future feature that inserts a `roles` row
 * would instantly make it grantable, and it would also accept another
 * tenant's role name). `role_code` is never a rule here at all: it is
 * ignored, not validated-then-rejected — this surface governs authorization
 * roles only, never the BEAI organizational role_code.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in(OrgRole::values())],
        ];
    }

    /**
     * Machine CODES, never Laravel's prose — the same contract the platform
     * requests carry, and for the same reason: a response body is
     * machine-facing, and the backoffice is the only layer that knows the
     * operator's language.
     *
     * EVERY declared rule, including `string` and the role allow-list. An
     * unmapped rule falls straight back to English, which is the defect this
     * method exists to end.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'name_required',
            'name.string' => 'name_invalid',
            'name.max' => 'name_too_long',
            'email.required' => 'email_required',
            'email.email' => 'email_invalid',
            'email.unique' => 'email_taken',
            'email.max' => 'email_too_long',
            'password.required' => 'password_required',
            'password.string' => 'password_invalid',
            'password.min' => 'password_too_short',
            'role.required' => 'role_required',
            'role.string' => 'role_invalid',
            'role.in' => 'role_invalid',
        ];
    }
}
