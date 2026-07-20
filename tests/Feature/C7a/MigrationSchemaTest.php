<?php

declare(strict_types=1);

/**
 * Phase 1 migration schema tests (C7a — Interview Session Mechanics).
 *
 * RED tests: assert the 5 migration files exist and the schema matches
 * (columns, types, indexes, FKs, UNIQUE constraints) for:
 * - interview_sessions
 * - utterances
 * - integrity_events
 * - interview_snapshots
 * - projects.provider_override (alter)
 *
 * Tasks: 1.1 (RED)
 * REQ: C7a schema — tenant-scoped interview session data model
 */

use Illuminate\Support\Facades\Schema;

// ─── interview_sessions ───────────────────────────────────────────────────────

test('interview_sessions table exists', function (): void {
    expect(Schema::hasTable('interview_sessions'))->toBeTrue();
});

test('interview_sessions has all required columns', function (): void {
    $columns = [
        'id',
        'organization_id',
        'participant_id',
        'project_id',
        'question_index',
        'competency_code',
        'framework_version_id',
        'provider',
        'provider_session_ref',
        'status',
        'ended_reason',
        'started_at',
        'ended_at',
        'created_at',
        'updated_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('interview_sessions', $column))
            ->toBeTrue("Column '{$column}' is missing from interview_sessions");
    }
});

