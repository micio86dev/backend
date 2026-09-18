<?php

declare(strict_types=1);

/**
 * RED — P2.1: `indicator_score_audit_runs` table schema (scoring-audit-jev design D4).
 *
 * Asserts every column D4 specifies is present, the org-first composite index
 * exists (D22), and the four CHECK constraints exist at the database — the
 * status enumeration, the failure_reason equivalence, the four-term coverage
 * identity (C-D), and the non-negative cost guard (D8). Constraint EXISTENCE
 * only; the raw-insert VIOLATION behaviour is asserted by
 * `AuditTablesCheckConstraintsTest.php` (P2.7) — mirrors the split already
 * established between this file's shape and that one's, e.g.
 * `UtteranceTurnKindCheckTest.php`'s convalidated-existence test vs. its own
 * violation test.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('indicator_score_audit_runs table exists', function (): void {
    expect(Schema::hasTable('indicator_score_audit_runs'))->toBeTrue();
});

test('indicator_score_audit_runs table has all required columns', function (): void {
    $columns = [
        'id', 'organization_id', 'evaluation_id', 'requested_by_user_id',
        'status', 'failure_reason',
        'indicators_total', 'indicators_judged', 'indicators_skipped',
        'indicators_unavailable', 'indicators_malformed',
        'input_tokens', 'output_tokens', 'estimated_cost_usd', 'latency_ms',
        'judge_model_version', 'audit_prompt_version', 'created_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('indicator_score_audit_runs', $column))->toBeTrue(
            "Expected indicator_score_audit_runs table to have column '{$column}'"
        );
    }

    // Append-only (D4): no updated_at column exists at all.
    expect(Schema::hasColumn('indicator_score_audit_runs', 'updated_at'))->toBeFalse();
});

test('indicator_score_audit_runs has FK organization_id with cascadeOnDelete', function (): void {
    $constraints = DB::select(
        "SELECT kcu.column_name, rc.delete_rule
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name
         JOIN information_schema.referential_constraints rc
           ON tc.constraint_name = rc.constraint_name
         WHERE tc.table_name = 'indicator_score_audit_runs'
           AND tc.constraint_type = 'FOREIGN KEY'
           AND kcu.column_name = 'organization_id'
           AND rc.delete_rule = 'CASCADE'"
    );

    expect($constraints)->not->toBeEmpty('Expected FK on organization_id with CASCADE on delete');
});

test('indicator_score_audit_runs has FK evaluation_id with cascadeOnDelete', function (): void {
    $constraints = DB::select(
        "SELECT kcu.column_name, rc.delete_rule
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name
         JOIN information_schema.referential_constraints rc
           ON tc.constraint_name = rc.constraint_name
         WHERE tc.table_name = 'indicator_score_audit_runs'
           AND tc.constraint_type = 'FOREIGN KEY'
           AND kcu.column_name = 'evaluation_id'
           AND rc.delete_rule = 'CASCADE'"
    );

    expect($constraints)->not->toBeEmpty('Expected FK on evaluation_id with CASCADE on delete');
});

test('indicator_score_audit_runs has FK requested_by_user_id with nullOnDelete', function (): void {
    $constraints = DB::select(
        "SELECT kcu.column_name, rc.delete_rule
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name
         JOIN information_schema.referential_constraints rc
           ON tc.constraint_name = rc.constraint_name
         WHERE tc.table_name = 'indicator_score_audit_runs'
           AND tc.constraint_type = 'FOREIGN KEY'
           AND kcu.column_name = 'requested_by_user_id'
           AND rc.delete_rule = 'SET NULL'"
    );

    expect($constraints)->not->toBeEmpty('Expected FK on requested_by_user_id with SET NULL on delete');
});

test('indicator_score_audit_runs has the D22 org-first composite index', function (): void {
    $indexes = Schema::getIndexes('indicator_score_audit_runs');

    $index = collect($indexes)->first(function (array $index): bool {
        return count($index['columns']) === 3
            && $index['columns'][0] === 'organization_id'
            && in_array('evaluation_id', $index['columns'], true)
            && in_array('created_at', $index['columns'], true);
    });

    expect($index)->not->toBeNull('Expected a composite index led by organization_id (D22).');
});

test('indicator_score_audit_runs_status_check exists', function (): void {
    $constraint = DB::selectOne(
        "SELECT conname FROM pg_constraint WHERE conname = 'indicator_score_audit_runs_status_check'"
    );

    expect($constraint)->not->toBeNull();
});

test('indicator_score_audit_runs_failure_reason_check exists', function (): void {
    $constraint = DB::selectOne(
        "SELECT conname FROM pg_constraint WHERE conname = 'indicator_score_audit_runs_failure_reason_check'"
    );

    expect($constraint)->not->toBeNull();
});

test('indicator_score_audit_runs_coverage_check exists (C-D four-term identity)', function (): void {
    $constraint = DB::selectOne(
        "SELECT conname FROM pg_constraint WHERE conname = 'indicator_score_audit_runs_coverage_check'"
    );

    expect($constraint)->not->toBeNull();
});

test('indicator_score_audit_runs_cost_check exists', function (): void {
    $constraint = DB::selectOne(
        "SELECT conname FROM pg_constraint WHERE conname = 'indicator_score_audit_runs_cost_check'"
    );

    expect($constraint)->not->toBeNull();
});
