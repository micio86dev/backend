<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/admin/platform-users (platform-user-management D2).
 *
 * There is no `role` rule and no `organization_id` rule, and their absence is
 * the point rather than an omission: both are DECIDED by the surface, exactly
 * as `/api/users` decides them for an organization's people. A field that is
 * never read cannot be crafted.
 */
class StorePlatformUserRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            // Unique across EVERY user, not only platform ones: `email` is the
            // login identity for this whole system, and two rows sharing it
            // would make authentication ambiguous.
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
