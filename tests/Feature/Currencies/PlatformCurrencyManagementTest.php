<?php

declare(strict_types=1);

use App\Models\Currency;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/** @return array<string, mixed> */
function gbpPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'GBP',
        'symbol' => '£',
        'decimals' => 2,
        'rate_per_usd' => '0.790000',
        'rate_as_of' => '2026-10-10',
        'is_active' => true,
    ], $overrides);
}

/** @return list<object> */
function currencyAudit(string $action): array
{
    return DB::table('audit_logs')->where('action', $action)->get()->all();
}

test('a superadmin lists every row, inactive ones included', function (): void {
    ['token' => $token] = saSuperadmin();

    $codes = collect($this->withToken($token)->getJson('/api/admin/currencies')->assertOk()->json('data'))->pluck('code');

    expect($codes->all())->toContain('USD', 'EUR');
});

test('a superadmin creates a currency, and it is audited once', function (): void {
    ['user' => $user, 'token' => $token] = saSuperadmin();

    $this->withToken($token)->postJson('/api/admin/currencies', gbpPayload())
        ->assertCreated()
        ->assertJsonPath('data.code', 'GBP')
        ->assertJsonPath('data.rate_per_usd', '0.790000');

    $this->withToken($token)->getJson('/api/currencies')->assertJsonFragment(['code' => 'GBP']);

    $rows = currencyAudit('currency.created');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->actor_id)->toBe($user->id)
        ->and($rows[0]->organization_id)->toBeNull()
        ->and($rows[0]->subject_type)->toBe('Currency')
        ->and($rows[0]->before)->toBeNull()
        ->and(json_decode($rows[0]->after, true))->toMatchArray(['code' => 'GBP', 'rate_per_usd' => '0.790000']);
});

test('an edit records the rate before and after in one audit entry', function (): void {
    ['token' => $token] = saSuperadmin();
    Currency::factory()->active()->create(['code' => 'GBP', 'rate_per_usd' => '0.920000']);

    $this->withToken($token)->patchJson('/api/admin/currencies/GBP', ['rate_per_usd' => '0.930000', 'rate_as_of' => '2026-10-11'])
        ->assertOk()
        ->assertJsonPath('data.rate_per_usd', '0.930000');

    $rows = currencyAudit('currency.updated');
    expect($rows)->toHaveCount(1)
        ->and(json_decode($rows[0]->before, true))->toMatchArray(['code' => 'GBP', 'rate_per_usd' => '0.920000'])
        ->and(json_decode($rows[0]->after, true))->toMatchArray(['code' => 'GBP', 'rate_per_usd' => '0.930000']);
});

