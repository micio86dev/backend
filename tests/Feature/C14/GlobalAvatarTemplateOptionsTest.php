<?php

declare(strict_types=1);

/**
 * `GET /api/avatar-templates/options` offers platform templates (A2).
 *
 * The picker lists the caller's own templates and the ACTIVE platform ones, in
 * that order, each tagged with its `scope`. A retired platform template stays
 * listed only while a live project of THIS organization pins it (the edit form
 * must render its current pin). It carries no config, organization id or usage
 * counts. A bare superadmin, who has no organization to be offered anything,
 * sees everything.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

function gopOwn(Organization $org, string $name, bool $active = false): AvatarTemplate
{
    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => $name, 'provider' => 'heygen', 'config' => ['avatarId' => 'secret-avatar'], 'is_active' => $active,
    ]));
}

function gopPin(Organization $org, AvatarTemplate $template): void
{
    TenantContextScope::runFor($org->id, fn (): Project => Project::factory()->create(['avatar_template_id' => $template->id]));
}

/**
 * Organization A with two own templates, another organization with one, and
 * four globals: offered, retired and pinned by A, retired and pinned only by
 * the other organization, retired and unpinned.
 *
 * @return array{0: Organization}
 */
function gopFixture(): array
{
    $org = Organization::factory()->create();
    $other = Organization::factory()->create();

    gopOwn($org, 'Own inactive');
    gopOwn($org, 'Own active', true);
    $foreign = gopOwn($other, 'Foreign active', true);

    PlatformTemplates::insertActiveGlobal(['name' => 'Global offered']);
    gopPin($org, PlatformTemplates::insertGlobal(['name' => 'Global retired pinned by A']));
    gopPin($other, PlatformTemplates::insertGlobal(['name' => 'Global retired pinned by other']));
    PlatformTemplates::insertGlobal(['name' => 'Global retired unpinned']);
    gopPin($other, $foreign);

    return [$org];
}

dataset('gopActors', ['admin', 'operator', 'viewer', 'acting']);

test('a member sees own templates then active globals, org first, active first, then by name', function (string $actor): void {
    [$org] = gopFixture();

    $response = $this->withToken(TemplateActors::token($actor, $org))->getJson('/api/avatar-templates/options')->assertOk();

    expect(array_column($response->json('data'), 'name'))->toBe([
        'Own active', 'Own inactive', 'Global offered', 'Global retired pinned by A',
    ]);
    expect(array_column($response->json('data'), 'scope'))->toBe(['organization', 'organization', 'platform', 'platform']);
})->with('gopActors');

test('an entry carries exactly id, name, provider, is_active and scope', function (): void {
    [$org] = gopFixture();

    $data = $this->withToken(TemplateActors::token('viewer', $org))->getJson('/api/avatar-templates/options')->json('data');

    expect(array_keys($data[0]))->toBe(['id', 'name', 'provider', 'is_active', 'scope']);
    expect(json_encode($data))->not->toContain('secret-avatar')->not->toContain('organization_id');
});

test('a bare superadmin sees every organization template and every global, retired included', function (): void {
    [$org] = gopFixture();

    $names = array_column($this->withToken(TemplateActors::token('bare', $org))->getJson('/api/avatar-templates/options')->json('data'), 'name');

    expect($names)->toContain('Foreign active', 'Own active', 'Own inactive', 'Global offered', 'Global retired unpinned', 'Global retired pinned by other')
        ->toHaveCount(7);
});

test('a project renders the scope of the template it pins, a global included', function (): void {
    $org = Organization::factory()->create();
    $own = gopOwn($org, 'Own');
    $global = PlatformTemplates::insertActiveGlobal(['name' => 'Global']);
    $token = TemplateActors::token('admin', $org);

    foreach ([[$own, 'organization'], [$global, 'platform']] as [$template, $scope]) {
        $project = TenantContextScope::runFor($org->id, fn (): Project => Project::factory()->create(['avatar_template_id' => $template->id]));

        $this->withToken($token)->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.avatar_template.name', $template->name)
            ->assertJsonPath('data.avatar_template.scope', $scope);
    }
});
