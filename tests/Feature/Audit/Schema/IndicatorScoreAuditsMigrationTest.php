<?php

declare(strict_types=1);

/**
 * RED — P2.3: `indicator_score_audits` table schema (scoring-audit-jev design D4).
 *
 * Asserts every column D4 specifies is present, the org-first composite
 * index exists (D22), the UNIQUE(audit_run_id, indicator_score_id) index
 * exists, and the four CHECK constraints exist — the status enumeration, the
 * judged/probability equivalence, the judged/outcome_reason equivalence
 * (C-E), and the probability domain [0,1]. Constraint EXISTENCE only; raw-
 * insert VIOLATION behaviour is asserted by
 * `AuditTablesCheckConstraintsTest.php` (P2.7).
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('indicator_score_audits table exists', function (): void {
    expect(Schema::hasTable('indicator_score_audits'))->toBeTrue();
});

test('indicator_score_audits table has all required columns', function (): void {
    $columns = [
        'id', 'organization_id', 'audit_run_id', 'indicator_score_id',
        'status', 'support_probability', 'question_probabilities',
        'outcome_reason', 'created_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('indicator_score_audits', $column))->toBeTrue(
            "Expected indicator_score_audits table to have column '{$column}'"
        );
    }

    // Append-only (D4): no updated_at column exists at all.
    expect(Schema::hasColumn('indicator_score_audits', 'updated_at'))->toBeFalse();
});

test('indicator_score_audits has FK organization_id with cascadeOnDelete', function (): void {
    $constraints = DB::select(
        "SELECT kcu.column_name, rc.delete_rule
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name
         JOIN information_schema.referential_constraints rc
           ON tc.constraint_name = rc.constraint_name
         WHERE tc.table_name = 'indicator_score_audits'
           AND tc.constraint_type = 'FOREIGN KEY'
           AND kcu.column_name = 'organization_id'
           AND rc.delete_rule = 'CASCADE'"
    );

    expect($constraints)->not->toBeEmpty('Expected FK on organization_id with CASCADE on delete');
});

test('indicator_score_audits has FK audit_run_id with cascadeOnDelete', function (): void {
    $constraints = DB::select(
        "SELECT kcu.column_name, rc.delete_rule
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name
         JOIN information_schema.referential_constraints rc
           ON tc.constraint_name = rc.constraint_name
         WHERE tc.table_name = 'indicator_score_audits'
           AND tc.constraint_type = 'FOREIGN KEY'
           AND kcu.column_name = 'audit_run_id'
           AND rc.delete_rule = 'CASCADE'"
    );

    expect($constraints)->not->toBeEmpty('Expected FK on audit_run_id with CASCADE on delete');
});

test('indicator_score_audits has FK indicator_score_id with cascadeOnDelete', function (): void {
    $constraints = DB::select(
        "SELECT kcu.column_name, rc.delete_rule
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name
         JOIN information_schema.referential_constraints rc
           ON tc.constraint_name = rc.constraint_name
         WHERE tc.table_name = 'indicator_score_audits'
           AND tc.constraint_type = 'FOREIGN KEY'
           AND kcu.column_name = 'indicator_score_id'
           AND rc.delete_rule = 'CASCADE'"
    );

    expect($constraints)->not->toBeEmpty('Expected FK on indicator_score_id with CASCADE on delete (both parents, D4)');
});

test('indicator_score_audits has UNIQUE(audit_run_id, indicator_score_id)', function (): void {
    $indexes = Schema::getIndexes('indicator_score_audits');

    $index = collect($indexes)->first(function (array $index): bool {
        return $index['unique'] === true
            && count($index['columns']) === 2
            && in_array('audit_run_id', $index['columns'], true)
            && in_array('indicator_score_id', $index['columns'], true);
    });

    expect($index)->not->toBeNull('Expected a unique index on (audit_run_id, indicator_score_id).');
});

test('indicator_score_audits has the D22 org-first composite index', function (): void {
    $indexes = Schema::getIndexes('indicator_score_audits');

    $index = collect($indexes)->first(function (array $index): bool {
        return count($index['columns']) === 2
            && $index['columns'][0] === 'organization_id'
            && in_array('indicator_score_id', $index['columns'], true);
    });

    expect($index)->not->toBeNull('Expected a composite index led by organization_id (D22).');
});

test('indicator_score_audits_status_check exists', function (): void {
    $constraint = DB::selectOne(
        "SELECT conname FROM pg_constraint WHERE conname = 'indicator_score_audits_status_check'"
    );

    expect($constraint)->not->toBeNull();
});

test('indicator_score_audits_probability_check exists', function (): void {
    $constraint = DB::selectOne(
        "SELECT conname FROM pg_constraint WHERE conname = 'indicator_score_audits_probability_check'"
    );

    expect($constraint)->not->toBeNull();
});

test('indicator_score_audits_reason_check exists (C-E)', function (): void {
    $constraint = DB::selectOne(
        "SELECT conname FROM pg_constraint WHERE conname = 'indicator_score_audits_reason_check'"
    );

    expect($constraint)->not->toBeNull();
});

test('indicator_score_audits_probability_domain_check exists', function (): void {
    $constraint = DB::selectOne(
        "SELECT conname FROM pg_constraint WHERE conname = 'indicator_score_audits_probability_domain_check'"
    );

    expect($constraint)->not->toBeNull();
});
