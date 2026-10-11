<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'symbol' => ['sometimes', 'string', 'max:8'],
            'decimals' => ['sometimes', 'integer', 'between:0,4'],
            /**
             * Canonical form is a decimal string (up to six decimals, as `CurrencyResource` returns it);
             * a JSON number is also accepted.
             *
             * @var string|float
             */
            'rate_per_usd' => ['required_with:rate_as_of', 'numeric', 'decimal:0,6', 'gt:0', 'lt:1000000'],
            'rate_as_of' => ['required_with:rate_per_usd', 'date_format:Y-m-d', 'before_or_equal:today'],
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
     * `is_active` arrives in every form the `boolean` rule accepts (`0`, `"0"`, `false`, ...); the
     * controller decides on the value, so it is cast once here, before validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('is_active') && in_array($this->input('is_active'), [0, 1, '0', '1'], true)) {
            $this->merge(['is_active' => (bool) $this->input('is_active')]);
        }
    }

    /**
     * `code` is refused here, not by a `prohibited` rule: that rule would list `code` as a
     * writable property in the generated schema (the path parameter carries the code). One rule,
     * two encodings for activation: the rate may already be stored, whereas
     * {@see StoreCurrencyRequest::after()} requires it in the body.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->has('code')) {
                $validator->errors()->add('code', StoreCurrencyRequest::MESSAGES['code.prohibited']);
            }
            if (! $this->boolean('is_active') || $this->has('rate_per_usd')) {
                return;
            }

            // An unknown code is the controller's 404, not a 422 about a rate it never had.
            $row = Currency::query()->whereKey($this->route('code'))->first(['rate_per_usd']);
            if ($row !== null && $row->rate_per_usd === null) {
                $validator->errors()->add('is_active', 'currency_activation_requires_rate');
            }
        }];
    }
}
