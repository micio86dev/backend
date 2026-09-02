<?php

declare(strict_types=1);

/**
 * The platform superadmin, provisioned by PlatformSuperadminSeeder.
 *
 * Read through config rather than `env()` at the call site, because
 * `config:cache` in production makes a direct `env()` return null — the
 * failure mode Larastan's `noEnvCallsOutsideOfConfig` rule exists to prevent,
 * and one that would leave a deploy with no superadmin and no error.
 *
 * No defaults for email or password. An unset email skips the seeder entirely
 * (it is opt-in), and an unset password makes it refuse rather than invent
 * one: a default credential on an account that crosses every tenant is the
 * first thing anyone would try.
 */
return [
    'email' => env('SUPERADMIN_EMAIL'),
    'name' => env('SUPERADMIN_NAME', 'Platform Superadmin'),
    'password' => env('SUPERADMIN_PASSWORD'),
];
