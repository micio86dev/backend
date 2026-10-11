<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to the only state that is always legal: inactive, unrated. USD and
 * EUR are seeded by the migration, so a factory row uses a code outside them.
 *
 * @extends Factory<Currency>
 */
class CurrencyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('Q??')),
            'symbol' => '¤',
            'decimals' => 2,
            'rate_per_usd' => null,
            'rate_as_of' => null,
            'is_active' => false,
        ];
    }

    /** A rate and its date, still inactive. */
    public function rated(string $ratePerUsd = '0.800000'): static
    {
        return $this->state(['rate_per_usd' => $ratePerUsd, 'rate_as_of' => '2026-10-12']);
    }

    /** Active, which the database only allows with a rate. */
    public function active(): static
    {
        return $this->rated()->state(['is_active' => true]);
    }
}
