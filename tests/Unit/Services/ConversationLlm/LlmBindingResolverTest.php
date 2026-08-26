<?php

declare(strict_types=1);

/**
 * `LlmBindingResolver` — NEVER throws; null for unbound, revoked, and
 * cross-org (pluggable-conversation-llm PR P3a, design D6).
 *
 * An interview must not fail to start because a cost preference could not
 * be read — the same doctrine `ActiveTemplateResolver.php` already states
 * for its own null return.
 */

use App\Enums\LlmBindingStatus;
use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Services\ConversationLlm\LlmBinding;
use App\Services\ConversationLlm\LlmBindingResolver;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function resolverManagedModel(): LlmModel
{
    return LlmModel::create([
        'key' => 'gemini-3-flash-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
    ]);
}

test('an unbound template resolves null', function (): void {
    $org = Organization::factory()->create();
    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Unbound',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
    ]));

    expect(app(LlmBindingResolver::class)->resolve($template))->toBeNull();
});

test('a bound template resolves a full LlmBinding', function (): void {
    $org = Organization::factory()->create();
    $model = resolverManagedModel();

    $credential = TenantContextScope::runFor($org->id, function () use ($org): LlmCredential {
        $c = new LlmCredential;
        $c->forceFill([
            'organization_id' => $org->id,
            'name' => 'Cred',
            'vendor' => 'google',
            'api_key' => 'sk-real-key',
            'key_last_four' => 'real',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $c->save();

        return $c;
    });

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Bound',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));

    $binding = app(LlmBindingResolver::class)->resolve($template);

    expect($binding)->toBeInstanceOf(LlmBinding::class);
    expect($binding->modelKey)->toBe('gemini-3-flash-preview');
    expect($binding->apiKey)->toBe('sk-real-key');
});

test('a cross-org credential (data corruption) resolves null, never throws', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $model = resolverManagedModel();

    $credentialB = TenantContextScope::runFor($orgB->id, function () use ($orgB): LlmCredential {
        $c = new LlmCredential;
        $c->forceFill([
            'organization_id' => $orgB->id,
            'name' => 'Cred B',
            'vendor' => 'google',
            'api_key' => 'sk-real-key',
            'key_last_four' => 'real',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $c->save();

        return $c;
    });

    // Simulate a corrupted row bypassing the model layer's I3 guard entirely.
    $template = TenantContextScope::runFor($orgA->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Corrupted',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
    ]));

    DB::table('avatar_templates')->where('id', $template->id)->update([
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credentialB->id,
    ]);

    expect(fn () => app(LlmBindingResolver::class)->resolve($template->fresh()))->not->toThrow(Throwable::class);
    expect(app(LlmBindingResolver::class)->resolve($template->fresh()))->toBeNull();
});

// ─── resolveStatus() — the tri-state billing decision (design D0) ─────────────

test('an unbound template resolves status Unbound', function (): void {
    $org = Organization::factory()->create();
    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Status unbound',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
    ]));

    expect(app(LlmBindingResolver::class)->resolveStatus($template))->toBe(LlmBindingStatus::Unbound);
});

test('a bound template with llm_sync_status=synced resolves status Applied', function (): void {
    $org = Organization::factory()->create();
    $model = resolverManagedModel();
    $credential = TenantContextScope::runFor($org->id, function () use ($org): LlmCredential {
        $c = new LlmCredential;
        $c->forceFill([
            'organization_id' => $org->id, 'name' => 'Synced cred', 'vendor' => 'google',
            'api_key' => 'sk-real-key', 'key_last_four' => 'real',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $c->save();

        return $c;
    });

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Status applied',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));
    $template->forceFill(['llm_sync_status' => 'synced'])->saveQuietly();

    expect(app(LlmBindingResolver::class)->resolveStatus($template->fresh()))->toBe(LlmBindingStatus::Applied);
});

test('a bound template whose llm_sync_status is still NULL resolves status Degraded — an import that never synced', function (): void {
    $org = Organization::factory()->create();
    $model = resolverManagedModel();
    $credential = TenantContextScope::runFor($org->id, function () use ($org): LlmCredential {
        $c = new LlmCredential;
        $c->forceFill([
            'organization_id' => $org->id, 'name' => 'Never synced cred', 'vendor' => 'google',
            'api_key' => 'sk-real-key', 'key_last_four' => 'real',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $c->save();

        return $c;
    });

    // Mirrors an imported bound template (D13): bound, but no provider sync
    // has ever run, so llm_sync_status is NULL by default. NULL is not
    // 'synced' — this must fail CLOSED, never bill.
    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Status degraded',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));

    expect($template->llm_sync_status)->toBeNull();
    expect(app(LlmBindingResolver::class)->resolveStatus($template))->toBe(LlmBindingStatus::Degraded);
});

test('a bound template whose llm_sync_status is failed resolves status Degraded', function (): void {
    $org = Organization::factory()->create();
    $model = resolverManagedModel();
    $credential = TenantContextScope::runFor($org->id, function () use ($org): LlmCredential {
        $c = new LlmCredential;
        $c->forceFill([
            'organization_id' => $org->id, 'name' => 'Failed sync cred', 'vendor' => 'google',
            'api_key' => 'sk-real-key', 'key_last_four' => 'real',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $c->save();

        return $c;
    });

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Status failed sync',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));
    $template->forceFill(['llm_sync_status' => 'failed'])->saveQuietly();

    expect(app(LlmBindingResolver::class)->resolveStatus($template->fresh()))->toBe(LlmBindingStatus::Degraded);
});
