<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateProfileRequest (user-profile-self-service, design D2/D8).
 *
 * Validates PATCH /api/profile. Declares ONLY `name`, `email`, `locale` —
 * `role`, `organization_id`, `is_superadmin`, `deactivated_at` are NEVER
 * declared here, so a FormRequest alone does not strip them (D2 layer 1);
 * ProfileController's `$request->safe()->only([...])` (D2 layer 2) and
 * User::$fillable (D2 layer 3) are what actually drop them, silently rather
 * than a validated-then-rejected `prohibited` 422 (same discipline as
 * UpdateOrganizationRequest.php's `slug`).
 *
 * REQ: Editable Fields Are An Allow-List; Email Uniqueness On Self-Update
 * (openspec/changes/user-profile-self-service/specs/user-self-service/spec.md)
 */
class UpdateProfileRequest extends FormRequest
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
        /** @var User $user */
        $user = $this->user();

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'locale' => ['sometimes', Rule::in(config('app.supported_locales'))],
        ];
    }
}
