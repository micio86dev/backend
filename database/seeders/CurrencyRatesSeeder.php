<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CurrencyRatesSeeder — the owner-confirmed initial EUR rate
 * (organization-currencies, owner decision 2026-10-11, supersedes P6).
 *
 * The migration keeps seeding EUR INACTIVE with no rate, so a fresh database
 * never shows an invented one; this seeder is the one explicit place where the
 * rate is set. Production runs no `db:seed`, so `beai:deploy` invokes it via
 * `beai:sync-currency-rates`.
 *
 * NON-DESTRUCTIVE: it writes ONLY while the stored EUR rate is NULL, in one
 * conditional UPDATE. A rate a superadmin has set or edited (any non-null
 * value) is never overwritten by a deploy. USD is never touched.
 */
class CurrencyRatesSeeder extends Seeder
{
    public const APPLIED = 'applied';

    public const ALREADY_SET = 'already set';

    public const SKIPPED = 'skipped';

    /** ECB euro foreign exchange reference rates, published 2026-10-09: 1 EUR = 1.1206 USD (https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml). */
    private const EUR_RATE_PER_USD = '0.892379';

    private const EUR_RATE_AS_OF = '2026-10-09';

    public function run(): string
    {
        $applied = DB::table('currencies')
            ->where('code', 'EUR')
            ->whereNull('rate_per_usd')
            ->update([
                'rate_per_usd' => self::EUR_RATE_PER_USD,
                'rate_as_of' => self::EUR_RATE_AS_OF,
                'is_active' => true,
                'updated_at' => now(),
            ]);

        if ($applied > 0) {
            return self::APPLIED;
        }

        if (DB::table('currencies')->where('code', 'EUR')->exists()) {
            return self::ALREADY_SET;
        }

        Log::warning('CurrencyRatesSeeder: no EUR row in currencies; skipped.');

        return self::SKIPPED;
    }
}
