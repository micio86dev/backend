<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use App\Http\Requests\Catalogue\Concerns\ResolvesOpenDraftRevision;
use App\Http\Requests\Catalogue\Concerns\ValidatesLocaleMaps;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/catalogue/roles/{role}` (framework-catalogue-authoring PR3).
 * No sixth-role check here — that only applies to creating a NEW role;
 * renaming an existing one never changes the count.
 */
class UpdateRoleRequest extends FormRequest
{
    use ResolvesOpenDraftRevision;
    use ValidatesLocaleMaps;

    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->is_superadmin === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Read-only: an UPDATE never opens a new draft — see
        // `existingOpenDraftRevisionId()`'s own docblock. `null` scopes the
        // uniqueness rule to a revision_id that matches no row, which is
        // harmless: the controller's own `findOrFail` 404s regardless when
        // no draft is open, since there is nothing this request could be
        // updating.
        $draftId = $this->existingOpenDraftRevisionId();
        $roleId = (int) $this->route('role');

        return [
            ...$this->localeMapRules('name', required: false),
            ...$this->localeMapRules('responsibilities', required: false),
            'code' => [
                'sometimes', 'required', 'string', 'max:16', 'regex:/^[A-Z0-9_]+$/',
                Rule::unique('framework_roles', 'code')->where('revision_id', $draftId)->ignore($roleId),
            ],
        ];
    }
}
