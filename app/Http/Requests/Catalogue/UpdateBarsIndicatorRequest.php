<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use App\Http\Requests\Catalogue\Concerns\ValidatesLocaleMaps;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/catalogue/bars-indicators/{indicator}` (framework-catalogue-
 * authoring PR3). No 4th-indicator check here — editing text/anchors on an
 * EXISTING row never changes the count for its pair. Reassigning
 * `role_id`/`competency_id` on an existing indicator is out of scope for
 * this PR (D3's twin scopes the count check to creation).
 */
class UpdateBarsIndicatorRequest extends FormRequest
{
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
        return [
            ...$this->localeMapRules('text', required: false),
            ...$this->localeMapRules('anchor_5', required: false),
            ...$this->localeMapRules('anchor_3', required: false),
            ...$this->localeMapRules('anchor_1', required: false),
            'position' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }
}
