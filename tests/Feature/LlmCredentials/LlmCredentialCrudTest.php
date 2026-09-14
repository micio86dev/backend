<?php

declare(strict_types=1);

/**
 * llm_credentials CRUD, RBAC, throttle, and audit.
 *
 * The ACTOR changed on 2026-09-14 and nothing else about these cases did.
 * Credentials stopped being an organization's bring-your-own key and became
 * BEAI's own, so every write here is made by a SUPERADMIN; an org admin — who
 * owned this surface until that day — now gets a 403, which is its own test
 * below.
 *
 * `LlmCredential::query()` is gone from the assertions too: it
 * was defeating a tenant scope the model no longer carries, so it had stopped
 * meaning anything.
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
    ['token' => $token] = authUserAndTokenForRole($org, 'platform');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 401)]);

    $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Bad key',
        'vendor' => 'google',
        'api_key' => 'sk-bad-key',
    ])->assertStatus(422);

    expect(LlmCredential::query()->count())->toBe(0);
});

test('a rate-limited or unreachable result is still stored', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'platform');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 429)]);

    $response = $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Rate limited key',
        'vendor' => 'google',
        'api_key' => 'sk-real-key',
    ])->assertStatus(201);

    $credential = LlmCredential::query()->find($response->json('data.id'));
    expect($credential)->not->toBeNull();
    expect($credential->validation_error)->toBe('rate_limited');
});

test('a valid key is stored with validated_at set', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'platform');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $response = $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Good key',
        'vendor' => 'google',
        'api_key' => 'sk-real-key',
    ])->assertStatus(201);

    $credential = LlmCredential::query()->find($response->json('data.id'));
    expect($credential->validation_error)->toBeNull();
    expect($credential->validated_at)->not->toBeNull();
    expect($credential->key_last_four)->toBe('-key');
});

test('creating a credential is audited without the key value', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'platform');

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
    ['token' => $token] = authUserAndTokenForRole($org, 'platform');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $created = $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Rotate me',
        'vendor' => 'google',
        'api_key' => 'sk-old-key',
    ])->assertStatus(201);

    $this->withToken($token)->patchJson('/api/llm-credentials/'.$created->json('data.id'), [
        'api_key' => 'sk-new-key-1234',
    ])->assertStatus(200);

    $credential = LlmCredential::query()->find($created->json('data.id'));
    expect($credential->api_key)->toBe('sk-new-key-1234');

    $log = AuditLog::withoutGlobalScopes()->where('action', 'llm_credential.rotated')->first();
    expect($log)->not->toBeNull();
    expect(json_encode($log->after))->not->toContain('sk-new-key-1234');
});

test('deleting a credential is audited', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'platform');

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

/**
 * The gate NARROWED on 2026-09-14, and this is the case that proves it.
 *
 * An org `admin` could create, rotate and delete these until that day. They
 * cannot now: the rows are BEAI's, and `hasRole('admin')` is an ORG-SCOPED
 * grant (Spatie teams mode) that cannot describe who may touch a platform row.
 * Asserting on an operator would no longer prove anything — the interesting
 * refusal is the role that used to be allowed.
 */
test('writes are superadmin-only — an org admin is refused', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $adminToken] = authUserAndTokenForRole($org, 'admin');

    $this->withToken($adminToken)->postJson('/api/llm-credentials', [
        'name' => 'Nope',
        'vendor' => 'google',
        'api_key' => 'sk-real-key',
    ])->assertStatus(403);
});

test('reads are superadmin-only — an org admin is refused', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $adminToken] = authUserAndTokenForRole($org, 'admin');

    $this->withToken($adminToken)->getJson('/api/llm-credentials')->assertStatus(403);
});

/**
 * REPLACES 'a cross-org credential id resolves 404, never 403'.
 *
 * That test asserted the isolation this change deliberately removed: a
 * credential created while acting as org B was invisible from org A. There is
 * ONE set now, and the property worth pinning is the opposite one — the
 * selected client does not change which credentials exist, because they do not
 * belong to a client at all.
 *
 * Deleted rather than inverted-in-place so the diff says plainly that an
 * isolation guarantee was dropped on purpose.
 */
test('the same one set is visible no matter which client is selected', function (): void {
    seedGeminiModelForCredentialTests();
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    ['token' => $tokenA] = authUserAndTokenForRole($orgA, 'platform');
    ['token' => $tokenB] = authUserAndTokenForRole($orgB, 'platform');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $createdInB = $this->withToken($tokenB)->postJson('/api/llm-credentials', [
        'name' => 'Platform key',
        'vendor' => 'google',
        'api_key' => 'sk-real-key',
    ])->assertStatus(201);

    resetAuthGuardState();

    $listedFromA = $this->withToken($tokenA)->getJson('/api/llm-credentials')->assertOk();

    expect(array_column($listedFromA->json('data'), 'id'))
        ->toContain($createdInB->json('data.id'));
});

test('the sixth write request in a minute is throttled', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'platform');

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

/**
 * `destroy` and `update` need their OWN refusal cases — the POST one above
 * does not cover them.
 *
 * `destroy()` is the endpoint that matters most here, because its 409 body
 * lists `templates` by name ACROSS EVERY TENANT: the in-use guard runs
 * `AvatarTemplate::withoutGlobalScopes()`, deliberately, because the foreign
 * key is ON DELETE RESTRICT platform-wide and a scoped count would report
 * "nothing bound" and turn an integrity error into a 500.
 *
 * That makes the response a cross-tenant read surface whose ONLY guard is
 * `LlmCredentialPolicy::delete`. A guard that has never been seen to refuse is
 * a guard nobody has tested, so these assert the refusal directly rather than
 * inferring it from the POST case.
 */
test('DELETE is refused for an org admin — the 409 body is a cross-tenant surface', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $platformToken] = authUserAndTokenForRole($org, 'platform');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $created = $this->withToken($platformToken)->postJson('/api/llm-credentials', [
        'name' => 'Platform key for delete',
        'vendor' => 'google',
        'api_key' => 'sk-real-key',
    ])->assertStatus(201);

    resetAuthGuardState();

    ['token' => $adminToken] = authUserAndTokenForRole($org, 'admin');

    $this->withToken($adminToken)
        ->deleteJson('/api/llm-credentials/'.$created->json('data.id'))
        ->assertStatus(403);

    expect(LlmCredential::query()->whereKey($created->json('data.id'))->exists())->toBeTrue();
});

test('PATCH is refused for an org admin — a rotation is a write to a platform secret', function (): void {
    seedGeminiModelForCredentialTests();
    $org = Organization::factory()->create();
    ['token' => $platformToken] = authUserAndTokenForRole($org, 'platform');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $created = $this->withToken($platformToken)->postJson('/api/llm-credentials', [
        'name' => 'Platform key for rotate',
        'vendor' => 'google',
        'api_key' => 'sk-real-key',
    ])->assertStatus(201);

    $originalFingerprint = LlmCredential::query()->findOrFail($created->json('data.id'))->key_fingerprint;

    resetAuthGuardState();

    ['token' => $adminToken] = authUserAndTokenForRole($org, 'admin');

    $this->withToken($adminToken)
        ->patchJson('/api/llm-credentials/'.$created->json('data.id'), [
            'api_key' => 'sk-someone-elses-key',
        ])->assertStatus(403);

    expect(LlmCredential::query()->findOrFail($created->json('data.id'))->key_fingerprint)
        ->toBe($originalFingerprint);
});
