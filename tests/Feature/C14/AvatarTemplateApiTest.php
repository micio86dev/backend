<?php

declare(strict_types=1);

/**
 * The avatar-template API (C14 PR4).
 *
 * Admin-only, org-scoped, and the activation endpoint is the interesting one:
 * it must swap the active template ATOMICALLY. The database index forbids two
 * active rows, so a naive "activate the new one, then deactivate the old" fails
 * outright — and the reverse order leaves a window with none active, during
 * which an interview starting would fall back to the environment defaults.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function templateActor(Organization $org, string $role): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate([
        'name' => $role, 'guard_name' => 'api', 'team_id' => $org->id,
    ]));

    return auth('api')->login($user);
}

function seedTemplate(Organization $org, array $attributes = []): AvatarTemplate
{
    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create(array_merge([
        'name' => 'T'.uniqid(),
        'provider' => 'heygen',
        'config' => ['avatarId' => 'av', 'voiceId' => 'vo'],
    ], $attributes)));
}

$validPayload = [
    'name' => 'Recruiter voice',
    'provider' => 'heygen',
    'config' => ['avatarId' => 'av_1', 'voiceId' => 'vo_1'],
];

// ─── RBAC ────────────────────────────────────────────────────────────────────

test('an unauthenticated caller gets 401', function (): void {
    $this->getJson('/api/avatar-templates')->assertUnauthorized();
});

test('operator and viewer are refused', function (): void {
    $org = Organization::factory()->create();

    foreach (['operator', 'viewer'] as $role) {
        // Choosing the face and voice every candidate of an organization meets
        // is a brand decision, not a day-to-day one. Read access is withheld
        // too: the config carries provider-side identifiers that are closer to
        // credentials than to settings.
        $this->withToken(templateActor($org, $role))
            ->getJson('/api/avatar-templates')
            ->assertForbidden();
    }
});

test('an admin may list', function (): void {
    $org = Organization::factory()->create();
    seedTemplate($org);

    $this->withToken(templateActor($org, 'admin'))
        ->getJson('/api/avatar-templates')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ─── Tenancy ─────────────────────────────────────────────────────────────────

test('a listing never shows another tenant\'s templates', function (): void {
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    seedTemplate($theirs, ['name' => 'Not yours']);

    $this->withToken(templateActor($mine, 'admin'))
        ->getJson('/api/avatar-templates')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('another tenant\'s template is a 404, not a 403', function (): void {
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    $template = seedTemplate($theirs);

    // 404 rather than 403 on purpose: a 403 would confirm the id exists, which
    // turns the endpoint into an enumeration oracle for other tenants' data.
    $this->withToken(templateActor($mine, 'admin'))
        ->getJson("/api/avatar-templates/{$template->id}")
        ->assertNotFound();
});

// ─── Create ──────────────────────────────────────────────────────────────────

test('an admin can create a template', function () use ($validPayload): void {
    $org = Organization::factory()->create();

    $this->withToken(templateActor($org, 'admin'))
        ->postJson('/api/avatar-templates', $validPayload)
        ->assertCreated()
        ->assertJsonPath('data.name', 'Recruiter voice')
        ->assertJsonPath('data.is_active', false);
});

test('a created template is never active', function () use ($validPayload): void {
    $org = Organization::factory()->create();
    $existing = seedTemplate($org, ['is_active' => true]);

    $this->withToken(templateActor($org, 'admin'))
        ->postJson('/api/avatar-templates', $validPayload)
        ->assertCreated();

    // Creating a template must never change what candidates are seeing right
    // now. Activation is a separate, deliberate act — and if creation could
    // activate, it would also have to deactivate something, silently.
    expect($existing->fresh()->is_active)->toBeTrue();
});

test('an invalid config is rejected with every error at once, keyed per field', function (): void {
    $org = Organization::factory()->create();

    $response = $this->withToken(templateActor($org, 'admin'))
        ->postJson('/api/avatar-templates', [
            'name' => 'Broken',
            'provider' => 'heygen',
            'config' => ['voiceSpeed' => 99, 'nonsense' => 1],
        ])
        ->assertStatus(422);

    // avatarId + voiceId missing, voiceSpeed out of range, nonsense unknown.
    // All four, in one response, each under its own `config.{knob}` key
    // (generated-client-truth-and-session-safety D6) — one error per round
    // trip turns filling in a form into a guessing game, and a field-keyed
    // shape is what lets the client place each one without parsing text.
    $response->assertJsonValidationErrors([
        'config.avatarId', 'config.voiceId', 'config.voiceSpeed', 'config.nonsense',
    ])->assertJsonMissingValidationErrors(['config']);
});

test('an unknown provider is rejected', function (): void {
    $org = Organization::factory()->create();

    $this->withToken(templateActor($org, 'admin'))
        ->postJson('/api/avatar-templates', ['name' => 'X', 'provider' => 'openai', 'config' => []])
        ->assertStatus(422);
});

test('a duplicate name in the same org is a 422, not a 500', function (): void {
    $org = Organization::factory()->create();
    seedTemplate($org, ['name' => 'Taken']);

    // The unique index would otherwise surface as a QueryException → 500. A
    // name collision is a thing the operator can fix, so it must read as one.
    $this->withToken(templateActor($org, 'admin'))
        ->postJson('/api/avatar-templates', ['name' => 'Taken', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v']])
        ->assertStatus(422);
});

// ─── Activation ──────────────────────────────────────────────────────────────

test('activating a template deactivates the previous one', function (): void {
    $org = Organization::factory()->create();
    $old = seedTemplate($org, ['is_active' => true]);
    $new = seedTemplate($org);

    $this->withToken(templateActor($org, 'admin'))
        ->postJson("/api/avatar-templates/{$new->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    expect($old->fresh()->is_active)->toBeFalse();
});

test('activation never leaves the organization with two active templates', function (): void {
    $org = Organization::factory()->create();
    seedTemplate($org, ['is_active' => true]);
    $new = seedTemplate($org);

    $this->withToken(templateActor($org, 'admin'))
        ->postJson("/api/avatar-templates/{$new->id}/activate")
        ->assertOk();

    $active = TenantContextScope::runFor($org->id, fn (): int => AvatarTemplate::where('is_active', true)->count());

    // The index would refuse a second active row anyway — this asserts the
    // controller does not RELY on that refusal, which would mean a 500 where
    // the operator expects a swap.
    expect($active)->toBe(1);
});

test('activating a Tavus template does not deactivate an active HeyGen template (pluggable-conversation-llm P0)', function (): void {
    $org = Organization::factory()->create();
    $heygen = seedTemplate($org, ['provider' => 'heygen', 'is_active' => true]);
    $tavus = seedTemplate($org, ['provider' => 'tavus', 'name' => 'T-tavus-'.uniqid(), 'config' => ['faceId' => 'f', 'palId' => 'p']]);

    $this->withToken(templateActor($org, 'admin'))
        ->postJson("/api/avatar-templates/{$tavus->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    // Both are now active simultaneously — the deactivate query is narrowed
    // to the SAME provider as the template being activated (D0).
    expect($heygen->fresh()->is_active)->toBeTrue();
    expect($tavus->fresh()->is_active)->toBeTrue();
});

test('activating a template still deactivates the prior active template on the SAME provider (pluggable-conversation-llm P0)', function (): void {
    $org = Organization::factory()->create();
    $oldTavus = seedTemplate($org, ['provider' => 'tavus', 'is_active' => true, 'config' => ['faceId' => 'f1', 'palId' => 'p1']]);
    $newTavus = seedTemplate($org, ['provider' => 'tavus', 'name' => 'T-tavus-new-'.uniqid(), 'config' => ['faceId' => 'f2', 'palId' => 'p2']]);

    $this->withToken(templateActor($org, 'admin'))
        ->postJson("/api/avatar-templates/{$newTavus->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    expect($oldTavus->fresh()->is_active)->toBeFalse();

    $activeTavusCount = TenantContextScope::runFor(
        $org->id,
        fn (): int => AvatarTemplate::where('is_active', true)->where('provider', 'tavus')->count(),
    );

    expect($activeTavusCount)->toBe(1);
});

test('activating the already-active template is a no-op, not an error', function (): void {
    $org = Organization::factory()->create();
    $active = seedTemplate($org, ['is_active' => true]);

    // Idempotent because a double-click is not a mistake worth an error page.
    $this->withToken(templateActor($org, 'admin'))
        ->postJson("/api/avatar-templates/{$active->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

test('a template with an invalid config cannot be activated', function (): void {
    $org = Organization::factory()->create();
    // Written straight to the database, bypassing the API — which is how a
    // config goes stale: the field spec changes, and a template saved under the
    // old one is still sitting there.
    $broken = seedTemplate($org, ['config' => ['avatarId' => 'only-half']]);

    $this->withToken(templateActor($org, 'admin'))
        ->postJson("/api/avatar-templates/{$broken->id}/activate")
        ->assertStatus(422);

    // Activation is the last moment anybody can catch this before a candidate
    // does. Validating only at write time trusts that nothing else ever writes.
    expect($broken->fresh()->is_active)->toBeFalse();
});

test('an operator cannot activate', function (): void {
    $org = Organization::factory()->create();
    $template = seedTemplate($org);

    $this->withToken(templateActor($org, 'operator'))
        ->postJson("/api/avatar-templates/{$template->id}/activate")
        ->assertForbidden();
});

test('a template from another tenant cannot be activated', function (): void {
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    $template = seedTemplate($theirs);

    $this->withToken(templateActor($mine, 'admin'))
        ->postJson("/api/avatar-templates/{$template->id}/activate")
        ->assertNotFound();
});

// ─── Update and delete ───────────────────────────────────────────────────────

test('an admin can rename a template', function (): void {
    $org = Organization::factory()->create();
    $template = seedTemplate($org, ['name' => 'Before']);

    $this->withToken(templateActor($org, 'admin'))
        ->patchJson("/api/avatar-templates/{$template->id}", ['name' => 'After'])
        ->assertOk()
        ->assertJsonPath('data.name', 'After');
});

test('the provider cannot be changed after creation', function (): void {
    $org = Organization::factory()->create();
    $template = seedTemplate($org, ['provider' => 'heygen']);

    $this->withToken(templateActor($org, 'admin'))
        ->patchJson("/api/avatar-templates/{$template->id}", ['provider' => 'tavus'])
        ->assertStatus(422);

    // Switching provider would leave every knob in the config belonging to the
    // other one — avatarId where faceId is expected, and nothing overlapping.
    // Making a new template is one click and leaves an audit trail.
    expect($template->fresh()->provider)->toBe('heygen');
});

test('deleting the active template is refused', function (): void {
    $org = Organization::factory()->create();
    $active = seedTemplate($org, ['is_active' => true]);

    $this->withToken(templateActor($org, 'admin'))
        ->deleteJson("/api/avatar-templates/{$active->id}")
        ->assertStatus(409);

    // Deleting what candidates are currently being interviewed with is a
    // decision, not a cleanup. Activate something else first.
    expect($active->fresh())->not->toBeNull();
});

test('deleting an inactive template returns 204', function (): void {
    $org = Organization::factory()->create();
    $template = seedTemplate($org);

    $this->withToken(templateActor($org, 'admin'))
        ->deleteJson("/api/avatar-templates/{$template->id}")
        ->assertNoContent();
});

test('deleting a bound HeyGen template issues the DELETE and clears the ledger column (PR P5)', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');

    $org = Organization::factory()->create();
    $template = seedTemplate($org);
    $template->forceFill(['heygen_llm_configuration_id' => 'cfg-to-delete'])->saveQuietly();

    Http::fake(['*liveavatar.com/v1/llm-configurations/cfg-to-delete' => Http::response([], 200)]);

    $this->withToken(templateActor($org, 'admin'))
        ->deleteJson("/api/avatar-templates/{$template->id}")
        ->assertNoContent();

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/v1/llm-configurations/cfg-to-delete'));
});

// ─── Field specs ─────────────────────────────────────────────────────────────

test('the field spec endpoint describes both providers', function (): void {
    $org = Organization::factory()->create();

    $response = $this->withToken(templateActor($org, 'admin'))
        ->getJson('/api/avatar-templates/field-specs')
        ->assertOk();

    // Served rather than duplicated in the Nuxt app, so the form, the
    // validation and the provider payload cannot disagree — the whole reason
    // the spec is declarative.
    expect($response->json('data.heygen'))->not->toBeEmpty();
    expect($response->json('data.tavus'))->not->toBeEmpty();
});

test('the field spec carries label KEYS, never rendered text', function (): void {
    $org = Organization::factory()->create();

    $response = $this->withToken(templateActor($org, 'admin'))
        ->getJson('/api/avatar-templates/field-specs');

    foreach ($response->json('data.heygen') as $field) {
        // The API is machine-facing and is not localized. Translation happens
        // in the backoffice, which is where the operator's locale lives.
        expect($field['label_key'])->toStartWith('avatar_templates.field.');
    }
});

test('every role can read the PICKER list, and it carries no provider identifiers', function (): void {
    // `projects.avatar_template_id` is NOT NULL, so an operator creating a
    // project must choose a template — and an operator who cannot list them
    // cannot choose. Widening `viewAny` was tried and reverted: this resource
    // carries `config`, which holds avatarId/voiceId/faceId/palId, closer to
    // credentials than to settings. This endpoint is the narrow answer.
    $org = Organization::factory()->create();
    $template = seedTemplate($org);

    foreach (['admin', 'operator', 'viewer'] as $role) {
        $response = $this->withToken(templateActor($org, $role))->getJson('/api/avatar-templates/options');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $template->id);

        // The key set is the assertion. A future field on
        // `AvatarTemplateResource` must not silently become readable by every
        // role, which is why this endpoint builds its own shape.
        expect(array_keys($response->json('data.0')))
            ->toBe(['id', 'name', 'provider', 'is_active']);
    }
});

test('the picker list does NOT open the full resource to a non-admin', function (): void {
    $org = Organization::factory()->create();
    seedTemplate($org);

    foreach (['operator', 'viewer'] as $role) {
        $this->withToken(templateActor($org, $role))
            ->getJson('/api/avatar-templates')
            ->assertForbidden();
    }
});
