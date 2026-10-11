<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of `POST /api/admin/currencies` (superadmin only).
 *
 * Error codes are machine slugs, not sentences (the app that renders them owns
 * the language). A rate and its date travel together; activation needs a rate.
 */
class StoreCurrencyRequest extends FormRequest
{
    /** Machine codes, shared with {@see UpdateCurrencyRequest}. */
    public const MESSAGES = [
        'code.required' => 'currency_code_invalid',
        'code.string' => 'currency_code_invalid',
        'code.regex' => 'currency_code_invalid',
        'code.unique' => 'currency_code_taken',
        'code.prohibited' => 'currency_code_immutable',
        'symbol.required' => 'currency_symbol_invalid',
        'symbol.string' => 'currency_symbol_invalid',
        'symbol.max' => 'currency_symbol_invalid',
        'decimals.required' => 'currency_decimals_invalid',
        'decimals.integer' => 'currency_decimals_invalid',
        'decimals.between' => 'currency_decimals_invalid',
        'rate_per_usd.required_with' => 'currency_rate_invalid',
        'rate_per_usd.numeric' => 'currency_rate_invalid',
        'rate_per_usd.decimal' => 'currency_rate_invalid',
        'rate_per_usd.gt' => 'currency_rate_invalid',
        'rate_per_usd.lt' => 'currency_rate_invalid',
        'rate_as_of.required_with' => 'currency_rate_as_of_invalid',
        'rate_as_of.date_format' => 'currency_rate_as_of_invalid',
        'rate_as_of.before_or_equal' => 'currency_rate_as_of_invalid',
        'is_active.boolean' => 'currency_active_invalid',
    ];

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
            'code' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/', 'unique:currencies,code'],
            'symbol' => ['required', 'string', 'max:8'],
            'decimals' => ['required', 'integer', 'between:0,4'],
            /**
             * Canonical form is a decimal string (up to six decimals, as `CurrencyResource` returns it);
             * a JSON number is also accepted. Required together with `rate_as_of`; activating
             * (`is_active: true`) requires a rate.
             *
             * @var string|float|null
             */
            'rate_per_usd' => ['nullable', 'required_with:rate_as_of', 'numeric', 'decimal:0,6', 'gt:0', 'lt:1000000'],
            'rate_as_of' => ['nullable', 'required_with:rate_per_usd', 'date_format:Y-m-d', 'before_or_equal:today'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::MESSAGES;
    }

    /**
     * One rule, two encodings: activation needs a rate. Here the rate must be in the body;
     * {@see UpdateCurrencyRequest::after()} may also accept the rate already stored.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function ($validator): void {
            if ($this->boolean('is_active') && $this->input('rate_per_usd') === null) {
                $validator->errors()->add('is_active', 'currency_activation_requires_rate');
            }
        }];
    }
}
