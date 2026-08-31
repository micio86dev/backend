<?php

declare(strict_types=1);

/**
 * ActiveTemplateResolver::resolve() requires an explicit provider
 * (pluggable-conversation-llm, PR P0, design D0).
 *
 * Before this change the resolver matched on `is_active` alone and could
 * return an active template belonging to a DIFFERENT provider than the one
 * asking — a project running on Tavus could silently receive a HeyGen-shaped
 * active template. `$provider` is now required, with no default, so a future
 * call site cannot omit it and reintroduce the bug.
 *
 * REQ: avatar-templates "Active template resolution requires an explicit
 *      provider and never crosses providers"
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\Project;
use App\Support\AvatarTemplates\ActiveTemplateResolver;
use App\Support\Tenancy\TenantContextScope;

function resolverActiveTemplate(Organization $org, string $provider): AvatarTemplate
{
    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => "Active {$provider}",
        'provider' => $provider,
        'config' => [],
        'is_active' => true,
    ]));
}

test('an active template on a different provider is not returned', function (): void {
    $org = Organization::factory()->create();
    resolverActiveTemplate($org, 'heygen');

    $found = TenantContextScope::runFor(
        $org->id,
        fn () => app(ActiveTemplateResolver::class)->resolve('tavus'),
    );

    expect($found)->toBeNull();
});

test('an active template on the requested provider IS returned', function (): void {
    $org = Organization::factory()->create();
    $template = resolverActiveTemplate($org, 'tavus');

    $found = TenantContextScope::runFor(
        $org->id,
        fn () => app(ActiveTemplateResolver::class)->resolve('tavus'),
    );

    expect($found?->id)->toBe($template->id);
});

test('resolve() has no default argument for $provider — a no-argument call is not legal', function (): void {
    $parameters = (new ReflectionMethod(ActiveTemplateResolver::class, 'resolve'))->getParameters();

    // `$provider` stays FIRST and REQUIRED. `$projectId` was added after it as
    // an optional second parameter (per-project template pinning) — optional
    // there is safe in a way it would never be for `$provider`, because a
    // caller that omits it gets the organization-wide fallback this class
    // always had, not a silently cross-provider template.
    expect($parameters[0]->getName())->toBe('provider');
    expect($parameters[0]->isOptional())->toBeFalse();
    expect($parameters[0]->isDefaultValueAvailable())->toBeFalse();
    expect((string) $parameters[0]->getType())->toBe('string');
});

/**
 * Per-project template pinning.
 *
 * `projects.avatar_template_id` is nullable and the organization-wide active
 * template remains the fallback, so a project that pins nothing behaves
 * exactly as it did before this existed.
 */
function resolverProjectPinning(Organization $org, ?int $templateId): Project
{
    return TenantContextScope::runFor($org->id, fn (): Project => Project::factory()->create([
        'organization_id' => $org->id,
        'avatar_template_id' => $templateId,
    ]));
}

test("a project's pinned template wins over the organization's active one", function (): void {
    $org = Organization::factory()->create();
    resolverActiveTemplate($org, 'heygen');

    $pinned = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Pinned heygen',
        'provider' => 'heygen',
        'config' => [],
        'is_active' => false,
    ]));

    $project = resolverProjectPinning($org, $pinned->id);

    $found = TenantContextScope::runFor(
        $org->id,
        fn () => app(ActiveTemplateResolver::class)->resolve('heygen', $project->id),
    );

    // Note `is_active: false` on the pinned one — pinning is an explicit
    // choice by the project and does not additionally require the template to
    // be the org's active one. Requiring both would make two projects on the
    // same provider impossible, which is the whole point of the column.
    expect($found?->id)->toBe($pinned->id);
});

test('a project pinning nothing still gets the organization active template', function (): void {
    $org = Organization::factory()->create();
    $active = resolverActiveTemplate($org, 'heygen');
    $project = resolverProjectPinning($org, null);

    $found = TenantContextScope::runFor(
        $org->id,
        fn () => app(ActiveTemplateResolver::class)->resolve('heygen', $project->id),
    );

    expect($found?->id)->toBe($active->id);
});

test('a pinned template on a DIFFERENT provider is ignored, never returned across providers', function (): void {
    $org = Organization::factory()->create();
    $tavusActive = resolverActiveTemplate($org, 'tavus');

    $heygenPinned = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Pinned heygen',
        'provider' => 'heygen',
        'config' => [],
        'is_active' => false,
    ]));

    $project = resolverProjectPinning($org, $heygenPinned->id);

    $found = TenantContextScope::runFor(
        $org->id,
        fn () => app(ActiveTemplateResolver::class)->resolve('tavus', $project->id),
    );

    // The cross-provider bug PR P0 fixed must not come back through the pin.
    // A mismatch should not normally happen — the provider is derived FROM the
    // pinned template — but the resolver must not depend on a caller upstream
    // getting that right.
    expect($found?->id)->toBe($tavusActive->id);
});

test('a pinned template belonging to another tenant is never returned', function (): void {
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();

    $theirTemplate = resolverActiveTemplate($theirs, 'heygen');
    $project = resolverProjectPinning($mine, $theirTemplate->id);

    $found = TenantContextScope::runFor(
        $mine->id,
        fn () => app(ActiveTemplateResolver::class)->resolve('heygen', $project->id),
    );

    expect($found)->toBeNull();
});

test('an unknown project id falls back rather than throwing', function (): void {
    $org = Organization::factory()->create();
    $active = resolverActiveTemplate($org, 'heygen');

    $found = TenantContextScope::runFor(
        $org->id,
        fn () => app(ActiveTemplateResolver::class)->resolve('heygen', 999_999),
    );

    expect($found?->id)->toBe($active->id);
});

test('an organization with no active template on any provider resolves to null', function (): void {
    $org = Organization::factory()->create();

    $found = TenantContextScope::runFor(
        $org->id,
        fn () => app(ActiveTemplateResolver::class)->resolve('heygen'),
    );

    expect($found)->toBeNull();
});

test('resolution never crosses tenants', function (): void {
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    resolverActiveTemplate($theirs, 'tavus');

    $found = TenantContextScope::runFor(
        $mine->id,
        fn () => app(ActiveTemplateResolver::class)->resolve('tavus'),
    );

    expect($found)->toBeNull();
});
