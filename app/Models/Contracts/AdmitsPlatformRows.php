<?php

declare(strict_types=1);

namespace App\Models\Contracts;

/**
 * Marks the one tenant model that may persist a row owned by the platform
 * (`organization_id IS NULL`).
 *
 * TenantScoped::creating consults it before its unconditional org stamp; every
 * other TenantModel keeps the stamp-or-throw behavior untouched. Only
 * AvatarTemplate implements it, and an architecture test pins that.
 */
interface AdmitsPlatformRows
{
    /** True while an explicit platform write context is active. */
    public function writesAsPlatformRow(): bool;
}
