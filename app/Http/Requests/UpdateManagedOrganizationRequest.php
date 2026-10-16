<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PATCH /api/admin/organizations/{id} (superadmin only).
 *
 * Only `name`, `primary_color`, `background_color` and `currency_code` are writable here. `slug` is a tenancy
 * identifier and is never editable; the webhook defaults and the logo keep
 * their own tenant-facing endpoints.
 */
class UpdateManagedOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->is_superadmin === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Same rules as the tenant-facing endpoint, so a colour valid in one
        // place is never invalid in the other.
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'primary_color' => ['sometimes', 'nullable', 'string', 'regex:/\A#[0-9a-fA-F]{6}\z/'],
            'background_color' => ['sometimes', 'nullable', 'string', 'regex:/\A#[0-9a-fA-F]{6}\z/'],
            'currency_code' => ['sometimes', 'required', 'string', 'regex:/\A[A-Z]{3}\z/', Rule::exists('currencies', 'code')->where('is_active', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'name_required',
            'name.string' => 'name_invalid',
            'name.max' => 'name_too_long',
            'primary_color.string' => 'primary_color_invalid',
            'primary_color.regex' => 'primary_color_invalid',
            'background_color.string' => 'background_color_invalid',
            'background_color.regex' => 'background_color_invalid',
            'currency_code.required' => 'currency_unavailable',
            'currency_code.string' => 'currency_unavailable',
            'currency_code.regex' => 'currency_unavailable',
            'currency_code.exists' => 'currency_unavailable',
        ];
    }
}
