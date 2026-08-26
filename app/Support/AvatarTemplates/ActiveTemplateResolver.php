<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

use App\Models\AvatarTemplate;

/**
 * The organization's active avatar template for a given provider, if it has
 * one (C14; pluggable-conversation-llm PR P0, design D0).
 *
 * `$provider` is REQUIRED, with no default value. An organization may hold
 * one active template per provider simultaneously (a project on HeyGen and
 * another on Tavus each need their own), so matching on `is_active` alone —
 * the original shape of this class — could silently return an active
 * template belonging to a DIFFERENT provider than the one asking. An
 * optional `?string $provider = null` would only relocate that bug to
 * whichever future call site forgot to pass it; requiring the argument
 * makes that call a compile/type error instead.
 *
 * Returns null rather than throwing when nothing is active for that
 * provider, and that is not defensive coding — having no active template is
 * a fully supported state, not an edge case, and the providers' platform
 * defaults are what make it so (pinned by HeygenProviderTest and
 * ProviderContractFixtureTest) — the moment this ships. Failing here would
 * break every interview in the product to deliver a feature nobody has
 * configured yet.
 *
 * Tenancy comes from the TenantScoped global scope, so there is no
 * organization_id argument to pass and therefore none to pass wrongly.
 */
final class ActiveTemplateResolver
{
    public function resolve(string $provider): ?AvatarTemplate
    {
        return AvatarTemplate::where('is_active', true)
            ->where('provider', $provider)
            ->first();
    }
}
