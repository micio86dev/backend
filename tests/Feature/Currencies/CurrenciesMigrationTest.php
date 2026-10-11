<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** @param array<string, mixed> $overrides */
function currencyRow(array $overrides = []): array
{
    return array_merge([
        'code' => 'GBP',
        'symbol' => '£',
        'decimals' => 2,
        'rate_per_usd' => '0.800000',
        'rate_as_of' => '2026-10-12',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/**
 * Assert the statement is refused by the named constraint. Runs in a savepoint
 * (nested transaction) because Postgres aborts the surrounding test
 * transaction on the first constraint violation.
 */
function expectRefusedBy(string $constraint, Closure $statement): void
{
    try {
        DB::transaction($statement);
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain($constraint);

        return;
    }

    test()->fail("Expected {$constraint} to refuse the statement.");
}

/** @param array<string, mixed> $overrides */
function expectCheckRefuses(string $constraint, array $overrides): void
{
    expectRefusedBy($constraint, fn () => DB::table('currencies')->insert(currencyRow($overrides)));
}

it('seeds USD active at rate 1 and EUR inactive with no rate', function (): void {
    $usd = DB::table('currencies')->where('code', 'USD')->first();
    expect($usd->is_active)->toBeTrue()
        ->and((float) $usd->rate_per_usd)->toBe(1.0)
        ->and($usd->decimals)->toBe(2)
        ->and($usd->symbol)->toBe('$');

    $eur = DB::table('currencies')->where('code', 'EUR')->first();
    expect($eur->is_active)->toBeFalse()
        ->and($eur->rate_per_usd)->toBeNull()
        ->and($eur->rate_as_of)->toBeNull()
        ->and($eur->decimals)->toBe(2)
        ->and($eur->symbol)->toBe('€');

    expect(DB::table('currencies')->count())->toBe(2);
});

it('refuses a code that is not three uppercase letters', function (): void {
    expectCheckRefuses('currencies_code_iso', ['code' => 'gbp']);
});

it('refuses decimals outside 0..4', function (): void {
    expectCheckRefuses('currencies_decimals', ['decimals' => 5]);
});

it('refuses a zero or out-of-range rate', function (): void {
    expectCheckRefuses('currencies_rate_range', ['rate_per_usd' => '0']);
    expectCheckRefuses('currencies_rate_range', ['rate_per_usd' => '1000000']);
});

it('refuses a rate without a date', function (): void {
    expectCheckRefuses('currencies_rate_pair', ['rate_as_of' => null]);
});

it('refuses an active currency without a rate', function (): void {
    expectCheckRefuses('currencies_active_rated', ['rate_per_usd' => null, 'rate_as_of' => null, 'is_active' => true]);
});

it('refuses a USD rate other than 1 and an inactive USD', function (): void {
    expectRefusedBy('currencies_usd_anchor', fn () => DB::table('currencies')->where('code', 'USD')->update(['rate_per_usd' => '2']));
    expectRefusedBy('currencies_usd_anchor', fn () => DB::table('currencies')->where('code', 'USD')->update(['is_active' => false]));
});

it('refuses an organization with a currency that is not in the catalogue', function (): void {
    $org = Organization::factory()->create();

    expectRefusedBy('organizations_currency_code_foreign', fn () => DB::table('organizations')->where('id', $org->id)->update(['currency_code' => 'XYZ']));
});

it('refuses deleting a currency an organization uses', function (): void {
    Organization::factory()->create();

    expectRefusedBy('organizations_currency_code_foreign', fn () => DB::table('currencies')->where('code', 'USD')->delete());
});

it('reads USD for organizations created without a currency', function (): void {
    $org = Organization::factory()->create();

    expect(DB::table('organizations')->where('id', $org->id)->value('currency_code'))->toBe('USD');
});

it('drops the column and the table on rollback and restores them on up', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_12_100000_create_currencies_and_organization_currency.php');

    $migration->down();
    expect(Schema::hasTable('currencies'))->toBeFalse()
        ->and(Schema::hasColumn('organizations', 'currency_code'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('currencies'))->toBeTrue()
        ->and(Schema::hasColumn('organizations', 'currency_code'))->toBeTrue();
});
