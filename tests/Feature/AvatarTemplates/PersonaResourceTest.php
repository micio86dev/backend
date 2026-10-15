<?php

declare(strict_types=1);

/**
 * `persona.display_name` on the admin avatar template resource, and the accessor behind it
 * (live-interview-call-chrome, T-A3.5).
 *
 * The resource returns ONLY `display_name` of the persona, never the raw JSON, so keys an import
 * brought in do not reach the admin surface. A value stored before the write rules existed (an
 * import) degrades to null through `AvatarTemplate::personaDisplayName()`, so the outline and the
 * resource can never render a name the form would refuse. The name is candidate-facing operator
 * text and never travels to the avatar provider.
 *
 * REQ: avatar-templates "A template MAY carry an interviewer display name"
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

dataset('prSurfaces', ['organization', 'platform']);

/**
 * @param  array<string, mixed>|null  $persona
 * @return array{0: string, 1: string} the template URL and the bearer
 */
function prTemplateUrl(string $surface, ?array $persona): array
{
    $org = Organization::factory()->create();

    if ($surface === 'platform') {
        $template = PlatformTemplates::insertGlobal(['persona' => $persona === null ? null : json_encode($persona)]);

        return ["/api/admin/avatar-templates/{$template->id}", TemplateActors::token('bare', $org)];
    }

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Resource '.uniqid(),
        'provider' => 'heygen',
        'config' => ['avatarId' => 'av', 'voiceId' => 'vo'],
        'persona' => $persona,
    ]));

    return ["/api/avatar-templates/{$template->id}", TemplateActors::token('acting', $org)];
}

test('the resource returns display_name and no other persona key', function (string $surface): void {
    [$url, $token] = prTemplateUrl($surface, ['display_name' => 'Giulia', 'imported' => 'secret-ish']);

    $response = $this->withToken($token)->getJson($url)->assertOk();

    $response->assertJsonPath('data.persona', ['display_name' => 'Giulia']);
    expect($response->getContent())->not->toContain('imported');
})->with('prSurfaces');

test('a template with no persona returns a null display_name', function (string $surface, ?array $persona): void {
    [$url, $token] = prTemplateUrl($surface, $persona);

    $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('data.persona', ['display_name' => null]);
})->with('prSurfaces')->with([
    'no persona at all' => [null],
    'a persona without a name' => [['imported' => 'x']],
]);

test('a stored legacy value no write path would accept degrades to null', function (string $surface, string $stored): void {
    [$url, $token] = prTemplateUrl($surface, ['display_name' => $stored]);

    $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('data.persona.display_name', null);
})->with('prSurfaces')->with([
    'too long' => [str_repeat('a', 61)],
    'markup' => ['<b>Giulia</b>'],
    'a provider name' => ['Tavus Coach'],
    'blank' => ['   '],
]);

test('the accessor trims a valid stored name', function (): void {
    $template = new AvatarTemplate(['persona' => ['display_name' => '  Giulia  ']]);

    expect($template->personaDisplayName())->toBe('Giulia');
});

test('the accessor is null for an absent persona and for a non-string name', function (?array $persona): void {
    expect((new AvatarTemplate(['persona' => $persona]))->personaDisplayName())->toBeNull();
})->with([[null], [[]], [['display_name' => 42]]]);

test('no avatar provider request carries the display name', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 1);
    AvatarTemplate::withoutGlobalScopes()->findOrFail($project->avatar_template_id)->update(['persona' => ['display_name' => 'Giulia Marker']]);
    $participant = casParticipant($org, $project, 'in_attesa');

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    $recorded = Http::recorded();
    expect($recorded)->not->toBeEmpty();
    foreach ($recorded as [$request]) {
        /** @var HttpRequest $request */
        expect($request->body().json_encode($request->headers()))->not->toContain('Giulia Marker');
    }
});
