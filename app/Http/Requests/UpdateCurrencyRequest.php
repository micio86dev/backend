<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Currency;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of `PATCH /api/admin/currencies/{code}` (superadmin only).
 *
 * The code is the primary key and never changes: sending it is refused. A
 * rate is never cleared once set, and a rate and its date travel together.
 */
class UpdateCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_superadmin === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['prohibited'],
            'symbol' => ['sometimes', 'string', 'max:8'],
            'decimals' => ['sometimes', 'integer', 'between:0,4'],
            'rate_per_usd' => ['sometimes', 'required_with:rate_as_of', 'numeric', 'decimal:0,6', 'gt:0', 'lt:1000000'],
            'rate_as_of' => ['sometimes', 'required_with:rate_per_usd', 'date_format:Y-m-d', 'before_or_equal:today'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return StoreCurrencyRequest::MESSAGES;
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function ($validator): void {
            if (! $this->boolean('is_active') || $this->has('rate_per_usd')) {
                return;
            }

            $current = Currency::query()->whereKey($this->route('code'))->value('rate_per_usd');
            if ($current === null) {
                $validator->errors()->add('is_active', 'currency_activation_requires_rate');
            }
        }];
    }
}
