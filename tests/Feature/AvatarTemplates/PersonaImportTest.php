<?php

declare(strict_types=1);

/**
 * `persona.display_name` through the avatar template JSON import, export and duplicate paths
 * (live-interview-call-chrome, T-A3.4).
 *
 * Import validates the name with the SAME rule as the form (`PersonaDisplayName`), so a file can
 * never install a name the form would refuse. All-or-nothing: one bad entry creates nothing.
 *
 * REQ: avatar-templates "A template MAY carry an interviewer display name"
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $persona
 * @return array{schema: string, templates: list<array<string, mixed>>}
 */
function pimDocument(array $persona, string $name = 'Imported'): array
{
    return [
        'schema' => 'beai.avatar-template/1',
        'templates' => [[
            'name' => $name,
            'provider' => 'heygen',
            'config' => ['avatarId' => 'av_1', 'voiceId' => 'vo_1'],
            'persona' => $persona,
        ]],
    ];
}

test('an invalid persona name fails the import with a persona.display_name error and creates nothing', function (string $name): void {
    $org = Organization::factory()->create();
    $token = TemplateActors::token('acting', $org);
    $before = AvatarTemplate::withoutGlobalScopes()->count();

    $response = $this->withToken($token)->postJson('/api/avatar-templates/import', pimDocument(['display_name' => $name]))
        ->assertUnprocessable();

    expect($response->json('errors')['templates.0'][0])->toStartWith('persona.display_name: ')
        ->and(AvatarTemplate::withoutGlobalScopes()->count())->toBe($before);
})->with([
    'a provider name' => ['Tavus'],
    'too long' => [str_repeat('a', 61)],
    'markup' => ['<b>'],
]);

test('a display name that is not text fails the import cleanly and creates nothing', function (mixed $name): void {
    $token = TemplateActors::token('acting', Organization::factory()->create());
    $before = AvatarTemplate::withoutGlobalScopes()->count();

    $response = $this->withToken($token)->postJson('/api/avatar-templates/import', pimDocument(['display_name' => $name]))
        ->assertUnprocessable();

    expect($response->json('errors')['templates.0'][0])->toStartWith('persona.display_name: ')
        ->and(AvatarTemplate::withoutGlobalScopes()->count())->toBe($before);
})->with([[123], [['Giulia']], [true]]);

test('the refusal does not repeat the vendor name', function (): void {
    $token = TemplateActors::token('acting', Organization::factory()->create());

    $response = $this->withToken($token)->postJson('/api/avatar-templates/import', pimDocument(['display_name' => 'Tavus']))
        ->assertUnprocessable();

    expect(mb_strtolower($response->getContent()))->not->toContain('tavus');
});

test('one bad entry fails the whole document', function (): void {
    $org = Organization::factory()->create();
    $token = TemplateActors::token('acting', $org);
    $document = pimDocument(['display_name' => 'Giulia'], 'Good');
    $document['templates'][] = pimDocument(['display_name' => 'Tavus'], 'Bad')['templates'][0];

    $this->withToken($token)->postJson('/api/avatar-templates/import', $document)
        ->assertUnprocessable()->assertJsonValidationErrors(['templates.1']);

    expect(AvatarTemplate::withoutGlobalScopes()->where('name', 'Good')->exists())->toBeFalse();
});

test('a blank name is dropped on import and a valid one is trimmed', function (array $persona, ?array $expected): void {
    $org = Organization::factory()->create();
    $token = TemplateActors::token('acting', $org);

    $this->withToken($token)->postJson('/api/avatar-templates/import', pimDocument($persona))->assertCreated();

    $stored = AvatarTemplate::withoutGlobalScopes()->where('name', 'Imported')->firstOrFail()->persona;
    expect($stored)->toBe($expected);
})->with([
    'a blank name' => [['display_name' => '   '], null],
    'a null name' => [['display_name' => null], null],
    'a blank name beside another key' => [['display_name' => '', 'imported' => 'kept'], ['imported' => 'kept']],
    'a padded name' => [['display_name' => '  Giulia  '], ['display_name' => 'Giulia']],
]);

test('export then import round-trips the name as an inactive template', function (): void {
    $org = Organization::factory()->create();
    $token = TemplateActors::token('acting', $org);
    TenantContextScope::runFor($org->id, fn () => AvatarTemplate::create([
        'name' => 'Source', 'provider' => 'heygen', 'config' => ['avatarId' => 'av_1', 'voiceId' => 'vo_1'],
        'persona' => ['display_name' => 'Giulia'],
    ]));

    $document = $this->withToken($token)->getJson('/api/avatar-templates/export')->assertOk()->json();
    $document['templates'][0]['name'] = 'Round trip';
    $this->withToken($token)->postJson('/api/avatar-templates/import', $document)->assertCreated();

    $copy = AvatarTemplate::withoutGlobalScopes()->where('name', 'Round trip')->firstOrFail();
    expect($copy->persona)->toBe(['display_name' => 'Giulia'])
        ->and($copy->is_active)->toBeFalse();
});

test('duplicating into another organization keeps the name and leaves the original unchanged', function (): void {
    $source = Organization::factory()->create();
    $target = Organization::factory()->create();
    $original = TenantContextScope::runFor($source->id, fn () => AvatarTemplate::create([
        'name' => 'Original', 'provider' => 'heygen', 'config' => ['avatarId' => 'av_1', 'voiceId' => 'vo_1'],
        'persona' => ['display_name' => 'Giulia'],
    ]));

    $this->withToken(TemplateActors::token('bare', $source))
        ->postJson("/api/avatar-templates/{$original->id}/duplicate", ['target_organization_ids' => [$target->id]])
        ->assertCreated();

    $copy = TenantContextScope::runFor($target->id, fn () => AvatarTemplate::query()->firstOrFail());
    expect($copy->persona)->toBe(['display_name' => 'Giulia'])
        ->and(AvatarTemplate::withoutGlobalScopes()->findOrFail($original->id)->persona)->toBe(['display_name' => 'Giulia']);
});
