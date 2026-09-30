<?php

declare(strict_types=1);

/**
 * How a PLATFORM avatar template syncs with its provider (global-avatar-
 * templates A4): the HeyGen configuration is named without an organization, a
 * credential rotation reaches a platform template bound to it, and provider
 * bookkeeping on a platform row works from any tenant context — a queued job
 * runs inside one organization but the row belongs to none.
 */

use App\Actions\ConversationLlm\ResyncCredentialBindings;
use App\Actions\ConversationLlm\ResyncTemplateBinding;
use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Services\ConversationLlm\HeygenLlmRegistrar;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');
    Http::fake([
        '*liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => 'sec_1'], 'message' => 'ok'], 200),
        '*liveavatar.com/v1/llm-configurations' => Http::response(['data' => ['id' => 'cfg_1']], 200),
    ]);
});

/** @return array{0: LlmModel, 1: LlmCredential} */
function ptsBinding(): array
{
    $model = LlmModel::create([
        'key' => 'gemini-3-flash-preview', 'vendor' => 'google', 'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/', 'capability' => 'text',
        'is_available' => true, 'sort_order' => 0,
    ]);
    $credential = (new LlmCredential)->forceFill([
        'name' => 'Sync credential', 'vendor' => 'google', 'api_key' => 'sk-real-gemini-key',
        'key_last_four' => 'lkey', 'key_fingerprint' => hash('sha256', 'pts-credential'),
    ]);
    $credential->save();

    return [$model, $credential];
}

function ptsBoundGlobal(): AvatarTemplate
{
    [$model, $credential] = ptsBinding();

    return PlatformTemplates::insertGlobal(['llm_model_id' => $model->id, 'llm_credential_id' => $credential->id]);
}

function ptsConfigurationDisplayName(): ?string
{
    $sent = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/v1/llm-configurations') && $request->method() === 'POST');

    return $sent->isEmpty() ? null : $sent->first()[0]['display_name'];
}

test('a platform template configuration is named with no organization segment', function (): void {
    $global = ptsBoundGlobal();

    app(HeygenLlmRegistrar::class)->ensureConfiguration($global);

    expect(ptsConfigurationDisplayName())->toBe("beai-platform-template{$global->id}");
});

test('an organization template configuration keeps its organization segment', function (): void {
    [$model, $credential] = ptsBinding();
    $org = Organization::factory()->create();
    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Own', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id, 'llm_credential_id' => $credential->id,
    ]));

    app(HeygenLlmRegistrar::class)->ensureConfiguration($template);

    expect(ptsConfigurationDisplayName())->toBe("beai-org{$org->id}-template{$template->id}");
});

test('a credential rotation re-syncs a platform template bound to it and records the outcome', function (): void {
    $global = ptsBoundGlobal();
    $credential = LlmCredential::findOrFail($global->llm_credential_id);

    $result = app(ResyncCredentialBindings::class)->run($credential);

    $fresh = AvatarTemplate::platformOnly()->findOrFail($global->id);

    expect($result['status'])->toBe('synced')
        ->and($fresh->llm_sync_status)->toBe('synced')
        ->and($fresh->llm_synced_at)->not->toBeNull()
        ->and($fresh->heygen_llm_configuration_id)->toBe('cfg_1');
});

test('provider bookkeeping on a platform template works from inside an organization context', function (): void {
    $global = ptsBoundGlobal();
    $org = Organization::factory()->create();

    $result = TenantContextScope::runFor($org->id, fn () => app(ResyncTemplateBinding::class)->run($global));

    $fresh = AvatarTemplate::platformOnly()->findOrFail($global->id);

    expect($result['status'])->toBe('synced')
        ->and($fresh->llm_sync_status)->toBe('synced')
        ->and($fresh->organization_id)->toBeNull();
});
