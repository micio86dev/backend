<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * Request-scoped tenant context holder.
 *
 * Registered via app()->scoped() — NOT singleton — so that state
 * is re-created per HTTP request (critical for Octane safety) and
 * reset via Queue::before hook for queue workers (see TenancyServiceProvider).
 *
 * Default state: orgId=null, bypass=false (fail-closed).
 */
final class TenantResolver
{
    private ?int $orgId = null;

    private bool $bypass = false;

    public function getOrgId(): ?int
    {
        return $this->orgId;
    }

    public function setOrgId(?int $orgId): void
    {
        $this->orgId = $orgId;
    }

    public function isBypass(): bool
    {
        return $this->bypass;
    }

    public function setBypass(bool $bypass): void
    {
        $this->bypass = $bypass;
    }
}
