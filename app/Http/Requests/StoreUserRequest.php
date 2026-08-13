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
}
