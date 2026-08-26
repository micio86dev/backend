<?php

declare(strict_types=1);

/**
 * llm_credentials CRUD, RBAC, throttle, and audit (pluggable-conversation-llm
 * PR P2, design D2/D9).
 *
 * REQ: conversation-llm "Org credentials are encrypted at rest and never
 *      leave the API as plaintext"
 * REQ: conversation-llm "Credential validation returns a stable code, never
 *      the vendor's prose, and cannot become a key-testing oracle"
 */

use App\Models\AuditLog;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function seedGeminiModelForCredentialTests(): void
{
    LlmModel::create([
        'key' => 'gemini-3-flash-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
        'text_input_usd_per_million' => '0.075000',
        'text_output_usd_per_million' => '0.300000',
    ]);
}

test('an invalid key is rejected 422 and nothing is persisted', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 401)]);

    $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Bad key',
        'vendor' => 'google',
        'api_key' => 'sk-bad-key',
    ])->assertStatus(422);

    expect(LlmCredential::withoutGlobalScopes()->count())->toBe(0);
});

test('a rate-limited or unreachable result is still stored', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 429)]);

    $response = $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Rate limited key',
        'vendor' => 'google',
        'api_key' => 'sk-real-key',
    ])->assertStatus(201);

    $credential = LlmCredential::withoutGlobalScopes()->find($response->json('data.id'));
    expect($credential)->not->toBeNull();
    expect($credential->validation_error)->toBe('rate_limited');
});

test('a valid key is stored with validated_at set', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $response = $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Good key',
        'vendor' => 'google',
        'api_key' => 'sk-real-key',
    ])->assertStatus(201);

    $credential = LlmCredential::withoutGlobalScopes()->find($response->json('data.id'));
    expect($credential->validation_error)->toBeNull();
    expect($credential->validated_at)->not->toBeNull();
    expect($credential->key_last_four)->toBe('-key');
});

test('creating a credential is audited without the key value', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Audited key',
        'vendor' => 'google',
        'api_key' => 'sk-real-key',
    ])->assertStatus(201);

    $log = AuditLog::withoutGlobalScopes()->where('action', 'llm_credential.created')->first();

    expect($log)->not->toBeNull();
    expect($log->after['name'])->toBe('Audited key');
    expect($log->after['key_last_four'])->toBe('-key');
    expect(json_encode($log->after))->not->toContain('sk-real-key');
});

test('rotating a credential is audited and updates the stored key', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $created = $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Rotate me',
        'vendor' => 'google',
        'api_key' => 'sk-old-key',
    ])->assertStatus(201);

    $this->withToken($token)->patchJson('/api/llm-credentials/'.$created->json('data.id'), [
        'api_key' => 'sk-new-key-1234',
    ])->assertStatus(200);

    $credential = LlmCredential::withoutGlobalScopes()->find($created->json('data.id'));
    expect($credential->api_key)->toBe('sk-new-key-1234');

    $log = AuditLog::withoutGlobalScopes()->where('action', 'llm_credential.rotated')->first();
    expect($log)->not->toBeNull();
    expect(json_encode($log->after))->not->toContain('sk-new-key-1234');
});

test('deleting a credential is audited', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $created = $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Delete me',
        'vendor' => 'google',
        'api_key' => 'sk-real-key',
    ])->assertStatus(201);

    $this->withToken($token)->deleteJson('/api/llm-credentials/'.$created->json('data.id'))
        ->assertStatus(200);

    $log = AuditLog::withoutGlobalScopes()->where('action', 'llm_credential.deleted')->first();
    expect($log)->not->toBeNull();
});

test('writes are admin-only', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $operatorToken] = authUserAndTokenForRole($org, 'operator');

    $this->withToken($operatorToken)->postJson('/api/llm-credentials', [
        'name' => 'Nope',
        'vendor' => 'google',
        'api_key' => 'sk-real-key',
    ])->assertStatus(403);
});

test('a cross-org credential id resolves 404, never 403', function (): void {
    seedGeminiModelForCredentialTests();
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    ['token' => $tokenA] = authUserAndTokenForRole($orgA, 'admin');
    ['token' => $tokenB] = authUserAndTokenForRole($orgB, 'admin');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $createdInB = $this->withToken($tokenB)->postJson('/api/llm-credentials', [
        'name' => 'Org B key',
        'vendor' => 'google',
        'api_key' => 'sk-real-key',
    ])->assertStatus(201);

    resetAuthGuardState();

    $this->withToken($tokenA)->deleteJson('/api/llm-credentials/'.$createdInB->json('data.id'))
        ->assertStatus(404);
});

test('the sixth write request in a minute is throttled', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 401)]);

    for ($i = 0; $i < 5; $i++) {
        $this->withToken($token)->postJson('/api/llm-credentials', [
            'name' => "Attempt {$i}",
            'vendor' => 'google',
            'api_key' => 'sk-bad-key',
        ])->assertStatus(422);
    }

    $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Attempt 6',
        'vendor' => 'google',
        'api_key' => 'sk-bad-key',
    ])->assertStatus(429);
});
