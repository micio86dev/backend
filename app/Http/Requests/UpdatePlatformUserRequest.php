<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PATCH /api/admin/platform-users/{id} (platform-user-management D2).
 *
 * Every field is `sometimes`: a partial update must not blank what it does not
 * mention. `role` and `organization_id` are absent for the same reason as on
 * the store request — they are the surface's to decide, not the caller's.
 */
class UpdatePlatformUserRequest extends FormRequest
{
    /**
     * The superadmin check lives HERE, not only in the controller, and the
     * reason is ordering: a FormRequest validates BEFORE the action runs, so a
     * controller-side check let an unauthorized org admin receive a 422 that
     * enumerated this endpoint's field rules. Authorization must answer first.
     *
     * The controller still asserts it too — the verbs that take no FormRequest
     * have nowhere else to put it, and one surface should not be guarded two
     * different ways depending on which verb you reach it by.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->is_superadmin === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var string $id */
        $id = $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // Ignoring THIS row, or renaming a user without changing their
            // address would fail against their own record.
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
        ];
    }

    /**
     * Machine CODES, never Laravel's prose.
     *
     * A response body is machine-facing: this API has no idea what language
     * the reader speaks, and i18n it/en is binding on error states. Without
     * this a duplicate address came back as "The email has already been
     * taken." and the backoffice rendered it verbatim into an Italian field.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // EVERY declared rule, not the ones that came to mind: an
            // unmapped rule falls back to Laravel's English, which is the
            // whole defect this method exists to end.
            'name.required' => 'name_required',
            'name.string' => 'name_invalid',
            'email.required' => 'email_required',
            'email.email' => 'email_invalid',
            'email.unique' => 'email_taken',
            'email.max' => 'email_too_long',
            'name.max' => 'name_too_long',
            'password.required' => 'password_required',
            'password.string' => 'password_invalid',
            'password.min' => 'password_too_short',
        ];
    }
}
