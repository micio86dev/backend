<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

use App\Models\AvatarTemplate;
use App\Models\Project;

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
    /**
     * @param  int|null  $projectId  When given, the project's own pinned
     *                               template wins over the organization-wide
     *                               active one. Optional, and safe to be so:
     *                               omitting it yields exactly the behaviour
     *                               this class always had.
     */
    public function resolve(string $provider, ?int $projectId = null): ?AvatarTemplate
    {
        $pinned = $this->pinnedFor($provider, $projectId);

        if ($pinned !== null) {
            return $pinned;
        }

        return AvatarTemplate::where('is_active', true)
            ->where('provider', $provider)
            ->first();
    }

    /**
     * A project's own template, when it has pinned one of the right provider.
     *
     * `is_active` is deliberately NOT required here. Requiring both would
     * defeat the column: only one template per provider can be active at a
     * time, so two projects on the same provider could still never differ.
     * Pinning IS the project's choice; `is_active` is only the fallback for
     * projects that made none.
     *
     * Both reads go through the TenantScoped global scope, so a project or a
     * template belonging to another organization is invisible here rather than
     * merely rejected — the same reason this class takes no organization_id
     * argument and therefore has none to pass wrongly.
     *
     * The provider check is not redundant with the caller deriving the
     * provider FROM this template: it is what stops a stale or hand-edited pin
     * reintroducing the cross-provider bug PR P0 fixed, and it costs one
     * comparison.
     */
    private function pinnedFor(string $provider, ?int $projectId): ?AvatarTemplate
    {
        if ($projectId === null) {
            return null;
        }

        $templateId = Project::whereKey($projectId)->value('avatar_template_id');

        if ($templateId === null) {
            return null;
        }

        return AvatarTemplate::whereKey($templateId)
            ->where('provider', $provider)
            ->first();
    }
}
