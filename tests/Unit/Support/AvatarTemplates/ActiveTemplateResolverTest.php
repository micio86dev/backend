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

test('resolve() has no default argument — a no-argument call is not legal', function (): void {
    $parameters = (new ReflectionMethod(ActiveTemplateResolver::class, 'resolve'))->getParameters();

    expect($parameters)->toHaveCount(1);
    expect($parameters[0]->getName())->toBe('provider');
    expect($parameters[0]->isOptional())->toBeFalse();
    expect($parameters[0]->isDefaultValueAvailable())->toBeFalse();
    expect((string) $parameters[0]->getType())->toBe('string');
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
