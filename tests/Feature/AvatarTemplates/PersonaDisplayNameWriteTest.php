<?php

declare(strict_types=1);

/**
 * `persona.display_name` on every avatar-template write path (live-interview-call-chrome, T-A3.3).
 *
 * The same rule (`PersonaDisplayName`) guards create and update on BOTH surfaces, the organization
 * one (`/api/avatar-templates`) and the platform one (`/api/admin/avatar-templates`), because both
 * controllers share `ValidatesAvatarTemplateWrites`. Clearing the field UNSETS the key (never a
 * stored null or empty string), and a write that carries a persona changes only `display_name`:
 * other keys an import brought in survive.
 *
 * REQ: avatar-templates "A template MAY carry an interviewer display name"
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

dataset('pdnSurfaces', ['organization', 'platform']);

/**
 * @return array{0: string, 1: string, 2: Organization} the collection URL, the bearer, the organization
 */
function pdnSurface(string $surface): array
{
    $org = Organization::factory()->create();

    return $surface === 'organization'
        ? ['/api/avatar-templates', TemplateActors::token('acting', $org), $org]
        : ['/api/admin/avatar-templates', TemplateActors::token('bare', $org), $org];
}

/**
 * @param  array<string, mixed>|null  $persona
 */
function pdnSeed(string $surface, Organization $org, ?array $persona): AvatarTemplate
{
    if ($surface === 'platform') {
        return PlatformTemplates::insertGlobal(['persona' => $persona === null ? null : json_encode($persona)]);
    }

    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Seed '.uniqid(),
        'provider' => 'heygen',
        'config' => ['avatarId' => 'av', 'voiceId' => 'vo'],
        'persona' => $persona,
    ]));
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function pdnCreatePayload(array $extra = []): array
{
    return array_merge([
        'name' => 'Persona '.uniqid(),
        'provider' => 'heygen',
        'config' => ['avatarId' => 'av_1', 'voiceId' => 'vo_1'],
    ], $extra);
}

/** @return array<string, mixed>|null */
function pdnStoredPersona(int $id): ?array
{
    $persona = AvatarTemplate::withoutGlobalScopes()->findOrFail($id)->persona;

    // jsonb keeps keys in its own order; the keys are what is asserted, not their order.
    if ($persona !== null) {
        ksort($persona);
    }

    return $persona;
}

function pdnTemplateCount(): int
{
    return AvatarTemplate::withoutGlobalScopes()->count();
}

// ─── Refusals (create and update) ────────────────────────────────────────────

test('an invalid persona is refused on create and nothing is written', function (string $surface, array $persona, string $errorKey): void {
    [$url, $token] = pdnSurface($surface);
    $before = pdnTemplateCount();

    $this->withToken($token)->postJson($url, pdnCreatePayload(['persona' => $persona]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);

    expect(pdnTemplateCount())->toBe($before);
})->with('pdnSurfaces')->with([
    'too long' => [['display_name' => str_repeat('a', 61)], 'persona.display_name'],
    'markup' => [['display_name' => '<b>Giulia</b>'], 'persona.display_name'],
    'control character' => [['display_name' => "Giu\x07lia"], 'persona.display_name'],
    'provider name' => [['display_name' => 'Tavus Coach'], 'persona.display_name'],
    'an unknown persona key' => [['display_name' => 'Giulia', 'voice' => 'x'], 'persona'],
]);

test('an invalid persona is refused on update and the stored persona is unchanged', function (string $surface, array $persona, string $errorKey): void {
    [$url, $token, $org] = pdnSurface($surface);
    $template = pdnSeed($surface, $org, ['display_name' => 'Giulia']);

    $this->withToken($token)->patchJson("{$url}/{$template->id}", ['persona' => $persona])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);

    expect(pdnStoredPersona($template->id))->toBe(['display_name' => 'Giulia']);
})->with('pdnSurfaces')->with([
    'too long' => [['display_name' => str_repeat('a', 61)], 'persona.display_name'],
    'markup' => [['display_name' => 'Gi>ulia'], 'persona.display_name'],
    'provider name' => [['display_name' => 'My LiveKit agent'], 'persona.display_name'],
    'an unknown persona key' => [['display_name' => 'Giulia', 'voice' => 'x'], 'persona'],
]);

