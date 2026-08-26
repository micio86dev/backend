<?php

declare(strict_types=1);

/**
 * avatar_templates binding columns (pluggable-conversation-llm PR P3a,
 * design D3).
 *
 * Five nullable columns — `llm_model_id`, `llm_credential_id`,
 * `heygen_llm_configuration_id`, `llm_sync_status`, `llm_synced_at` — real
 * columns, never `config` jsonb keys, because they are FKs (jsonb carries no
 * referential integrity) and must be queryable ("which templates use this
 * credential?" powers D2's 409). `llm_sync_status` / `llm_synced_at` are the
 * Tavus half of the orphan ledger (design C2) — without them `degraded` is
 * unreachable on the Tavus path.
 *
 * REQ: avatar-templates "A template may bind one conversation model and one
 *      credential, both or neither"
 */

use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('avatar_templates gains the five binding columns', function (): void {
    foreach ([
        'llm_model_id',
        'llm_credential_id',
        'heygen_llm_configuration_id',
        'llm_sync_status',
        'llm_synced_at',
    ] as $column) {
        expect(Schema::hasColumn('avatar_templates', $column))
            ->toBeTrue("Column '{$column}' is missing from avatar_templates");
    }
});

test('all five binding columns are nullable', function (): void {
    foreach ([
        'llm_model_id',
        'llm_credential_id',
        'heygen_llm_configuration_id',
        'llm_sync_status',
        'llm_synced_at',
    ] as $column) {
        $col = collect(DB::select(
            "SELECT is_nullable FROM information_schema.columns
             WHERE table_name = 'avatar_templates' AND column_name = ?",
            [$column]
        ))->first();

        expect($col->is_nullable)->toBe('YES', "{$column} must be nullable");
    }
});

test('(organization_id, llm_credential_id) is indexed', function (): void {
    $indexes = DB::select(
        "SELECT indexdef FROM pg_indexes
         WHERE tablename = 'avatar_templates'
           AND indexdef LIKE '%organization_id%'
           AND indexdef LIKE '%llm_credential_id%'"
    );

    expect($indexes)->not->toBeEmpty('Index on (organization_id, llm_credential_id) missing from avatar_templates');
});

test('a raw half-bound insert is rejected by the database CHECK', function (): void {
    $org = Organization::factory()->create();
    $model = LlmModel::create([
        'key' => 'gemini-3-flash-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
    ]);

    $violates = false;

    try {
        DB::statement(
            'INSERT INTO avatar_templates (organization_id, name, provider, config, llm_model_id, llm_credential_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NULL, now(), now())',
            [$org->id, 'half-bound', 'tavus', '{}', $model->id]
        );
    } catch (QueryException $e) {
        $violates = true;
    }

    expect($violates)->toBeTrue('The CHECK (llm_model_id IS NULL) = (llm_credential_id IS NULL) must reject a half-bound row');
});

test('a fully bound row is accepted by the CHECK', function (): void {
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    app(TenantResolver::class)->setBypass(false);

    $model = LlmModel::create([
        'key' => 'gemini-3-flash-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
    ]);

    $credential = LlmCredential::create([
        'name' => 'Primary key',
        'vendor' => 'google',
        'api_key' => 'sk-real-key',
        'key_last_four' => 'real',
        'key_fingerprint' => hash('sha256', 'sk-real-key'),
    ]);

    DB::statement(
        'INSERT INTO avatar_templates (organization_id, name, provider, config, llm_model_id, llm_credential_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, now(), now())',
        [$org->id, 'fully-bound', 'tavus', '{}', $model->id, $credential->id]
    );

    expect(DB::table('avatar_templates')->where('name', 'fully-bound')->exists())->toBeTrue();
});
