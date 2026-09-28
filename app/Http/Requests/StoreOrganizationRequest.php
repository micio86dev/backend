<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/admin/organizations (superadmin only).
 *
 * The superadmin check lives here so authorization answers before validation,
 * exactly as `StorePlatformUserRequest` does: otherwise a non-superadmin would
 * get a 422 enumerating this endpoint's rules.
 */
class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->is_superadmin === true;
    }

    /**
     * The slug is derived from the name when omitted, so the uniqueness rule
     * must see the derived value too.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('slug') && is_string($this->input('name'))) {
            $this->merge(['slug' => Str::slug($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', Rule::unique('organizations', 'slug')],
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
            'slug.required' => 'slug_invalid',
            'slug.string' => 'slug_invalid',
            'slug.max' => 'slug_too_long',
            'slug.regex' => 'slug_invalid',
            'slug.unique' => 'slug_taken',
        ];
    }
}
