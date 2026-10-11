<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\CurrencyRatesSeeder;
use Illuminate\Console\Command;

/**
 * beai:sync-currency-rates — sets the owner-confirmed EUR rate when none is
 * stored yet. Invoked by `beai:deploy` (production runs no `db:seed`); run it
 * by hand to recover:
 *
 *     php artisan beai:sync-currency-rates
 *
 * Idempotent and non-destructive: see `CurrencyRatesSeeder`.
 */
class SyncCurrencyRatesCommand extends Command
{
    protected $signature = 'beai:sync-currency-rates';

    protected $description = 'Set the confirmed initial EUR rate when none is stored (never overwrites an existing rate).';

    public function handle(CurrencyRatesSeeder $seeder): int
    {
        $this->info('EUR rate: '.$seeder->run());

        return self::SUCCESS;
    }
}