test('the refusal never repeats the vendor name', function (string $surface): void {
    [$url, $token] = pdnSurface($surface);

    $response = $this->withToken($token)->postJson($url, pdnCreatePayload(['persona' => ['display_name' => 'Tavus Coach']]))
        ->assertUnprocessable();

    expect(mb_strtolower($response->getContent()))->not->toContain('tavus');
})->with('pdnSurfaces');

test('a persona error is listed together with another invalid field', function (string $surface): void {
    [$url, $token] = pdnSurface($surface);

    $this->withToken($token)->postJson($url, pdnCreatePayload([
        'provider' => 'nope',
        'persona' => ['display_name' => '<b>'],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['provider', 'persona.display_name']);
})->with('pdnSurfaces');

// ─── Accepted writes ─────────────────────────────────────────────────────────

test('a valid name is trimmed and stored on create and on update', function (string $surface): void {
    [$url, $token, $org] = pdnSurface($surface);

    $created = $this->withToken($token)->postJson($url, pdnCreatePayload(['persona' => ['display_name' => '  Giulia  ']]))
        ->assertCreated();
    $id = (int) $created->json('data.id');
    expect(pdnStoredPersona($id))->toBe(['display_name' => 'Giulia']);

    $this->withToken($token)->patchJson("{$url}/{$id}", ['persona' => ['display_name' => '  Marco  ']])->assertOk();
    expect(pdnStoredPersona($id))->toBe(['display_name' => 'Marco']);
})->with('pdnSurfaces');

test('a create without a persona stores none', function (string $surface): void {
    [$url, $token] = pdnSurface($surface);

    $id = (int) $this->withToken($token)->postJson($url, pdnCreatePayload())->assertCreated()->json('data.id');

    expect(pdnStoredPersona($id))->toBeNull();
})->with('pdnSurfaces');

// ─── Merge semantics ─────────────────────────────────────────────────────────

test('an update without a persona leaves the column untouched', function (string $surface): void {
    [$url, $token, $org] = pdnSurface($surface);
    $template = pdnSeed($surface, $org, ['display_name' => 'Giulia', 'imported' => 'kept']);

    $this->withToken($token)->patchJson("{$url}/{$template->id}", ['description' => 'Only the description'])->assertOk();

    expect(pdnStoredPersona($template->id))->toBe(['display_name' => 'Giulia', 'imported' => 'kept']);
})->with('pdnSurfaces');

test('a new name keeps the other persona keys an import brought in', function (string $surface): void {
    [$url, $token, $org] = pdnSurface($surface);
    $template = pdnSeed($surface, $org, ['display_name' => 'Giulia', 'imported' => 'kept']);

    $this->withToken($token)->patchJson("{$url}/{$template->id}", ['persona' => ['display_name' => 'Marco']])->assertOk();

    expect(pdnStoredPersona($template->id))->toBe(['display_name' => 'Marco', 'imported' => 'kept']);
})->with('pdnSurfaces');

test('clearing the name unsets the key, never storing null or an empty string', function (string $surface, array $persona): void {
    [$url, $token, $org] = pdnSurface($surface);
    $withExtra = pdnSeed($surface, $org, ['display_name' => 'Giulia', 'imported' => 'kept']);
    $alone = pdnSeed($surface, $org, ['display_name' => 'Giulia']);

    $this->withToken($token)->patchJson("{$url}/{$withExtra->id}", ['persona' => $persona])->assertOk();
    $this->withToken($token)->patchJson("{$url}/{$alone->id}", ['persona' => $persona])->assertOk();

    expect(pdnStoredPersona($withExtra->id))->toBe(['imported' => 'kept'])
        ->and(pdnStoredPersona($alone->id))->toBeNull();
})->with('pdnSurfaces')->with([
    'an empty persona object' => [[]],
    'a null display name' => [['display_name' => null]],
    'a blank display name' => [['display_name' => '   ']],
]);
