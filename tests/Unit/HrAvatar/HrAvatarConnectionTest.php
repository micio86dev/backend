<?php

declare(strict_types=1);

use App\Services\Provider\HrAvatarConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  array<string, string|null>  $overrides
 */
function hraConfigure(array $overrides = []): void
{
    config(['hr_avatar' => array_merge([
        'base_url' => 'https://hra.example.test',
        'secret_key' => 'hra_sk_live_SECRETVALUE',
        'publishable_key' => 'hra_pk_live_PUBLISHABLE',
    ], $overrides)]);
}

test('all three settings present make the integration available without any request', function (): void {
    Http::fake();
    Http::preventStrayRequests();
    hraConfigure();

    expect(HrAvatarConnection::isAvailable())->toBeTrue();
    expect(HrAvatarConnection::missingVariables())->toBe([]);

    Http::assertNothingSent();
});

test('a single missing or blank setting makes it unavailable and names only the variable', function (string $key, string $variable, ?string $value): void {
    Http::fake();
    Http::preventStrayRequests();
    hraConfigure([$key => $value]);

    expect(HrAvatarConnection::isAvailable())->toBeFalse();
    expect(HrAvatarConnection::missingVariables())->toBe([$variable]);

    Http::assertNothingSent();
})->with([
    'secret unset' => ['secret_key', 'HR_AVATAR_SECRET_KEY', null],
    'secret empty' => ['secret_key', 'HR_AVATAR_SECRET_KEY', ''],
    'secret whitespace only' => ['secret_key', 'HR_AVATAR_SECRET_KEY', "  \t "],
    'base url unset' => ['base_url', 'HR_AVATAR_BASE_URL', null],
    'publishable unset' => ['publishable_key', 'HR_AVATAR_PUBLISHABLE_KEY', null],
]);

test('a base URL that is not an absolute http(s) URL counts as missing', function (string $url): void {
    hraConfigure(['base_url' => $url]);

    expect(HrAvatarConnection::isAvailable())->toBeFalse();
    expect(HrAvatarConnection::missingVariables())->toBe(['HR_AVATAR_BASE_URL']);
})->with(['not a url', 'ftp://hra.example.test', '/relative/path', 'hra.example.test']);

test('nothing set is the default state and reports every variable', function (): void {
    Http::fake();
    Http::preventStrayRequests();
    config(['hr_avatar' => ['base_url' => null, 'secret_key' => null, 'publishable_key' => null]]);

    expect(HrAvatarConnection::isAvailable())->toBeFalse();
    expect(HrAvatarConnection::missingVariables())->toBe([
        'HR_AVATAR_BASE_URL', 'HR_AVATAR_SECRET_KEY', 'HR_AVATAR_PUBLISHABLE_KEY',
    ]);

    Http::assertNothingSent();
});

test('the configuration file declares exactly the three settings', function (): void {
    expect(array_keys(require config_path('hr_avatar.php')))->toBe(['base_url', 'secret_key', 'publishable_key']);
});

test('accessors trim the base URL slash and expose the keys', function (): void {
    hraConfigure(['base_url' => 'https://hra.example.test/']);

    expect(HrAvatarConnection::baseUrl())->toBe('https://hra.example.test');
    expect(HrAvatarConnection::publishableKey())->toBe('hra_pk_live_PUBLISHABLE');
    expect(HrAvatarConnection::secretKey())->toBe('hra_sk_live_SECRETVALUE');
});

test('organizationRef is opaque and a pure function of the id', function (): void {
    expect(HrAvatarConnection::organizationRef(7))->toBe('org_7');
});

test('client sends the secret key as a bearer credential with a 15 second timeout', function (): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    hraConfigure(['base_url' => 'https://hra.example.test/']);

    $client = HrAvatarConnection::client();
    $client->get('/v1/ping');

    expect($client->getOptions()['timeout'])->toBe(15);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://hra.example.test/v1/ping'
        && $request->hasHeader('Authorization', 'Bearer hra_sk_live_SECRETVALUE')
        && $request->hasHeader('Accept', 'application/json'));
});

test('the provider rule refuses hr_avatar while unavailable and leaves other values alone', function (): void {
    config(['hr_avatar' => ['base_url' => null, 'secret_key' => null, 'publishable_key' => null]]);

    $rule = fn (string $value) => Validator::make(['provider' => $value], ['provider' => [HrAvatarConnection::unavailableProviderRule()]]);

    $refused = $rule('hr_avatar');
    expect($refused->fails())->toBeTrue();
    expect($refused->errors()->first('provider'))->toBe(__('validation.in', ['attribute' => 'provider']));

    expect($rule('tavus')->fails())->toBeFalse();
    expect($rule('heygen')->fails())->toBeFalse();
    expect($rule('nonsense')->fails())->toBeFalse();
});

test('the provider rule accepts hr_avatar while available', function (): void {
    hraConfigure();

    $validator = Validator::make(['provider' => 'hr_avatar'], ['provider' => [HrAvatarConnection::unavailableProviderRule()]]);

    expect($validator->fails())->toBeFalse();
});

test('a config id is exactly avc_ plus alphanumerics: no newline, no path, no overlong value', function (mixed $value, bool $valid): void {
    expect(HrAvatarConnection::isConfigId($value))->toBe($valid);
})->with([
    'valid' => ['avc_01HXYZ', true],
    'trailing newline' => ["avc_01HXYZ\n", false],
    'path' => ['avc_x/../y', false],
    'no prefix' => ['cfg_1', false],
    'too long' => ['avc_'.str_repeat('a', 61), false],
    'not a string' => [123, false],
]);

test('the config id pattern is self-contained: no trailing newline passes through the constant alone or as a validation rule', function (): void {
    expect(preg_match(HrAvatarConnection::CONFIG_ID_REGEX, 'avc_01HXYZ'))->toBe(1)
        ->and(preg_match(HrAvatarConnection::CONFIG_ID_REGEX, "avc_01HXYZ\n"))->toBe(0);

    $rule = fn (string $value) => Validator::make(['id' => $value], ['id' => ['regex:'.HrAvatarConnection::CONFIG_ID_REGEX]])->passes();

    expect($rule('avc_01HXYZ'))->toBeTrue()
        ->and($rule("avc_01HXYZ\n"))->toBeFalse();
});
