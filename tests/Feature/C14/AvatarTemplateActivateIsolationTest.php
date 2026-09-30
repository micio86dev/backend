<?php

declare(strict_types=1);

/**
 * R7: activating an organization's template must deactivate only THAT
 * organization's previous template of the same provider (A1).
 *
 * The bulk update used to rely on the implicit tenant scope, which filters
 * nothing under superadmin bypass — so a bare superadmin activating one org's
 * HeyGen template switched off the active HeyGen template of EVERY org.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\ActingOrganization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;

function raSeed(Organization $org, string $name, bool $active): AvatarTemplate
{
    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => $name, 'provider' => 'heygen', 'is_active' => $active,
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
    ]));
}

/** @return array{a: Organization, aOld: AvatarTemplate, aNew: AvatarTemplate, bActive: AvatarTemplate, global: AvatarTemplate} */
function raWorld(): array
{
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();

    return [
        'a' => $a,
        'aOld' => raSeed($a, 'A old', true),
        'aNew' => raSeed($a, 'A new', false),
        'bActive' => raSeed($b, 'B active', true),
        'global' => PlatformTemplates::insertActiveGlobal(['name' => 'Active global']),
    ];
}

function raActive(AvatarTemplate $template): bool
{
    return (bool) AvatarTemplate::withoutGlobalScopes()->whereKey($template->id)->value('is_active');
}

test('a bare superadmin activating an org template leaves other orgs and the global active', function (): void {
    Http::fake();
    $w = raWorld();
    $su = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    $this->withToken(auth('api')->login($su))
        ->postJson("/api/avatar-templates/{$w['aNew']->id}/activate")
        ->assertOk();

    expect(raActive($w['aNew']))->toBeTrue()
        ->and(raActive($w['aOld']))->toBeFalse()
        ->and(raActive($w['bActive']))->toBeTrue()
        ->and(raActive($w['global']))->toBeTrue();
});

test('a superadmin acting as an org leaves the global active', function (): void {
    Http::fake();
    $w = raWorld();
    $su = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
    app(ActingOrganization::class)->set((int) $su->id, $w['a']->id);

    $this->withToken(auth('api')->login($su))
        ->postJson("/api/avatar-templates/{$w['aNew']->id}/activate")
        ->assertOk();

    expect(raActive($w['aOld']))->toBeFalse()
        ->and(raActive($w['bActive']))->toBeTrue()
        ->and(raActive($w['global']))->toBeTrue();
});

test('deactivating an org template leaves the global active', function (): void {
    Http::fake();
    $w = raWorld();
    $su = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
    app(ActingOrganization::class)->set((int) $su->id, $w['a']->id);

    $this->withToken(auth('api')->login($su))
        ->postJson("/api/avatar-templates/{$w['aOld']->id}/deactivate")
        ->assertOk();

    expect(raActive($w['aOld']))->toBeFalse()->and(raActive($w['global']))->toBeTrue();
});
