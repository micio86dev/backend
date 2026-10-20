<?php

declare(strict_types=1);

/**
 * A template created ALREADY bound to a language model is audited as `avatar_template.llm_bound`, exactly as the
 * edit path audits a binding (hr-avatar-adapter A12). The create audit row names only the name and provider, so
 * without this a bound-at-creation template had no record of which model and credential it was given.
 *
 * Both template controllers, create. Names, never ids or keys.
 */

use App\Models\AuditLog;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'interview.heygen.api_key' => 'HEYGEN_KEY_X',
        'interview.preflight.verify_references' => true,
    ]);

    Http::fake([
        'api.liveavatar.com/v1/avatars*' => Http::response(['data' => ['next' => null, 'results' => [['id' => 'av_ok', 'name' => 'A', 'status' => 'ACTIVE']]]], 200),
        'api.liveavatar.com/v1/voices*' => Http::response(['data' => ['next' => null, 'results' => [['id' => 'v', 'name' => 'V', 'status' => 'ACTIVE']]]], 200),
    ]);
});

/** @return array{llm_model_id: int, llm_credential_id: int} */
function createAuditBinding(): array
{
    $model = LlmModel::create([
        'key' => 'create-audit-model',
        'vendor' => 'google',
        'display_name' => 'Model',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
    ]);

    $credential = new LlmCredential;
    $credential->forceFill([
        'name' => 'Create audit credential',
        'vendor' => 'google',
        'api_key' => 'sk-real-key',
        'key_last_four' => 'real',
        'key_fingerprint' => hash('sha256', uniqid('', true)),
    ])->save();

    return ['llm_model_id' => $model->id, 'llm_credential_id' => $credential->id];
}

function createAuditToken(string $base): string
{
    return TemplateActors::token($base === '/api/avatar-templates' ? 'acting' : 'bare', Organization::factory()->create());
}

dataset('createAuditRoutes', [
    'organization route' => ['/api/avatar-templates'],
    'platform route' => ['/api/admin/avatar-templates'],
]);

test('a template created already bound is audited as llm_bound, with names and never ids', function (string $base): void {
    $payload = ['name' => 'Bound at birth', 'provider' => 'heygen', 'config' => ['avatarId' => 'av_ok', 'voiceId' => 'v']] + createAuditBinding();

    $id = $this->withToken(createAuditToken($base))->postJson($base, $payload)->assertCreated()->json('data.id');

    $log = AuditLog::withoutGlobalScopes()->where('action', 'avatar_template.llm_bound')->sole();

    expect((int) $log->subject_id)->toBe($id)
        ->and($log->after)->toBe(['model_key' => 'create-audit-model', 'credential_name' => 'Create audit credential'])
        ->and(json_encode($log->after))->not->toContain('sk-real-key');
})->with('createAuditRoutes');

test('a template created unbound writes no llm_bound row', function (string $base): void {
    $this->withToken(createAuditToken($base))
        ->postJson($base, ['name' => 'Unbound', 'provider' => 'heygen', 'config' => ['avatarId' => 'av_ok', 'voiceId' => 'v']])
        ->assertCreated();

    expect(AuditLog::withoutGlobalScopes()->where('action', 'avatar_template.llm_bound')->count())->toBe(0);
})->with('createAuditRoutes');
