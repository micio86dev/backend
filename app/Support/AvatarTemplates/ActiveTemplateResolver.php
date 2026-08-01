<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

use App\Models\AvatarTemplate;

/**
 * The organization's active avatar template, if it has one (C14).
 *
 * Returns null rather than throwing when nothing is active, and that is not
 * defensive coding — it is the state EVERY existing organization is in the
 * moment this ships. Failing here would break every interview in the product to
 * deliver a feature nobody has configured yet.
 *
 * Tenancy comes from the TenantScoped global scope, so there is no
 * organization_id argument to pass and therefore none to pass wrongly.
 */
final class ActiveTemplateResolver
{
    public function resolve(): ?AvatarTemplate
    {
        return AvatarTemplate::where('is_active', true)->first();
    }
}
