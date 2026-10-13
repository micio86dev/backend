<?php

declare(strict_types=1);

use App\Models\Currency;
use Database\Seeders\CurrencyRatesSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class DeployStepRecorder
{
    /** @var list<string> */
    public static array $order = [];
}

final class RecordingCurrencyRatesStub extends Command
{
    protected $signature = 'beai:sync-currency-rates';

    protected $description = 'Stub that records invocation.';

    public function handle(): int
    {
        DeployStepRecorder::$order[] = 'currency-rates';

        return self::SUCCESS;
    }
}

final class OrderedRegistryStub extends Command
{
    protected $signature = 'beai:sync-llm-registry';

    protected $description = 'Stub that records order.';

    public function handle(): int
    {
        DeployStepRecorder::$order[] = 'registry';

        return self::SUCCESS;
    }
}

final class FailingCurrencyRatesStub extends Command
{
    protected $signature = 'beai:sync-currency-rates';

    protected $description = 'Stub that always fails.';

    public function handle(): int
    {
        return self::FAILURE;
    }
}

final class ThrowingCurrencyRatesStub extends Command
{
    protected $signature = 'beai:sync-currency-rates';

    protected $description = 'Stub that always throws.';

    public function handle(): int
    {
        throw new RuntimeException('SQLSTATE[08006]: connection refused');
    }
}

it('applies the confirmed ECB rate to a fresh EUR and leaves USD alone', function (): void {
    $usdBefore = DB::table('currencies')->where('code', 'USD')->first();

    expect((new CurrencyRatesSeeder)->run())->toBe(CurrencyRatesSeeder::APPLIED);

    $eur = Currency::query()->findOrFail('EUR');
    expect($eur->is_active)->toBeTrue()
        ->and($eur->rate_per_usd)->toBe('0.892379')
        ->and($eur->rate_as_of->toDateString())->toBe('2026-10-09');

    expect(DB::table('currencies')->where('code', 'USD')->first())->toEqual($usdBefore);
});

it('is a no-op on the second run', function (): void {
    (new CurrencyRatesSeeder)->run();
    $before = DB::table('currencies')->orderBy('code')->get()->toArray();

    expect((new CurrencyRatesSeeder)->run())->toBe(CurrencyRatesSeeder::ALREADY_SET);
    expect(DB::table('currencies')->orderBy('code')->get()->toArray())->toEqual($before);
});

it('never overwrites a rate the superadmin set', function (): void {
    DB::table('currencies')->where('code', 'EUR')->update(['rate_per_usd' => '0.930000', 'rate_as_of' => '2026-11-01', 'is_active' => false]);

    expect((new CurrencyRatesSeeder)->run())->toBe(CurrencyRatesSeeder::ALREADY_SET);

    $eur = Currency::query()->findOrFail('EUR');
    expect($eur->rate_per_usd)->toBe('0.930000')
        ->and($eur->rate_as_of->toDateString())->toBe('2026-11-01')
        ->and($eur->is_active)->toBeFalse();
});

it('skips when the EUR row is missing', function (): void {
    DB::table('currencies')->where('code', 'EUR')->delete();

    expect((new CurrencyRatesSeeder)->run())->toBe(CurrencyRatesSeeder::SKIPPED);
    expect(DB::table('currencies')->count())->toBe(1);
});

it('reports what happened from the artisan command', function (): void {
    $this->artisan('beai:sync-currency-rates')->expectsOutputToContain('applied')->assertSuccessful();
    $this->artisan('beai:sync-currency-rates')->expectsOutputToContain('already set')->assertSuccessful();

    DB::table('currencies')->where('code', 'EUR')->delete();
    $this->artisan('beai:sync-currency-rates')->expectsOutputToContain('skipped')->assertExitCode(2);
});

it('reports a skipped rate truthfully from beai:deploy without aborting it', function (): void {
    DB::table('currencies')->where('code', 'EUR')->delete();

    // Read the real buffer: doesntExpectOutputToContain can miss text written through warn()/error().
    $exit = Artisan::call('beai:deploy');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('[deploy] WARNING: currency rates skipped')
        ->toContain('[deploy] done')
        ->not->toContain('[deploy] currency rates OK');
});

it('runs from beai:deploy after the registry sync', function (): void {
    DeployStepRecorder::$order = [];
    app(Kernel::class)->registerCommand(new OrderedRegistryStub);
    app(Kernel::class)->registerCommand(new RecordingCurrencyRatesStub);

    $this->artisan('beai:deploy')->assertSuccessful();

    expect(DeployStepRecorder::$order)->toBe(['registry', 'currency-rates']);
});

it('does not abort the deploy when the rate step fails or throws', function (Command $stub): void {
    app(Kernel::class)->registerCommand($stub);

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('[deploy] WARNING: currency rates failed')
        ->expectsOutputToContain('[deploy] done')
        ->assertSuccessful();
})->with([
    'failure' => fn () => new FailingCurrencyRatesStub,
    'exception' => fn () => new ThrowingCurrencyRatesStub,
]);
