<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use App\Enums\ApiKeyMode;

/**
 * Request-scoped `live`/`test` mode holder for the BEAI Public API (`/v1`).
 *
 * SPEC.md §3.7 "Test mode": a `beai_test_` key's requests must never read or
 * write `live` data. This class only carries the flag — enforcing the
 * separation is a later step's job (data scoping); step 2 stamps it so that
 * later code has somewhere authoritative to read it from.
 *
 * Registered via `app()->scoped()` — NOT singleton — mirroring
 * `App\Support\Tenancy\TenantResolver` exactly: state must be re-created per
 * HTTP request (Octane safety), never leak between requests sharing a worker.
 *
 * Default state: `live` (fail towards the STRICTER surface — a request that
 * never went through `PublicApiTenantContext` is never `test` by omission).
 */
final class ApiMode
{
    private ApiKeyMode $mode = ApiKeyMode::Live;

    public function set(string $mode): void
    {
        $this->mode = ApiKeyMode::tryFrom($mode) ?? ApiKeyMode::Live;
    }

    /**
     * @return 'live'|'test'
     */
    public function get(): string
    {
        return $this->mode->value;
    }

    public function isTest(): bool
    {
        return $this->mode === ApiKeyMode::Test;
    }
}
