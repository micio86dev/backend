<?php

declare(strict_types=1);

/**
 * The template actually reaches the provider (C14 PR5).
 *
 * Everything before this PR was preparation: a table, a validator, an API. None
 * of it changed a single byte sent to HeyGen or Tavus. This is the part that
 * makes an operator's choice visible to a candidate.
 *
 * The mapper is a pure function on purpose. "Does the avatar id an operator
 * typed end up in the request body" should be one assertion, not an integration
 * test with a fake HTTP server.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\AvatarTemplates\ActiveTemplateResolver;
use App\Support\AvatarTemplates\TemplatePayload;
use App\Support\Tenancy\TenantContextScope;

function activeTemplate(Organization $org, string $provider, array $config): AvatarTemplate
{
    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Active',
        'provider' => $provider,
        'config' => $config,
        'is_active' => true,
    ]));
}

// ─── Resolution ──────────────────────────────────────────────────────────────

test('the resolver finds the organization\'s active template', function (): void {
    $org = Organization::factory()->create();
    activeTemplate($org, 'heygen', ['avatarId' => 'av_1', 'voiceId' => 'vo_1']);

    $found = TenantContextScope::runFor($org->id, fn () => app(ActiveTemplateResolver::class)->resolve('heygen'));

    expect($found?->config['avatarId'])->toBe('av_1');
});

test('an organization with no active template resolves to null, not an error', function (): void {
    $org = Organization::factory()->create();

    // Every existing tenant is in this state the moment this ships. Failing
    // here would break every interview in the product to deliver a feature
    // nobody has configured yet.
    $found = TenantContextScope::runFor($org->id, fn () => app(ActiveTemplateResolver::class)->resolve('heygen'));

    expect($found)->toBeNull();
});

test('an inactive template is not resolved', function (): void {
    $org = Organization::factory()->create();
    TenantContextScope::runFor($org->id, fn () => AvatarTemplate::create([
        'name' => 'Draft', 'provider' => 'heygen', 'config' => ['avatarId' => 'x', 'voiceId' => 'y'],
    ]));

    expect(TenantContextScope::runFor($org->id, fn () => app(ActiveTemplateResolver::class)->resolve('heygen')))->toBeNull();
});

test('the resolver never crosses tenants', function (): void {
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    activeTemplate($theirs, 'heygen', ['avatarId' => 'not-mine', 'voiceId' => 'v']);

    expect(TenantContextScope::runFor($mine->id, fn () => app(ActiveTemplateResolver::class)->resolve('heygen')))->toBeNull();
});

// ─── HeyGen mapping ──────────────────────────────────────────────────────────

test('the avatar and voice an operator chose reach the HeyGen body', function (): void {
    $payload = TemplatePayload::heygen([
        'avatarId' => 'Ann_Therapist_public',
        'voiceId' => 'en-US-JennyNeural',
    ]);

    // The whole point of the feature, in one assertion.
    expect($payload['avatar_id'])->toBe('Ann_Therapist_public');
    expect($payload['avatar_persona']['voice_id'])->toBe('en-US-JennyNeural');
});

test('HeyGen voice tuning is nested where HeyGen expects it', function (): void {
    $payload = TemplatePayload::heygen([
        'avatarId' => 'a', 'voiceId' => 'v',
        'voiceSpeed' => 1.1, 'voiceStability' => 0.4, 'voiceUseSpeakerBoost' => true,
    ]);

    // Flat keys would be accepted by the API and silently ignored — the worst
    // failure available, because the operator sees a saved setting and hears no
    // difference.
    expect($payload['voice_settings']['speed'])->toBe(1.1);
    expect($payload['voice_settings']['stability'])->toBe(0.4);
    expect($payload['voice_settings']['use_speaker_boost'])->toBeTrue();
});

test('unset HeyGen knobs are OMITTED, never sent as null', function (): void {
    $payload = TemplatePayload::heygen(['avatarId' => 'a', 'voiceId' => 'v']);

    // A null tells the provider "use null"; an absent key tells it "use your
    // default". Those are different requests, and only one of them is what an
    // operator who left a field blank meant.
    expect($payload)->not->toHaveKey('video_settings');
    expect($payload)->not->toHaveKey('voice_settings');
});

test('the HeyGen session duration is clamped to the plan ceiling', function (): void {
    $payload = TemplatePayload::heygen(['avatarId' => 'a', 'voiceId' => 'v', 'maxSessionDurationSec' => 99999]);

    // Belt and braces. The field spec caps this at save time, but a config
    // written before the cap existed — or straight to the database — would
    // otherwise be rejected by HeyGen at session start, in front of a
    // candidate rather than in front of the operator who set it.
    expect($payload['max_session_duration'])->toBe(1200);
});

// ─── Tavus mapping ───────────────────────────────────────────────────────────

test('the face and persona an operator chose reach the Tavus body', function (): void {
    $payload = TemplatePayload::tavus(['faceId' => 'r_abc', 'palId' => 'p_xyz']);

    expect($payload['replica_id'])->toBe('r_abc');
    expect($payload['persona_id'])->toBe('p_xyz');
});

test('neither provider mapping emits a language — the project owns it', function (): void {
    // Superseded (avatar-language-follows-project, D1). A template used to be
    // able to override the avatar's spoken language, which `avatar_templates`
    // cannot express correctly: it is scoped by organization with no project_id,
    // while `project.language` is per project.
    //
    // The vocabulary this test used to assert now lives in
    // App\Support\Provider\TavusLanguage, with its own unit test, and is applied
    // by the provider's platform default from the project's locale — at
    // `properties.language`, which is where Tavus actually reads it.
    expect(TemplatePayload::tavus(['faceId' => 'r', 'palId' => 'p', 'language' => 'it']))
        ->not->toHaveKey('language');

    expect(TemplatePayload::heygen(['avatarId' => 'a', 'language' => 'it'])['avatar_persona'] ?? [])
        ->not->toHaveKey('language');
});

test('Tavus call properties are nested under properties', function (): void {
    $payload = TemplatePayload::tavus([
        'faceId' => 'r', 'palId' => 'p',
        'maxCallDurationSec' => 900, 'participantAbsentTimeoutSec' => 60,
    ]);

    expect($payload['properties']['max_call_duration'])->toBe(900);
    expect($payload['properties']['participant_absent_timeout'])->toBe(60);
});

test('persona-level knobs are NOT sent on the conversation', function (): void {
    $payload = TemplatePayload::tavus([
        'faceId' => 'r', 'palId' => 'p',
        'llmTemperature' => 0.7, 'ttsEngine' => 'cartesia',
    ]);

    // Tavus splits its configuration across two API objects. A persona knob on
    // a conversation is silently ignored — no error, no effect, and an operator
    // watching a setting do nothing. They belong in the PAL.
    expect(json_encode($payload))->not->toContain('0.7');
    expect(json_encode($payload))->not->toContain('cartesia');
});

// ─── The PAL layers ──────────────────────────────────────────────────────────

test('persona knobs become nested PAL layers at their declared paths', function (): void {
    $layers = TemplatePayload::tavusPalLayers([
        'llmTemperature' => 0.7,
        'llmSpeculativeInference' => true,
        'ttsEngine' => 'cartesia',
        'turnTakingPatience' => 'high',
    ]);

    // The paths come from the field spec's palPath, so adding a knob is a
    // one-line change in one file rather than an edit here as well.
    expect($layers['llm']['extra_body']['temperature'])->toBe(0.7);
    expect($layers['llm']['speculative_inference'])->toBeTrue();
    expect($layers['tts']['tts_engine'])->toBe('cartesia');
    expect($layers['conversational_flow']['turn_taking_patience'])->toBe('high');
});

test('a config with no persona knobs produces no layers', function (): void {
    // Sent as an empty object, a PATCH would wipe the persona's existing
    // layers. Nothing to say must mean saying nothing.
    expect(TemplatePayload::tavusPalLayers(['faceId' => 'r', 'palId' => 'p']))->toBe([]);
});

test('conversation-level knobs never leak into the PAL', function (): void {
    $layers = TemplatePayload::tavusPalLayers(['faceId' => 'r', 'palId' => 'p', 'audioOnly' => true]);

    expect(json_encode($layers))->not->toContain('audioOnly');
    expect($layers)->toBe([]);
});

// ─── Degrading without a template ────────────────────────────────────────────

test('a null config yields an empty payload rather than nulls', function (): void {
    // What every organization gets on the day this ships. The provider request
    // must be byte-identical to the one it sent before this feature existed —
    // otherwise installing the change breaks every tenant who has not
    // configured a template yet.
    expect(TemplatePayload::heygen([]))->toBe([]);
    expect(TemplatePayload::tavus([]))->toBe([]);
});
