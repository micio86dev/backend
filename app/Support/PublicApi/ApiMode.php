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
 *
 * Review follow-up (public-api step 3, Part A finding 2): `set()` takes
 * `ApiKeyMode` directly, not a string. The previous `tryFrom($mode) ?? Live`
 * round trip existed only because the one real caller
 * (`PublicApiTenantContext`) had an `ApiKeyMode` in hand already
 * (`$client->mode`) and threw it away as `->value` just to have this method
 * parse it back — a string round trip with no unknown-string input to
 * defend against, and a silent `?? Live` fallback that could never fire for
 * that caller but would silently mask a real bug in any other. A caller
 * that genuinely has a raw string should parse it explicitly with
 * `ApiKeyMode::tryFrom()` and decide what an unrecognised value means at the
 * call site, rather than this class deciding "live" on its behalf.
 */
final class ApiMode
{
    private ApiKeyMode $mode = ApiKeyMode::Live;

    public function set(ApiKeyMode $mode): void
    {
        $this->mode = $mode;
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
