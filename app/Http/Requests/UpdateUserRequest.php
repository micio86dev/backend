<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrgRole;
use App\Support\Users\UserAdminReader;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateUserRequest (backoffice-missing-pages D4).
 *
 * Validates PATCH /api/users/{id}. The target is resolved through
 * UserAdminReader (D4) — the org filter runs BEFORE authorization, so a
 * cross-org id 404s before any role is even evaluated (mirrors
 * UpdateProjectRequest.php's SubstituteBindings-runs-before-TenantContext
 * discipline).
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $userId = $this->route('user');
        if ($userId === null) {
            return false;
        }

        try {
            $target = app(UserAdminReader::class)->read((int) $userId);
        } catch (ModelNotFoundException) {
            // Cross-org / superadmin id → 404, not 403 (never confirms the
            // row exists to a caller who cannot see it).
            abort(404);
        }

        return $this->user()?->can('update', $target) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = (int) $this->route('user');

        // `sometimes` AND `required` together, matching
        // UpdatePlatformUserRequest: the field is optional, but if it IS sent
        // it must carry a value. `sometimes` alone let an empty string through
        // — a PATCH with `{"name": ""}` cleared the user's name and answered
        // 200, and the four `.required` message keys below could never fire.
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
            'role' => ['sometimes', 'required', 'string', Rule::in(OrgRole::values())],
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
