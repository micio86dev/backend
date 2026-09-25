<?php

declare(strict_types=1);

/**
 * Public API step 1 (docs/specs/public-api/SPEC.md §0, §3.2, §5.2, §7 step 1).
 *
 * Evaluates `config/public_api.php` DIRECTLY under controlled environment
 * input — never against Laravel's booted `config()` cache, which resolves
 * every `env()` call once at application boot and freezes the result for
 * the rest of the process. A test asserting against the booted cache breaks
 * for any developer (or CI runner) who already has `PUBLIC_API_URL` (or any
 * of the other four keys below) exported in their own shell; requiring the
 * file fresh, under env vars this test sets and restores itself, removes
 * that dependency on the ambient environment entirely.
 *
 * No existing `tests/Unit/Config/*ConfigTest.php` establishes a
 * controlled-environment pattern for this — checked `AuditConfigTest` and
 * `TruncationRetryConfigTest`, both pin booted `config()` defaults with no
 * env manipulation — so this follows `Illuminate\Support\Env`'s own
 * contract instead: `env()` resolves through `Env::getRepository()`, built
 * from `getenv()`/`$_ENV`/`$_SERVER`-backed adapters
 * (vendor/laravel/framework/src/Illuminate/Support/Env.php). Setting
 * `putenv()` and mirroring into `$_ENV`/`$_SERVER` before a fresh `require`
 * covers every adapter that repository can be built with, and restoring all
 * three afterwards (in `finally`, so a failing assertion still restores
 * them) leaves no trace for later tests.
 */

/**
 * Requires `config/public_api.php` fresh under the given env overrides,
 * restoring the previous `getenv()`/$_ENV/$_SERVER state for each touched
 * key afterwards regardless of outcome.
 *
 * @param  array<string, string|null>  $vars  a string sets the key (including
 *                                            `''` for a present-but-empty `KEY=` line); `null` unsets it entirely.
 * @return array<string, mixed>
 */
function loadPublicApiConfig(array $vars): array
{
    $previous = [];

    foreach ($vars as $key => $value) {
        $previous[$key] = [
            'getenv' => getenv($key),
            'hadEnv' => array_key_exists($key, $_ENV),
            'env' => $_ENV[$key] ?? null,
            'hadServer' => array_key_exists($key, $_SERVER),
            'server' => $_SERVER[$key] ?? null,
        ];

        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    try {
        return require base_path('config/public_api.php');
    } finally {
        foreach ($previous as $key => $state) {
            if ($state['getenv'] === false) {
                putenv($key);
            } else {
                putenv("{$key}={$state['getenv']}");
            }

            if ($state['hadEnv']) {
                $_ENV[$key] = $state['env'];
            } else {
                unset($_ENV[$key]);
            }

            if ($state['hadServer']) {
                $_SERVER[$key] = $state['server'];
            } else {
                unset($_SERVER[$key]);
            }
        }
    }
}

test('contract_path: an empty PUBLIC_API_CONTRACT_PATH falls back to the default', function (): void {
    $config = loadPublicApiConfig(['PUBLIC_API_CONTRACT_PATH' => '']);

    expect($config['contract_path'])->toBe(base_path('public-api/openapi.yaml'));
});

test('contract_path: an unset PUBLIC_API_CONTRACT_PATH falls back to the default', function (): void {
    $config = loadPublicApiConfig(['PUBLIC_API_CONTRACT_PATH' => null]);

    expect($config['contract_path'])->toBe(base_path('public-api/openapi.yaml'));
});

test('contract_path: an explicit PUBLIC_API_CONTRACT_PATH is used verbatim', function (): void {
    $config = loadPublicApiConfig(['PUBLIC_API_CONTRACT_PATH' => '/tmp/custom-contract.yaml']);

    expect($config['contract_path'])->toBe('/tmp/custom-contract.yaml');
});

test('base_url: an empty PUBLIC_API_URL falls back to APP_URL + /api/v1', function (): void {
    $config = loadPublicApiConfig([
        'PUBLIC_API_URL' => '',
        'APP_URL' => 'https://api.example.test',
    ]);

    expect($config['base_url'])->toBe('https://api.example.test/api/v1');
});

test('base_url: an unset PUBLIC_API_URL falls back to APP_URL + /api/v1', function (): void {
    $config = loadPublicApiConfig([
        'PUBLIC_API_URL' => null,
        'APP_URL' => 'https://api.example.test',
    ]);

    expect($config['base_url'])->toBe('https://api.example.test/api/v1');
});

test('base_url: falls back to http://localhost + /api/v1 when APP_URL is also unset', function (): void {
    $config = loadPublicApiConfig([
        'PUBLIC_API_URL' => null,
        'APP_URL' => null,
    ]);

    expect($config['base_url'])->toBe('http://localhost/api/v1');
});

test('base_url: falls back to http://localhost + /api/v1 when APP_URL is also empty', function (): void {
    $config = loadPublicApiConfig([
        'PUBLIC_API_URL' => null,
        'APP_URL' => '',
    ]);

    expect($config['base_url'])->toBe('http://localhost/api/v1');
});

test('base_url: a trailing slash on APP_URL is stripped before appending /api/v1', function (): void {
    $config = loadPublicApiConfig([
        'PUBLIC_API_URL' => null,
        'APP_URL' => 'https://x/',
    ]);

    expect($config['base_url'])->toBe('https://x/api/v1');
});

test('base_url: an explicit PUBLIC_API_URL is used verbatim', function (): void {
    $config = loadPublicApiConfig(['PUBLIC_API_URL' => 'https://api.beai.example/v1']);

    expect($config['base_url'])->toBe('https://api.beai.example/v1');
});

test('the three optional URLs: empty env values fall back to null', function (): void {
    $config = loadPublicApiConfig([
        'DEVELOPERS_URL' => '',
        'INTERVIEW_URL' => '',
        'EMBED_CDN_URL' => '',
    ]);

    expect($config['developers_url'])->toBeNull()
        ->and($config['interview_url'])->toBeNull()
        ->and($config['embed_cdn_url'])->toBeNull();
});

test('the three optional URLs: unset env values fall back to null', function (): void {
    $config = loadPublicApiConfig([
        'DEVELOPERS_URL' => null,
        'INTERVIEW_URL' => null,
        'EMBED_CDN_URL' => null,
    ]);

    expect($config['developers_url'])->toBeNull()
        ->and($config['interview_url'])->toBeNull()
        ->and($config['embed_cdn_url'])->toBeNull();
});

test('the three optional URLs: explicit values are used verbatim', function (): void {
    $config = loadPublicApiConfig([
        'DEVELOPERS_URL' => 'https://developers.beai.example',
        'INTERVIEW_URL' => 'https://interview.beai.example',
        'EMBED_CDN_URL' => 'https://cdn.beai.example',
    ]);

    expect($config['developers_url'])->toBe('https://developers.beai.example')
        ->and($config['interview_url'])->toBe('https://interview.beai.example')
        ->and($config['embed_cdn_url'])->toBe('https://cdn.beai.example');
});

test('the vendored contract file exists at the configured contract_path', function (): void {
    expect(file_exists(config('public_api.contract_path')))->toBeTrue();
});