test('interview_sessions.status defaults to pending', function (): void {
    $col = collect(
        DB::select("SELECT column_default FROM information_schema.columns
                    WHERE table_name = 'interview_sessions' AND column_name = 'status'")
    )->first();

    expect($col->column_default)->toContain('pending');
});

test('interview_sessions.question_index is integer', function (): void {
    $col = collect(
        DB::select("SELECT data_type FROM information_schema.columns
                    WHERE table_name = 'interview_sessions' AND column_name = 'question_index'")
    )->first();

    expect($col->data_type)->toBeIn(['integer', 'bigint']);
});

test('interview_sessions.provider_session_ref is nullable', function (): void {
    $col = collect(
        DB::select("SELECT is_nullable FROM information_schema.columns
                    WHERE table_name = 'interview_sessions' AND column_name = 'provider_session_ref'")
    )->first();

    expect($col->is_nullable)->toBe('YES');
});

test('interview_sessions.ended_reason is nullable', function (): void {
    $col = collect(
        DB::select("SELECT is_nullable FROM information_schema.columns
                    WHERE table_name = 'interview_sessions' AND column_name = 'ended_reason'")
    )->first();

    expect($col->is_nullable)->toBe('YES');
});

test('interview_sessions.started_at is nullable timestamptz', function (): void {
    $col = collect(
        DB::select("SELECT data_type, is_nullable FROM information_schema.columns
                    WHERE table_name = 'interview_sessions' AND column_name = 'started_at'")
    )->first();

    expect($col->data_type)->toBe('timestamp with time zone');
    expect($col->is_nullable)->toBe('YES');
});

test('interview_sessions.ended_at is nullable timestamptz', function (): void {
    $col = collect(
        DB::select("SELECT data_type, is_nullable FROM information_schema.columns
                    WHERE table_name = 'interview_sessions' AND column_name = 'ended_at'")
    )->first();

    expect($col->data_type)->toBe('timestamp with time zone');
    expect($col->is_nullable)->toBe('YES');
});

test('interview_sessions has UNIQUE(participant_id, competency_code)', function (): void {
    $indexes = DB::select(
        "SELECT indexname, indexdef
         FROM pg_indexes
         WHERE tablename = 'interview_sessions'
           AND indexdef LIKE '%participant_id%'
           AND indexdef LIKE '%competency_code%'"
    );

    expect($indexes)->not->toBeEmpty('UNIQUE(participant_id, competency_code) index missing');

    $defs = collect($indexes)->pluck('indexdef')->implode(' ');
    expect(strtolower($defs))->toContain('unique');
});

test('interview_sessions has composite index (organization_id, participant_id)', function (): void {
    $indexes = DB::select(
        "SELECT indexname, indexdef
         FROM pg_indexes
         WHERE tablename = 'interview_sessions'
           AND indexdef LIKE '%organization_id%'
           AND indexdef LIKE '%participant_id%'"
    );

    expect($indexes)->not->toBeEmpty('Composite index (organization_id, participant_id) missing');
});

test('interview_sessions has composite index (organization_id, status)', function (): void {
    $indexes = DB::select(
        "SELECT indexname, indexdef
         FROM pg_indexes
         WHERE tablename = 'interview_sessions'
           AND indexdef LIKE '%organization_id%'
           AND indexdef LIKE '%status%'"
    );

    expect($indexes)->not->toBeEmpty('Composite index (organization_id, status) missing');
});

test('interview_sessions.participant_id FK cascades on delete', function (): void {
    $fk = collect(DB::select(
        "SELECT rc.delete_rule
         FROM information_schema.referential_constraints rc
         JOIN information_schema.key_column_usage kcu
           ON rc.constraint_name = kcu.constraint_name
         WHERE kcu.table_name = 'interview_sessions'
           AND kcu.column_name = 'participant_id'"
    ))->first();

    expect($fk)->not->toBeNull('FK on participant_id not found');
    expect($fk->delete_rule)->toBe('CASCADE');
});

test('interview_sessions.project_id FK restricts on delete', function (): void {
    $fk = collect(DB::select(
        "SELECT rc.delete_rule
         FROM information_schema.referential_constraints rc
         JOIN information_schema.key_column_usage kcu
           ON rc.constraint_name = kcu.constraint_name
         WHERE kcu.table_name = 'interview_sessions'
           AND kcu.column_name = 'project_id'"
    ))->first();

    expect($fk)->not->toBeNull('FK on project_id not found');
    expect($fk->delete_rule)->toBe('RESTRICT');
});

// ─── utterances ───────────────────────────────────────────────────────────────

test('utterances table exists', function (): void {
    expect(Schema::hasTable('utterances'))->toBeTrue();
});

test('utterances has all required columns', function (): void {
    $columns = ['id', 'interview_session_id', 'organization_id', 'speaker', 'text', 'ts'];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('utterances', $column))
            ->toBeTrue("Column '{$column}' is missing from utterances");
    }
});

test('utterances.ts is timestamptz', function (): void {
    $col = collect(
        DB::select("SELECT data_type FROM information_schema.columns
                    WHERE table_name = 'utterances' AND column_name = 'ts'")
    )->first();

    expect($col->data_type)->toBe('timestamp with time zone');
});

test('utterances has composite index (organization_id, interview_session_id)', function (): void {
    $indexes = DB::select(
        "SELECT indexname FROM pg_indexes
         WHERE tablename = 'utterances'
           AND indexdef LIKE '%organization_id%'
           AND indexdef LIKE '%interview_session_id%'"
    );

    expect($indexes)->not->toBeEmpty('Composite index (organization_id, interview_session_id) missing from utterances');
});

test('utterances.interview_session_id FK cascades on delete', function (): void {
    $fk = collect(DB::select(
        "SELECT rc.delete_rule
         FROM information_schema.referential_constraints rc
         JOIN information_schema.key_column_usage kcu
           ON rc.constraint_name = kcu.constraint_name
         WHERE kcu.table_name = 'utterances'
           AND kcu.column_name = 'interview_session_id'"
    ))->first();

    expect($fk)->not->toBeNull('FK on interview_session_id not found in utterances');
    expect($fk->delete_rule)->toBe('CASCADE');
});

// ─── integrity_events ─────────────────────────────────────────────────────────

test('integrity_events table exists', function (): void {
    expect(Schema::hasTable('integrity_events'))->toBeTrue();
});

test('integrity_events has all required columns', function (): void {
    $columns = ['id', 'interview_session_id', 'organization_id', 'kind', 'payload', 'ts'];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('integrity_events', $column))
            ->toBeTrue("Column '{$column}' is missing from integrity_events");
    }
});

test('integrity_events.payload is jsonb', function (): void {
    $col = collect(
        DB::select("SELECT data_type FROM information_schema.columns
                    WHERE table_name = 'integrity_events' AND column_name = 'payload'")
    )->first();

    expect($col->data_type)->toBe('jsonb');
});

test('integrity_events.ts is timestamptz', function (): void {
    $col = collect(
        DB::select("SELECT data_type FROM information_schema.columns
                    WHERE table_name = 'integrity_events' AND column_name = 'ts'")
    )->first();

    expect($col->data_type)->toBe('timestamp with time zone');
});

test('integrity_events has composite index (organization_id, interview_session_id)', function (): void {
    $indexes = DB::select(
        "SELECT indexname FROM pg_indexes
         WHERE tablename = 'integrity_events'
           AND indexdef LIKE '%organization_id%'
           AND indexdef LIKE '%interview_session_id%'"
    );

    expect($indexes)->not->toBeEmpty('Composite index (organization_id, interview_session_id) missing from integrity_events');
});

test('integrity_events.interview_session_id FK cascades on delete', function (): void {
    $fk = collect(DB::select(
        "SELECT rc.delete_rule
         FROM information_schema.referential_constraints rc
         JOIN information_schema.key_column_usage kcu
           ON rc.constraint_name = kcu.constraint_name
         WHERE kcu.table_name = 'integrity_events'
           AND kcu.column_name = 'interview_session_id'"
    ))->first();

    expect($fk)->not->toBeNull('FK on interview_session_id not found in integrity_events');
    expect($fk->delete_rule)->toBe('CASCADE');
});

// ─── interview_snapshots ──────────────────────────────────────────────────────

test('interview_snapshots table exists', function (): void {
    expect(Schema::hasTable('interview_snapshots'))->toBeTrue();
});

test('interview_snapshots has all required columns', function (): void {
    $columns = ['id', 'interview_session_id', 'organization_id', 's3_key', 'taken_at'];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('interview_snapshots', $column))
            ->toBeTrue("Column '{$column}' is missing from interview_snapshots");
    }
});

test('interview_snapshots.taken_at is timestamptz', function (): void {
    $col = collect(
        DB::select("SELECT data_type FROM information_schema.columns
                    WHERE table_name = 'interview_snapshots' AND column_name = 'taken_at'")
    )->first();

    expect($col->data_type)->toBe('timestamp with time zone');
});

test('interview_snapshots has composite index (organization_id, interview_session_id)', function (): void {
    $indexes = DB::select(
        "SELECT indexname FROM pg_indexes
         WHERE tablename = 'interview_snapshots'
           AND indexdef LIKE '%organization_id%'
           AND indexdef LIKE '%interview_session_id%'"
    );

    expect($indexes)->not->toBeEmpty('Composite index (organization_id, interview_session_id) missing from interview_snapshots');
});

test('interview_snapshots.interview_session_id FK cascades on delete', function (): void {
    $fk = collect(DB::select(
        "SELECT rc.delete_rule
         FROM information_schema.referential_constraints rc
         JOIN information_schema.key_column_usage kcu
           ON rc.constraint_name = kcu.constraint_name
         WHERE kcu.table_name = 'interview_snapshots'
           AND kcu.column_name = 'interview_session_id'"
    ))->first();

    expect($fk)->not->toBeNull('FK on interview_session_id not found in interview_snapshots');
    expect($fk->delete_rule)->toBe('CASCADE');
});

// ─── projects.provider_override ───────────────────────────────────────────────

test('projects table has provider_override column', function (): void {
    expect(Schema::hasColumn('projects', 'provider_override'))->toBeTrue();
});

test('projects.provider_override is nullable', function (): void {
    $col = collect(
        DB::select("SELECT is_nullable FROM information_schema.columns
                    WHERE table_name = 'projects' AND column_name = 'provider_override'")
    )->first();

    expect($col->is_nullable)->toBe('YES');
});
