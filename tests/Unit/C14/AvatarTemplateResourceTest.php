<?php

declare(strict_types=1);

/**
 * AvatarTemplateResource unit tests (C14).
 *
 * Same schema-lie class as ApiClientResourceTest.php (dates-and-destructive-
 * actions, Phase 0 audit finding, closed here because this change's own
 * diff touches `avatar-templates/index.vue` and increases reliance on this
 * resource's generated type): `AvatarTemplate.php` already declares
 * `@property int $id` / `@property bool $is_active` /
 * `@property array<string,mixed> $config`, and the model casts already make
 * the RUNTIME values correct. Only the generated schema
 *
 * (`AvatarTemplateResource::toArray()`'s local `/** @var *\/` assignment
 * degrading Scramble's inference) lied — `id`/`config`/`is_active` typed
 * `string`. These assertions pin the wire types PHP-side.
 */

use App\Http\Resources\AvatarTemplateResource;
use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Http\Request;

test('AvatarTemplateResource wire types pin the docblock against schema drift', function (): void {
    $org = Organization::factory()->create();
    $template = TenantContextScope::runFor(
        $org->id,
        fn (): AvatarTemplate => AvatarTemplate::create([
            'name' => 'Recruiter voice',
            'provider' => 'heygen',
            'config' => ['avatarId' => 'av_1', 'voiceId' => 'vo_1'],
            'is_active' => true,
        ])
    );

    $resource = new AvatarTemplateResource($template);
    $array = $resource->toArray(new Request);

    expect($array['id'])->toBeInt();
    expect($array['is_active'])->toBeBool();
    expect($array['is_active'])->toBeTrue();
    expect($array['config'])->toBeArray();
    expect($array['config'])->toBe(['avatarId' => 'av_1', 'voiceId' => 'vo_1']);
});

test('AvatarTemplateResource exposes a null description as null, not a coerced string', function (): void {
    $org = Organization::factory()->create();
    $template = TenantContextScope::runFor(
        $org->id,
        fn (): AvatarTemplate => AvatarTemplate::create([
            'name' => 'No description',
            'provider' => 'heygen',
            'config' => ['avatarId' => 'av_1', 'voiceId' => 'vo_1'],
        ])
    );

    $resource = new AvatarTemplateResource($template);
    $array = $resource->toArray(new Request);

    expect($array['description'])->toBeNull();
});

// ─── P6b: per-template cost forecast (pluggable-conversation-llm, design D10) ─

test('an unbound template forecasts a null cost', function (): void {
    $org = Organization::factory()->create();
    $template = TenantContextScope::runFor(
        $org->id,
        fn (): AvatarTemplate => AvatarTemplate::create([
            'name' => 'Unbound template',
            'provider' => 'heygen',
            'config' => [],
        ])
    );

    $array = (new AvatarTemplateResource($template))->toArray(new Request);

    expect($array['llm']['estimated_cost_usd_per_interview'])->toBeNull();
});

test('a bound template forecasts minutes, turns, and ONE usd figure — never a $/minute value', function (): void {
    config()->set('conversation_llm.forecast', [
        'reference_minutes' => 15,
        'reference_turns' => 3,
        'reference_system_prompt_chars' => 400,
        'reference_participant_chars_per_turn' => 80,
        'reference_avatar_chars_per_turn' => 320,
    ]);

    $org = Organization::factory()->create();
    $model = LlmModel::create([
        'key' => 'gemini-3-flash-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
        'text_input_usd_per_million' => '1.000000',
        'text_output_usd_per_million' => '2.000000',
    ]);
    $credential = TenantContextScope::runFor($org->id, function () use ($org): LlmCredential {
        $credential = new LlmCredential;
        $credential->forceFill([
            'organization_id' => $org->id,
            'name' => 'Forecast credential',
            'vendor' => 'google',
            'api_key' => 'sk-real-gemini-key',
            'key_last_four' => 'lkey',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $credential->save();

        return $credential;
    });
    $template = TenantContextScope::runFor(
        $org->id,
        fn (): AvatarTemplate => AvatarTemplate::create([
            'name' => 'Bound template',
            'provider' => 'heygen',
            'config' => [],
            'llm_model_id' => $model->id,
            'llm_credential_id' => $credential->id,
        ])
    );

    $array = (new AvatarTemplateResource($template))->toArray(new Request);
    $forecast = $array['llm']['estimated_cost_usd_per_interview'];

    expect($forecast)->not->toBeNull()
        ->and($forecast)->toHaveKeys(['minutes', 'turns', 'usd'])
        ->and($forecast['minutes'])->toBe(15)
        ->and($forecast['turns'])->toBe(3)
        ->and($forecast['usd'])->toBeGreaterThan(0.0)
        ->and($forecast)->not->toHaveKey('usd_per_minute');
});
