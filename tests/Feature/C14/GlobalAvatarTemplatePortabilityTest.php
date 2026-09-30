<?php

declare(strict_types=1);

/**
 * Organization export/import never includes or creates platform templates
 * (global-avatar-templates A4, characterization).
 *
 * The design has NO platform export/import (proposal: out of scope, follow-up
 * A5), so these pin the boundary the other way round: whatever a superadmin is
 * — bare or acting — the ORGANIZATION routes only ever see and write
 * organization rows, and a document cannot smuggle an ownership claim in.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

function gdpOwn(Organization $org, string $name): AvatarTemplate
{
    return TenantContextScope::runFor($org->id, fn () => AvatarTemplate::create([
        'name' => $name, 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
    ]));
}

test('an acting superadmin export carries only the acting organization rows, never a platform template', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    gdpOwn($orgA, 'Own of A');
    gdpOwn($orgB, 'Own of B');
    PlatformTemplates::insertActiveGlobal(['name' => 'Platform only']);

    $names = collect($this->withToken(TemplateActors::token('acting', $orgA))->getJson('/api/avatar-templates/export')
        ->assertOk()->json('templates'))->pluck('name')->all();

    expect($names)->toBe(['Own of A']);
});

test('a bare superadmin export is empty: it has no organization, and a platform template is not "the rows of no organization"', function (): void {
    // The export filters `organization_id = <resolver org>`, which for a bare
    // superadmin is `IS NULL` — exactly the platform rows. The always-on
    // exclusion scope is what keeps them out; without it this would list them.
    gdpOwn(Organization::factory()->create(), 'Own of A');
    PlatformTemplates::insertActiveGlobal(['name' => 'Platform only']);

    $this->withToken(TemplateActors::token('bare', Organization::factory()->create()))
        ->getJson('/api/avatar-templates/export')->assertOk()->assertJsonPath('templates', []);
});

test('an import ignores any organization_id in the document and creates rows of the acting organization', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $document = [
        'schema' => 'beai.avatar-template/1',
        'templates' => [
            ['name' => 'Claims no owner', 'organization_id' => null, 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v']],
            ['name' => 'Claims another owner', 'organization_id' => $orgB->id, 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v']],
        ],
    ];

    $this->withToken(TemplateActors::token('acting', $orgA))->postJson('/api/avatar-templates/import', $document)->assertCreated();

    $rows = AvatarTemplate::withoutGlobalScopes()->whereIn('name', ['Claims no owner', 'Claims another owner'])->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('organization_id')->unique()->all())->toBe([$orgA->id])
        ->and(AvatarTemplate::platformOnly()->count())->toBe(0);
});
