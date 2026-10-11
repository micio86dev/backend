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
    /** Exit code when the EUR row is missing: nothing was set, and that is not success. */
    public const EXIT_SKIPPED = 2;

    protected $signature = 'beai:sync-currency-rates';

    protected $description = 'Set the confirmed initial EUR rate when none is stored (never overwrites an existing rate).';

    public function handle(CurrencyRatesSeeder $seeder): int
    {
        $outcome = $seeder->run();
        $this->info('EUR rate: '.$outcome);

        return $outcome === CurrencyRatesSeeder::SKIPPED ? self::EXIT_SKIPPED : self::SUCCESS;
    }
}
