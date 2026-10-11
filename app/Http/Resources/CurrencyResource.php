<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A catalogue currency. `rate_per_usd` is a decimal STRING (never a float): the
 * rate has six decimals and a float would round-trip it lossily.
 *
 * Admin-only under T-EXPOSE-001: no public serializer carries these keys.
 *
 * @mixin Currency
 */
final class CurrencyResource extends JsonResource
{
    /**
     * @return array{code: string, symbol: string, decimals: int, rate_per_usd: string|null, rate_as_of: string|null, is_active: bool}
     *
     * @scramble-return array{code: string, symbol: string, decimals: int, rate_per_usd: string|null, rate_as_of: string|null, is_active: bool}
     */
    public function toArray(Request $request): array
    {
        /** @var Currency $currency */
        $currency = $this->resource;

        return [
            'code' => $currency->code,
            'symbol' => $currency->symbol,
            'decimals' => $currency->decimals,
            'rate_per_usd' => $currency->rate_per_usd,
            'rate_as_of' => $currency->rate_as_of?->toDateString(),
            'is_active' => $currency->is_active,
        ];
    }
}
