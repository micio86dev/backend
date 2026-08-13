<?php

declare(strict_types=1);

/**
 * Avatar template JSON export / import (C14 portability).
 *
 * Configuration is tuned in the sibling `quint-avatar-tester` project and has
 * had no way into BEAI except retyping it into a form. This is that path.
 *
 * The refusals carry the weight here. An import that silently drops a key, or
 * overwrites a template a live project is running on, is worse than an import
 * that fails: the operator walks away believing something is configured.
 *
 * REQ: Avatar template configuration is exportable and importable as JSON
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function portabilityUser(Organization $org, string $role = 'admin'): string
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return (string) auth('api')->login($user);
}

function portableHeygenConfig(): array
{
    return [
        'avatarId' => 'ab0765ad-69de-41fb-9f8a-bd01c3c52d6f',
        'voiceId' => 'c84af063-5ce2-4370-8ef8-dcd0ef903d43',
        'language' => 'it',
    ];
}

function portableTemplate(Organization $org, string $name = 'Interviewer IT'): AvatarTemplate
{
    $template = new AvatarTemplate;
    $template->forceFill([
        'organization_id' => $org->id,
        'name' => $name,
        'description' => 'Ported from avatar-tester',
        'provider' => 'heygen',
        'config' => portableHeygenConfig(),
        'is_active' => false,
    ]);
    $template->save();

    return $template;
}

test('an admin exports a versioned document', function (): void {
    $org = Organization::factory()->create();
    $token = portabilityUser($org);
    portableTemplate($org);

    $response = $this->withToken($token)->getJson('/api/avatar-templates/export');

    $response->assertOk()->assertJsonStructure([
        'schema', 'exported_at',
        'templates' => [['name', 'description', 'provider', 'config']],
    ]);

    expect($response->json('schema'))->toBe('beai.avatar-template/1')
        ->and($response->json('templates.0.config.avatarId'))->toBe(portableHeygenConfig()['avatarId']);
});

test('an operator may neither export nor import', function (string $role): void {
    $org = Organization::factory()->create();
    $token = portabilityUser($org, $role);

    $this->withToken($token)->getJson('/api/avatar-templates/export')->assertForbidden();
    $this->withToken($token)->postJson('/api/avatar-templates/import', [
        'schema' => 'beai.avatar-template/1',
        'templates' => [],
    ])->assertForbidden();
})->with(['operator', 'viewer']);

test('a valid document imports as inactive templates', function (): void {
    $org = Organization::factory()->create();
    $token = portabilityUser($org);

    $response = $this->withToken($token)->postJson('/api/avatar-templates/import', [
        'schema' => 'beai.avatar-template/1',
        'templates' => [[
            'name' => 'Imported IT',
            'provider' => 'heygen',
            'config' => portableHeygenConfig(),
        ]],
    ]);

    $response->assertCreated();

    $created = AvatarTemplate::withoutGlobalScopes()->where('name', 'Imported IT')->firstOrFail();

    // Activation stays a deliberate, separate act: an import must never change
    // which avatar an organization's live interviews are running on.
    expect($created->is_active)->toBeFalse()
        ->and($created->organization_id)->toBe($org->id);
});

test('an unknown provider key is refused, naming the key, and nothing is created', function (): void {
    $org = Organization::factory()->create();
    $token = portabilityUser($org);

    $response = $this->withToken($token)->postJson('/api/avatar-templates/import', [
        'schema' => 'beai.avatar-template/1',
        'templates' => [[
            'name' => 'Bad',
            'provider' => 'heygen',
            'config' => portableHeygenConfig() + ['inventedKnob' => true],
        ]],
    ]);

    $response->assertUnprocessable();

    expect(json_encode($response->json()))->toContain('inventedKnob')
        ->and(AvatarTemplate::withoutGlobalScopes()->where('name', 'Bad')->exists())->toBeFalse();
});

test('a colliding name creates rather than overwrites', function (): void {
    $org = Organization::factory()->create();
    $token = portabilityUser($org);
    $existing = portableTemplate($org, 'Interviewer IT');
    $originalId = $existing->id;

    $this->withToken($token)->postJson('/api/avatar-templates/import', [
        'schema' => 'beai.avatar-template/1',
        'templates' => [[
            'name' => 'Interviewer IT',
            'provider' => 'heygen',
            // Same config on purpose: this test is about the NAME colliding,
            // and a config difference would only muddy what is being asserted.
            'config' => portableHeygenConfig(),
        ]],
    ])->assertCreated();

    $existing->refresh();

    // Overwriting would silently change what a live project runs on, with
    // nothing shown of what was lost.
    expect($existing->id)->toBe($originalId)
        ->and($existing->name)->toBe('Interviewer IT')
        ->and(AvatarTemplate::withoutGlobalScopes()->where('organization_id', $org->id)->count())->toBe(2)
        ->and(AvatarTemplate::withoutGlobalScopes()->where('name', 'Interviewer IT (2)')->exists())->toBeTrue();
});

test('an entry carrying two providers becomes two templates', function (): void {
    $org = Organization::factory()->create();
    $token = portabilityUser($org);

    $this->withToken($token)->postJson('/api/avatar-templates/import', [
        'schema' => 'beai.avatar-template/1',
        'templates' => [[
            'name' => 'Dual',
            'configs' => [
                'heygen' => portableHeygenConfig(),
                'tavus' => ['palId' => 'p8a490c4dfd4', 'faceId' => 'rf4e9d9790f0', 'language' => 'en'],
            ],
        ]],
    ])->assertCreated();

    $created = AvatarTemplate::withoutGlobalScopes()
        ->where('organization_id', $org->id)
        ->pluck('provider')
        ->sort()
        ->values()
        ->all();

    // A BEAI template belongs to one provider and that provider is immutable
    // after creation, so a dual-config entry cannot collapse into one row.
    expect($created)->toBe(['heygen', 'tavus']);
});

test('an unrecognised schema is refused before anything is created', function (): void {
    $org = Organization::factory()->create();
    $token = portabilityUser($org);

    $response = $this->withToken($token)->postJson('/api/avatar-templates/import', [
        'schema' => 'beai.avatar-template/99',
        'templates' => [['name' => 'X', 'provider' => 'heygen', 'config' => portableHeygenConfig()]],
    ]);

    $response->assertUnprocessable();
    expect(AvatarTemplate::withoutGlobalScopes()->where('organization_id', $org->id)->count())->toBe(0);
});

test('an export round-trips through import', function (): void {
    $org = Organization::factory()->create();
    $token = portabilityUser($org);
    portableTemplate($org, 'Round Trip');

    $document = $this->withToken($token)->getJson('/api/avatar-templates/export')->json();

    $this->withToken($token)->postJson('/api/avatar-templates/import', $document)->assertCreated();

    $names = AvatarTemplate::withoutGlobalScopes()
        ->where('organization_id', $org->id)
        ->pluck('name')
        ->all();

    expect($names)->toHaveCount(2)
        ->and($names)->toContain('Round Trip');
});
