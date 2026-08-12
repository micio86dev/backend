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

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['sometimes', 'string', 'min:8'],
            'role' => ['sometimes', 'string', Rule::in(OrgRole::values())],
        ];
    }
}
