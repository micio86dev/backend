<?php

declare(strict_types=1);

/**
 * Encryption-at-rest for llm_credentials.api_key (pluggable-conversation-llm
 * PR P2, design D2, non-negotiable #6).
 *
 * `Project.php:92,103`'s double convention verbatim: `'encrypted'` cast AND
 * `$hidden`. A raw DB read must see ciphertext; only Eloquent decrypts; no
 * resource, exception, or log line may ever carry the plaintext.
 *
 * REQ: conversation-llm "Org credentials are encrypted at rest and never
 *      leave the API as plaintext"
 */

use App\Models\LlmCredential;
use App\Models\Organization;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function makeCredentialForOrg(Organization $org, string $rawKey = 'sk-super-secret-google-key'): LlmCredential
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $credential = new LlmCredential;
    $credential->forceFill([
        'organization_id' => $org->id,
        'name' => 'Primary Gemini key',
        'vendor' => 'google',
        'api_key' => $rawKey,
        'key_last_four' => substr($rawKey, -4),
        'key_fingerprint' => hash('sha256', $rawKey),
    ]);
    $credential->save();

    return $credential;
}

test('a raw builder read returns ciphertext, an Eloquent read decrypts', function (): void {
    $org = Organization::factory()->create();
    $credential = makeCredentialForOrg($org, 'sk-super-secret-google-key');

    $raw = DB::table('llm_credentials')->where('id', $credential->id)->first();

    expect($raw->api_key)->not->toBe('sk-super-secret-google-key');
    expect($raw->api_key)->not->toContain('sk-super-secret-google-key');

    $eloquent = LlmCredential::withoutGlobalScopes()->find($credential->id);

    expect($eloquent->api_key)->toBe('sk-super-secret-google-key');
});

test('api_key is absent from the model serialized output', function (): void {
    $org = Organization::factory()->create();
    $credential = makeCredentialForOrg($org, 'sk-super-secret-google-key');

    $array = $credential->toArray();

    expect($array)->not->toHaveKey('api_key');
    expect(json_encode($array))->not->toContain('sk-super-secret-google-key');
});
