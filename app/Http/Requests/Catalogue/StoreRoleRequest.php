<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use App\Http\Requests\Catalogue\Concerns\ResolvesOpenDraftRevision;
use App\Http\Requests\Catalogue\Concerns\ValidatesLocaleMaps;
use App\Models\Role;
use App\Models\User;
use App\Support\Catalogue\CatalogueRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/catalogue/roles` (framework-catalogue-authoring PR3, D3/D12).
 *
 * The five roles are a closed set (ICO/FLL/MLL/BUL/SRX) — `catalogue-
 * authoring` spec: "Creating a sixth role MUST be rejected". `authorize()`
 * still repeats the superadmin check (`StorePlatformUserRequest`'s own
 * precedent): a FormRequest validates BEFORE the controller runs, so an
 * unauthorized caller must get 403 before 422 enumerates this endpoint's
 * field rules.
 */
class StoreRoleRequest extends FormRequest
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
        $draftId = $this->openDraftRevisionId();

        $existingRoleCount = Role::where('revision_id', $draftId)->count();

        return [
            ...$this->localeMapRules('name'),
            ...$this->localeMapRules('responsibilities', required: false),
            'code' => [
                'required', 'string', 'max:16', 'regex:/^[A-Z0-9_]+$/',
                Rule::unique('framework_roles', 'code')->where('revision_id', $draftId),
                // The sixth-role refusal. Evaluated per-request rather than
                // relying on the DB partial-unique-style trick roles don't
                // have (there is no "at most 5" constraint at the DB layer —
                // the closed set is a FormRequest/publish-sweep rule, not a
                // schema one): a request that ALSO fails uniqueness above
                // never reaches this bare count check meaningfully, but a
                // wholly new sixth code does.
                function (string $attribute, mixed $value, \Closure $fail) use ($existingRoleCount): void {
                    if ($existingRoleCount >= CatalogueRules::MAX_ROLES) {
                        $fail('framework_roles_closed_set');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'code_required',
            'code.regex' => 'code_invalid',
            'code.unique' => 'code_taken',
        ];
    }
}
