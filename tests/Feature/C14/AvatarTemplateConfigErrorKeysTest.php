<?php

declare(strict_types=1);

/**
 * AvatarTemplateConfigErrorKeysTest (generated-client-truth-and-session-safety D6).
 *
 * REQ: Config Validation Errors Are Keyed Per Field
 * (openspec/changes/generated-client-truth-and-session-safety/specs/avatar-templates/spec.md)
 *
 * `AvatarTemplateController::assertConfigValid` collapsed every knob's error
 * into one formatted-string array under a single `config` key, forcing the
 * client to parse message text to place it under the right control. This
 * asserts the field-level replacement: `config.{knob}`, one entry per
 * invalid key, never a combined `config` array.
 */

use App\Models\Organization;

function configErrorActor(Organization $org): string
{
    // The SUPERADMIN acting as this organization. Managing avatar templates
    // stopped being a client action on 2026-09-02 — a client selects a
    // template, it does not author one — and the templates themselves stay
    // TENANT data, because AvatarTemplate is a TenantModel that binds a
    // tenant-scoped `llm_credential_id`. Acting as the org is therefore the
    // product behaviour, not a test shortcut: a superadmin with no client
    // selected cannot create a tenant-scoped model at all.
    return authTokenForRole($org, 'platform');
}

test('two invalid knobs produce two field-keyed errors, never a combined config key', function (): void {
    $org = Organization::factory()->create();

    $response = $this->withToken(configErrorActor($org))
        ->postJson('/api/avatar-templates', [
            'name' => 'Broken',
            'provider' => 'heygen',
            // avatarId missing (required); voiceSpeed out of [0.8, 1.2] range.
            'config' => ['voiceId' => 'vo', 'voiceSpeed' => 99],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['config.avatarId', 'config.voiceSpeed'])
        ->assertJsonMissingValidationErrors(['config']);
});

test('a single invalid knob still routes correctly — exactly one config.{knob} key, nothing else', function (): void {
    $org = Organization::factory()->create();

    $response = $this->withToken(configErrorActor($org))
        ->postJson('/api/avatar-templates', [
            'name' => 'Broken',
            'provider' => 'heygen',
            // avatarId and voiceId are both valid; voiceSpeed alone is out
            // of [0.8, 1.2] range — the ONLY invalid knob (spec.md's "A
            // single invalid knob still routes correctly" scenario).
            'config' => ['avatarId' => 'av', 'voiceId' => 'vo', 'voiceSpeed' => 99],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['config.voiceSpeed'])
        ->assertJsonMissingValidationErrors(['config']);

    expect(array_keys($response->json('errors')))->toBe(['config.voiceSpeed']);
});

test('an unknown knob is keyed under config.{knob}', function (): void {
    $org = Organization::factory()->create();

    $response = $this->withToken(configErrorActor($org))
        ->postJson('/api/avatar-templates', [
            'name' => 'Broken',
            'provider' => 'heygen',
            'config' => ['avatarId' => 'av', 'voiceId' => 'vo', 'wat' => 'nonsense'],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['config.wat'])
        ->assertJsonMissingValidationErrors(['config']);
});

test('a non-array config fails at the bare config key, never a config.* key', function (): void {
    $org = Organization::factory()->create();

    $response = $this->withToken(configErrorActor($org))
        ->postJson('/api/avatar-templates', [
            'name' => 'Broken',
            'provider' => 'heygen',
            'config' => 'not-an-array',
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['config']);

    $errors = array_keys($response->json('errors'));
    foreach ($errors as $key) {
        expect($key)->not->toStartWith('config.');
    }
});
