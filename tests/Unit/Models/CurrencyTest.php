<?php

declare(strict_types=1);

use App\Models\Currency;
use App\Models\Organization;

it('uses the code as a non-incrementing string primary key', function (): void {
    $usd = Currency::findOrFail('USD');

    expect($usd->getKeyName())->toBe('code')
        ->and($usd->incrementing)->toBeFalse()
        ->and($usd->getKeyType())->toBe('string')
        ->and($usd->getKey())->toBe('USD');
});

it('casts the rate to a six-decimal string, the date to a date and the flag to a bool', function (): void {
    $usd = Currency::findOrFail('USD');

    expect($usd->rate_per_usd)->toBe('1.000000')
        ->and($usd->rate_as_of)->toBeInstanceOf(DateTimeInterface::class)
        ->and($usd->is_active)->toBeTrue()
        ->and($usd->decimals)->toBe(2);
});

it('scopes to active currencies', function (): void {
    expect(Currency::active()->pluck('code')->all())->toBe(['USD']);

    $gbp = Currency::factory()->active()->create(['code' => 'GBP']);
    Currency::factory()->rated()->create(['code' => 'CHF']);

    expect(Currency::active()->orderBy('code')->pluck('code')->all())->toBe(['GBP', 'USD'])
        ->and($gbp->refresh()->rate_per_usd)->toBe('0.800000');
});

it('resolves from an organization', function (): void {
    $org = Organization::factory()->create();

    expect($org->refresh()->currency)->toBeInstanceOf(Currency::class)
        ->and($org->currency->code)->toBe('USD');
});