test('EUR is activated once it has a rate', function (): void {
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->patchJson('/api/admin/currencies/EUR', ['rate_per_usd' => '0.920000', 'rate_as_of' => '2026-10-10', 'is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

test('an unassigned currency is deleted and audited', function (): void {
    ['token' => $token] = saSuperadmin();
    Currency::factory()->create(['code' => 'GBP']);

    $this->withToken($token)->deleteJson('/api/admin/currencies/GBP')->assertNoContent();

    expect(Currency::find('GBP'))->toBeNull()
        ->and(currencyAudit('currency.deleted'))->toHaveCount(1);
});

test('validation refuses each malformed field with its code', function (array $overrides, string $field, string $code): void {
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->postJson('/api/admin/currencies', gbpPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$field}.0", $code);

    expect(currencyAudit('currency.created'))->toBe([]);
})->with([
    'lowercase code' => [['code' => 'gbp'], 'code', 'currency_code_invalid'],
    'four letters' => [['code' => 'GBPX'], 'code', 'currency_code_invalid'],
    'taken code' => [['code' => 'EUR'], 'code', 'currency_code_taken'],
    'zero rate' => [['rate_per_usd' => '0'], 'rate_per_usd', 'currency_rate_invalid'],
    'rate at the ceiling' => [['rate_per_usd' => '1000000'], 'rate_per_usd', 'currency_rate_invalid'],
    'seven decimals' => [['rate_per_usd' => '0.1234567'], 'rate_per_usd', 'currency_rate_invalid'],
    'rate without a date' => [['rate_as_of' => null], 'rate_as_of', 'currency_rate_as_of_invalid'],
    'future date' => [['rate_as_of' => '2999-01-01'], 'rate_as_of', 'currency_rate_as_of_invalid'],
    'five decimals places' => [['decimals' => 5], 'decimals', 'currency_decimals_invalid'],
    'empty symbol' => [['symbol' => ''], 'symbol', 'currency_symbol_invalid'],
]);

test('activating without a rate is refused and leaves the row unchanged', function (): void {
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->patchJson('/api/admin/currencies/EUR', ['is_active' => true])
        ->assertUnprocessable()
        ->assertJsonPath('errors.is_active.0', 'currency_activation_requires_rate');

    expect(Currency::find('EUR')->is_active)->toBeFalse();
});

test('renaming a code is refused', function (): void {
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->patchJson('/api/admin/currencies/EUR', ['code' => 'EUX'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'currency_code_immutable');

    expect(Currency::find('EUR'))->not->toBeNull()->and(Currency::find('EUX'))->toBeNull();
});

test('an assigned currency cannot be deleted or deactivated, and the body names no organization', function (): void {
    ['token' => $token] = saSuperadmin();
    Currency::factory()->active()->create(['code' => 'GBP']);
    $org = Organization::factory()->create();
    DB::table('organizations')->where('id', $org->id)->update(['currency_code' => 'GBP']);

    $this->withToken($token)->deleteJson('/api/admin/currencies/GBP')
        ->assertConflict()
        ->assertExactJson(['message' => 'currency_in_use']);
    $this->withToken($token)->patchJson('/api/admin/currencies/GBP', ['is_active' => false])
        ->assertConflict()
        ->assertExactJson(['message' => 'currency_in_use']);

    expect(Currency::find('GBP')->is_active)->toBeTrue()
        ->and(currencyAudit('currency.deleted'))->toBe([])
        ->and(currencyAudit('currency.updated'))->toBe([]);
});

test('an assigned currency still accepts a rate edit', function (): void {
    ['token' => $token] = saSuperadmin();
    Currency::factory()->active()->create(['code' => 'GBP']);
    $org = Organization::factory()->create();
    DB::table('organizations')->where('id', $org->id)->update(['currency_code' => 'GBP']);

    $this->withToken($token)->patchJson('/api/admin/currencies/GBP', ['rate_per_usd' => '0.800000', 'rate_as_of' => '2026-10-11'])
        ->assertOk();
});

test('USD is immutable', function (array $payload): void {
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->patchJson('/api/admin/currencies/USD', $payload)
        ->assertConflict()
        ->assertExactJson(['message' => 'currency_usd_immutable']);
})->with([
    'deactivate' => [['is_active' => false]],
    'new rate' => [['rate_per_usd' => '2.000000', 'rate_as_of' => '2026-10-11']],
]);

test('USD cannot be deleted', function (): void {
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->deleteJson('/api/admin/currencies/USD')
        ->assertConflict()
        ->assertExactJson(['message' => 'currency_usd_immutable']);
});

test('every tenant role is refused on all four verbs and nothing is written', function (string $role): void {
    Currency::factory()->create(['code' => 'GBP']);
    $token = authTokenForRole(Organization::factory()->create(), $role);

    $this->withToken($token)->getJson('/api/admin/currencies')->assertForbidden();
    $this->withToken($token)->postJson('/api/admin/currencies', gbpPayload(['code' => 'CHF']))->assertForbidden();
    $this->withToken($token)->patchJson('/api/admin/currencies/GBP', ['symbol' => 'x'])->assertForbidden();
    $this->withToken($token)->deleteJson('/api/admin/currencies/GBP')->assertForbidden();

    expect(Currency::find('CHF'))->toBeNull()
        ->and(Currency::find('GBP')->symbol)->toBe('¤')
        ->and(DB::table('audit_logs')->where('action', 'like', 'currency.%')->count())->toBe(0);
})->with(['admin', 'operator', 'viewer']);

test('a concurrent duplicate code is a 422 currency_code_taken, not a 500', function (): void {
    ['token' => $token] = saSuperadmin();

    // Seam: the request-level `unique` rule has already passed when the model
    // insert runs, so a row committed by a racing request is simulated from the
    // `creating` event. The REAL database unique constraint then rejects the insert.
    Currency::creating(static function (): void {
        DB::table('currencies')->insert(['code' => 'GBP', 'symbol' => '£', 'decimals' => 2, 'is_active' => false]);
    });

    try {
        $this->withToken($token)->postJson('/api/admin/currencies', gbpPayload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'currency_code_taken');
    } finally {
        Currency::getEventDispatcher()->forget('eloquent.creating: '.Currency::class);
    }

    expect(currencyAudit('currency.created'))->toBe([]);
});

test('a currency created without is_active is inactive, and with a rate it may be created active', function (): void {
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->postJson('/api/admin/currencies', gbpPayload(['code' => 'NOK', 'is_active' => false]))
        ->assertCreated()->assertJsonPath('data.is_active', false);

    $payload = gbpPayload(['code' => 'CHF']);
    $this->withToken($token)->postJson('/api/admin/currencies', $payload)
        ->assertCreated()->assertJsonPath('data.is_active', true);

    $bare = ['code' => 'SEK', 'symbol' => 'kr', 'decimals' => 2];
    $this->withToken($token)->postJson('/api/admin/currencies', $bare)
        ->assertCreated()->assertJsonPath('data.is_active', false);
});

test('a fetched decimal-string rate round-trips through PATCH with six-decimal precision', function (): void {
    ['token' => $token] = saSuperadmin();
    Currency::factory()->active()->create(['code' => 'GBP', 'rate_per_usd' => '0.892379', 'rate_as_of' => '2026-10-10']);

    $fetched = $this->withToken($token)->getJson('/api/admin/currencies')->json('data');
    $gbp = collect($fetched)->firstWhere('code', 'GBP');

    $this->withToken($token)->patchJson('/api/admin/currencies/GBP', ['rate_per_usd' => $gbp['rate_per_usd'], 'rate_as_of' => '2026-10-11'])
        ->assertOk()
        ->assertJsonPath('data.rate_per_usd', '0.892379');
    expect(DB::table('currencies')->where('code', 'GBP')->value('rate_per_usd'))->toBe('0.892379');
});

test('a rate and its date travel together on update, in either direction', function (array $payload, string $field): void {
    ['token' => $token] = saSuperadmin();
    Currency::factory()->active()->create(['code' => 'GBP', 'rate_per_usd' => '0.920000', 'rate_as_of' => '2026-10-10']);

    $this->withToken($token)->patchJson('/api/admin/currencies/GBP', $payload)
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$field}.0", $field === 'rate_as_of' ? 'currency_rate_as_of_invalid' : 'currency_rate_invalid');

    $gbp = Currency::find('GBP');
    expect((string) $gbp->rate_per_usd)->toBe('0.920000')
        ->and(currencyAudit('currency.updated'))->toBe([]);
})->with([
    'rate without a date' => [['rate_per_usd' => '0.930000'], 'rate_as_of'],
    'date without a rate' => [['rate_as_of' => '2026-10-11'], 'rate_per_usd'],
]);

test('a date without a rate is refused when no rate is stored', function (): void {
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->patchJson('/api/admin/currencies/EUR', ['rate_as_of' => '2026-10-11'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.rate_per_usd.0', 'currency_rate_invalid');
});

test('every accepted false deactivation hits the in-use and USD guards', function (mixed $false): void {
    ['token' => $token] = saSuperadmin();
    Currency::factory()->active()->create(['code' => 'GBP']);
    $org = Organization::factory()->create();
    DB::table('organizations')->where('id', $org->id)->update(['currency_code' => 'GBP']);

    $this->withToken($token)->patchJson('/api/admin/currencies/GBP', ['is_active' => $false])
        ->assertConflict()->assertExactJson(['message' => 'currency_in_use']);
    $this->withToken($token)->patchJson('/api/admin/currencies/USD', ['is_active' => $false])
        ->assertConflict()->assertExactJson(['message' => 'currency_usd_immutable']);

    expect(Currency::find('GBP')->is_active)->toBeTrue()
        ->and(Currency::find('USD')->is_active)->toBeTrue();
})->with([false, 0, '0']);

test('a non-boolean is_active string is refused, not guessed', function (): void {
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->patchJson('/api/admin/currencies/USD', ['is_active' => 'false'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.is_active.0', 'currency_active_invalid');
});

test('an unknown code is a 404 even when the activation rule would fire', function (): void {
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->patchJson('/api/admin/currencies/ZZZ', ['is_active' => true])->assertNotFound();
});

test('an explicit null never clears the rate or its date, nor skips the activation guard', function (array $payload, string $field): void {
    ['token' => $token] = saSuperadmin();
    Currency::factory()->create(['code' => 'GBP', 'rate_per_usd' => '0.920000', 'rate_as_of' => '2026-10-10']);

    $this->withToken($token)->patchJson('/api/admin/currencies/GBP', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    $gbp = Currency::find('GBP');
    expect((string) $gbp->rate_per_usd)->toBe('0.920000')->and($gbp->rate_as_of)->not->toBeNull();
})->with([
    'null rate' => [['rate_per_usd' => null], 'rate_per_usd'],
    'null date' => [['rate_as_of' => null], 'rate_as_of'],
    'null rate with activation' => [['is_active' => true, 'rate_per_usd' => null], 'rate_per_usd'],
]);

test('creating an active currency without a rate is refused and nothing is stored', function (): void {
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->postJson('/api/admin/currencies', gbpPayload(['rate_per_usd' => null, 'rate_as_of' => null]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.is_active.0', 'currency_activation_requires_rate');

    expect(Currency::find('GBP'))->toBeNull();
});

test('creating an inactive currency without a rate is allowed', function (): void {
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->postJson('/api/admin/currencies', gbpPayload(['rate_per_usd' => null, 'rate_as_of' => null, 'is_active' => false]))
        ->assertCreated()
        ->assertJsonPath('data.is_active', false);
});
