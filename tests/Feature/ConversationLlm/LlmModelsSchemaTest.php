<?php

declare(strict_types=1);

/**
 * llm_models schema (pluggable-conversation-llm PR P1, design D1).
 *
 * The global, non-tenant-scoped registry of Google Gemini conversation
 * models and their published rates. Every rate column is nullable
 * `decimal(12,6)`: a NULL means "Google does not publish this," a different
 * fact from zero, and the schema itself is the first line of defense against
 * a `NOT NULL DEFAULT 0` that would let the estimator silently bill $0.00 for
 * an unpriced model.
 *
 * REQ: conversation-llm "The model registry is global, upserted, and carries
 *      a per-request context pricing tier"
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('llm_models table exists', function (): void {
    expect(Schema::hasTable('llm_models'))->toBeTrue();
});

test('llm_models has all required columns', function (): void {
    $columns = [
        'id',
        'key',
        'vendor',
        'display_name',
        'base_url',
        'capability',
        'is_available',
        'sort_order',
        'rate_card_source_url',
        'rate_card_verified_at',
        'text_input_usd_per_million',
        'text_output_usd_per_million',
        'text_input_usd_per_million_high',
        'text_output_usd_per_million_high',
        'context_tier_threshold_tokens',
        'audio_input_usd_per_million',
        'audio_output_usd_per_million',
        'audio_input_usd_per_minute',
        'audio_output_usd_per_minute',
        'audio_tokens_per_second',
        'created_at',
        'updated_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('llm_models', $column))
            ->toBeTrue("Column '{$column}' is missing from llm_models");
    }
});

test('llm_models.key is unique', function (): void {
    $indexes = DB::select(
        "SELECT indexdef FROM pg_indexes
         WHERE tablename = 'llm_models'
           AND indexdef LIKE '%UNIQUE%'
           AND indexdef LIKE '%(key)%'"
    );

    expect($indexes)->not->toBeEmpty('UNIQUE(key) index missing from llm_models');
});

test('every rate column is a nullable decimal(12,6) with no default', function (): void {
    $rateColumns = [
        'text_input_usd_per_million',
        'text_output_usd_per_million',
        'text_input_usd_per_million_high',
        'text_output_usd_per_million_high',
        'audio_input_usd_per_million',
        'audio_output_usd_per_million',
        'audio_input_usd_per_minute',
        'audio_output_usd_per_minute',
    ];

    foreach ($rateColumns as $column) {
        $col = collect(DB::select(
            'SELECT data_type, numeric_precision, numeric_scale, is_nullable, column_default
             FROM information_schema.columns
             WHERE table_name = \'llm_models\' AND column_name = ?',
            [$column]
        ))->first();

        expect($col)->not->toBeNull("Column '{$column}' not found in llm_models");
        expect($col->data_type)->toBe('numeric', "{$column} must be a decimal/numeric column");
        expect((int) $col->numeric_precision)->toBe(12, "{$column} must be decimal(12,6)");
        expect((int) $col->numeric_scale)->toBe(6, "{$column} must be decimal(12,6)");
        expect($col->is_nullable)->toBe('YES', "{$column} must be nullable — NULL means unpublished, not zero");
        expect($col->column_default)->toBeNull("{$column} must carry no default — a default would silently bill an unpriced model");
    }
});

test('context_tier_threshold_tokens is a nullable unsigned integer', function (): void {
    $col = collect(DB::select(
        "SELECT data_type, is_nullable FROM information_schema.columns
         WHERE table_name = 'llm_models' AND column_name = 'context_tier_threshold_tokens'"
    ))->first();

    expect($col->data_type)->toBeIn(['integer', 'bigint']);
    expect($col->is_nullable)->toBe('YES');
});

test('audio_tokens_per_second is nullable with NO default of 25', function (): void {
    // 25 tok/s is published for 3.5 Live Translate and Omni Flash Preview —
    // neither of which this registry seeds. A default here would silently
    // misprice both of our Live models (design.md C-C).
    $col = collect(DB::select(
        "SELECT data_type, is_nullable, column_default FROM information_schema.columns
         WHERE table_name = 'llm_models' AND column_name = 'audio_tokens_per_second'"
    ))->first();

    expect($col->data_type)->toBeIn(['smallint', 'integer']);
    expect($col->is_nullable)->toBe('YES');
    expect($col->column_default)->toBeNull();
});

test('is_available defaults to true', function (): void {
    $col = collect(DB::select(
        "SELECT column_default FROM information_schema.columns
         WHERE table_name = 'llm_models' AND column_name = 'is_available'"
    ))->first();

    expect($col->column_default)->toContain('true');
});
