<?php

declare(strict_types=1);

/**
 * llm_credentials schema (pluggable-conversation-llm PR P2, design D2).
 *
 * Org-scoped, encrypted-at-rest vault for a bring-your-own Google Gemini key.
 * `api_key` is a plain `text` column — encryption is an APPLICATION-layer cast
 * on the model (`'encrypted'` + `$hidden`), not a database feature — and
 * `key_fingerprint` is CHECK-constrained to a lowercase hex sha256, mirroring
 * `ai_requests.response_sha256`'s precedent.
 *
 * REQ: conversation-llm "Org credentials are encrypted at rest and never
 *      leave the API as plaintext"
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('llm_credentials table exists', function (): void {
    expect(Schema::hasTable('llm_credentials'))->toBeTrue();
});

test('llm_credentials has all required columns', function (): void {
    $columns = [
        'id',
        'organization_id',
        'name',
        'vendor',
        'api_key',
        'key_last_four',
        'key_fingerprint',
        'heygen_secret_id',
        'validated_at',
        'validation_error',
        'created_at',
        'updated_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('llm_credentials', $column))
            ->toBeTrue("Column '{$column}' is missing from llm_credentials");
    }
});

test('llm_credentials.api_key is a plain text column, not encrypted at the database', function (): void {
    // The database column carries NO encryption of its own — that would be a
    // second, undocumented encryption mechanism nobody could rotate keys for.
    // Encryption happens exclusively at the Eloquent cast layer (design D2).
    $col = collect(DB::select(
        "SELECT data_type FROM information_schema.columns
         WHERE table_name = 'llm_credentials' AND column_name = 'api_key'"
    ))->first();

    expect($col->data_type)->toBe('text');
});

test('key_fingerprint is CHECK-constrained to a lowercase hex sha256', function (): void {
    $violates = false;

    try {
        DB::statement(
            "INSERT INTO llm_credentials (organization_id, name, vendor, api_key, key_last_four, key_fingerprint, created_at, updated_at)
             VALUES (1, 'bad-fingerprint', 'google', 'ciphertext', 'abcd', 'not-a-valid-fingerprint', now(), now())"
        );
    } catch (QueryException $e) {
        $violates = true;
    }

    expect($violates)->toBeTrue('key_fingerprint must reject a value that is not 64 lowercase hex characters');
});

test('(organization_id, name) is unique', function (): void {
    $indexes = DB::select(
        "SELECT indexdef FROM pg_indexes
         WHERE tablename = 'llm_credentials'
           AND indexdef LIKE '%UNIQUE%'
           AND indexdef LIKE '%organization_id%'
           AND indexdef LIKE '%name%'"
    );

    expect($indexes)->not->toBeEmpty('UNIQUE(organization_id, name) index missing from llm_credentials');
});
