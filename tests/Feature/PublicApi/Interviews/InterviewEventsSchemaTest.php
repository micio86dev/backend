<?php

declare(strict_types=1);

/**
 * `interview_events` schema — public-api step 5, G-34. gga round 3 finding
 * 3: the events-list read index must lead with `organization_id` (D22
 * org-lead rule), matching every other tenant-scoped composite index in
 * this codebase (mirrors `tests/Feature/C6/Schema/ParticipantsMigrationTest.php`'s
 * own `pg_indexes` pattern).
 */

use Illuminate\Support\Facades\Schema;

test('gga finding 3: interview_events_participant_ordering_index leads with organization_id', function (): void {
    $indexes = Schema::getConnection()->select("
        SELECT indexdef
        FROM pg_indexes
        WHERE tablename = 'interview_events'
          AND indexname = 'interview_events_participant_ordering_index'
    ");

    expect($indexes)->not->toBeEmpty('interview_events_participant_ordering_index should exist');

    $indexDef = $indexes[0]->indexdef;

    // Column order matters here (it is what makes the org-lead rule real,
    // not just "organization_id is present somewhere in the index") — the
    // exact parenthesised column list, in order.
    expect($indexDef)->toContain('(organization_id, participant_id, occurred_at, id)');
});

test('gga finding 8: no redundant [organization_id, participant_id] index exists alongside the wider ordering index', function (): void {
    // That 2-column shape is a strict LEADING-COLUMN PREFIX of
    // interview_events_participant_ordering_index — Postgres serves a
    // prefix lookup from the wider index just as well, so a second,
    // narrower index buys nothing and only costs extra writes/storage.
    $indexes = Schema::getConnection()->select("
        SELECT indexname, indexdef
        FROM pg_indexes
        WHERE tablename = 'interview_events'
    ");

    $twoColumnIndex = collect($indexes)->first(
        fn ($i) => $i->indexname !== 'interview_events_participant_ordering_index'
            && str_contains($i->indexdef, '(organization_id, participant_id)')
    );

    expect($twoColumnIndex)->toBeNull(
        'A redundant [organization_id, participant_id] index should not exist alongside the wider ordering index.'
    );
});
