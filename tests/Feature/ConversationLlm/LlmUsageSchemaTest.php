<?php

declare(strict_types=1);

/**
 * Schema tests for the P6a snapshot columns and the P6a usage table
 * (pluggable-conversation-llm PR P6a, design D5).
 *
 * RED first: none of this exists before the two migrations below land.
 *
 * REQ: conversation-llm "The session records which template/model/binding
 *      state it was issued under, and cost history is append-only and
 *      survives the transcript purge"
 */

use Illuminate\Support\Facades\Schema;

// ─── interview_sessions snapshot columns ──────────────────────────────────

test('interview_sessions gains the four LLM snapshot columns, all nullable', function (): void {
    foreach (['avatar_template_id', 'llm_model_key', 'llm_binding_status', 'system_prompt_chars'] as $column) {
        expect(Schema::hasColumn('interview_sessions', $column))
            ->toBeTrue("Column '{$column}' is missing from interview_sessions");
    }

    foreach (['avatar_template_id', 'llm_model_key', 'llm_binding_status', 'system_prompt_chars'] as $column) {
        $col = collect(
            DB::select("SELECT is_nullable FROM information_schema.columns
                        WHERE table_name = 'interview_sessions' AND column_name = ?", [$column])
        )->first();

        expect($col->is_nullable)->toBe('YES', "{$column} must be nullable");
    }
});

test('interview_sessions.avatar_template_id FK sets null on delete', function (): void {
    $fk = collect(DB::select(
        "SELECT rc.delete_rule
         FROM information_schema.referential_constraints rc
         JOIN information_schema.key_column_usage kcu
           ON rc.constraint_name = kcu.constraint_name
         WHERE kcu.table_name = 'interview_sessions'
           AND kcu.column_name = 'avatar_template_id'"
    ))->first();

    expect($fk)->not->toBeNull('FK on avatar_template_id not found');
    expect($fk->delete_rule)->toBe('SET NULL');
});

test('interview_sessions.system_prompt_chars is an integer type', function (): void {
    $col = collect(
        DB::select("SELECT data_type FROM information_schema.columns
                    WHERE table_name = 'interview_sessions' AND column_name = 'system_prompt_chars'")
    )->first();

    expect($col->data_type)->toBeIn(['integer', 'bigint']);
});

// ─── interview_session_llm_usage ──────────────────────────────────────────

test('interview_session_llm_usage table exists', function (): void {
    expect(Schema::hasTable('interview_session_llm_usage'))->toBeTrue();
});

test('interview_session_llm_usage has all required columns', function (): void {
    $columns = [
        'id',
        'organization_id',
        'interview_session_id',
        'turn_count',
        'system_prompt_chars',
        'participant_chars',
        'avatar_chars',
        'live_seconds',
        'estimated_input_tokens',
        'estimated_output_tokens',
        'estimated_cost_usd',
        'estimation_method',
        'rate_card',
        'actual_input_tokens',
        'actual_output_tokens',
        'actual_cost_usd',
        'created_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('interview_session_llm_usage', $column))
            ->toBeTrue("Column '{$column}' is missing from interview_session_llm_usage");
    }
});

test('interview_session_llm_usage has NO updated_at column — append-only', function (): void {
    expect(Schema::hasColumn('interview_session_llm_usage', 'updated_at'))->toBeFalse();
});

test('interview_session_llm_usage.interview_session_id is UNIQUE', function (): void {
    $indexes = DB::select(
        "SELECT indexdef FROM pg_indexes
         WHERE tablename = 'interview_session_llm_usage'
           AND indexdef LIKE '%interview_session_id%'
           AND indexdef LIKE '%UNIQUE%'"
    );

    expect($indexes)->not->toBeEmpty('UNIQUE(interview_session_id) missing');
});

test('interview_session_llm_usage.rate_card is jsonb', function (): void {
    $col = collect(
        DB::select("SELECT data_type FROM information_schema.columns
                    WHERE table_name = 'interview_session_llm_usage' AND column_name = 'rate_card'")
    )->first();

    expect($col->data_type)->toBe('jsonb');
});

test('interview_session_llm_usage.actual_* columns are nullable', function (): void {
    foreach (['actual_input_tokens', 'actual_output_tokens', 'actual_cost_usd'] as $column) {
        $col = collect(
            DB::select("SELECT is_nullable FROM information_schema.columns
                        WHERE table_name = 'interview_session_llm_usage' AND column_name = ?", [$column])
        )->first();

        expect($col->is_nullable)->toBe('YES', "{$column} must be nullable");
    }
});

test('interview_session_llm_usage.interview_session_id FK cascades on delete', function (): void {
    $fk = collect(DB::select(
        "SELECT rc.delete_rule
         FROM information_schema.referential_constraints rc
         JOIN information_schema.key_column_usage kcu
           ON rc.constraint_name = kcu.constraint_name
         WHERE kcu.table_name = 'interview_session_llm_usage'
           AND kcu.column_name = 'interview_session_id'"
    ))->first();

    expect($fk)->not->toBeNull('FK on interview_session_id not found');
    expect($fk->delete_rule)->toBe('CASCADE');
});
