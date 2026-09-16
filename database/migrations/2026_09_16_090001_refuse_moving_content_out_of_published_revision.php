<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * H7 (framework-catalogue-authoring PR3b, R3-005): the content-immutability
 * trigger (`2026_09_15_201434_enforce_catalogue_published_content_
 * immutability`) reads only `NEW.revision_id` on UPDATE — it never looks at
 * `OLD.revision_id`. A raw UPDATE that changes a row's `revision_id` FROM a
 * published, non-baseline revision TO some other revision passed the
 * original trigger (it only checked where the row was GOING, never where it
 * was COMING FROM), moving content OUT of a revision that is supposed to be
 * frozen forever — the exact class of write immutability exists to refuse.
 *
 * `CREATE OR REPLACE FUNCTION`, same technique the original migration uses
 * and explains: redefining the function body updates every trigger already
 * attached to it, with no need to drop and recreate the triggers themselves,
 * and survives `migrate:fresh` dropping the tables without leaving an
 * orphaned function behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION framework_catalog_refuse_published_content_write() RETURNS trigger AS $$
            DECLARE
                old_state text;
                old_is_baseline boolean;
                new_state text;
                new_is_baseline boolean;
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    SELECT state, is_baseline INTO old_state, old_is_baseline
                        FROM framework_catalog_revisions WHERE id = OLD.revision_id FOR SHARE;

                    IF old_state = 'published' AND NOT old_is_baseline THEN
                        RAISE EXCEPTION 'framework_catalog_published_content_immutable: % on table % refused — revision % is published',
                            TG_OP, TG_TABLE_NAME, OLD.revision_id
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                IF TG_OP IN ('INSERT', 'UPDATE') THEN
                    -- FOR SHARE, not a plain SELECT (gga review finding,
                    -- blocking, carried over from the original migration):
                    -- `PublishRevision::publish()` takes `SELECT ... FOR
                    -- UPDATE` on the revision row for the duration of its
                    -- sweep-and-flip. A plain read here does not wait for
                    -- that lock, so a concurrent content write mid-publish
                    -- could still see 'draft' and commit — the sweep
                    -- already verified, the content then changes anyway,
                    -- and a published revision ships whatever landed after.
                    -- FOR SHARE queues behind the FOR UPDATE lock and
                    -- re-reads the now-'published' row once it releases.
                    SELECT state, is_baseline INTO new_state, new_is_baseline
                        FROM framework_catalog_revisions WHERE id = NEW.revision_id FOR SHARE;

                    IF new_state = 'published' AND NOT new_is_baseline THEN
                        RAISE EXCEPTION 'framework_catalog_published_content_immutable: % on table % refused — revision % is published',
                            TG_OP, TG_TABLE_NAME, NEW.revision_id
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        // Restores the PRE-H7 function body VERBATIM — including its own
        // explanatory comments, not only its logic (gga review finding: an
        // earlier version of this `down()` dropped them, so rolling H7 back
        // would have left an unexplained `FOR SHARE` in `pg_get_functiondef()`
        // output that looks removable to the next reader) — since `down()`
        // must reverse only THIS migration's change, not drop the
        // trigger/function entirely; the base migration's own `down()` owns
        // that.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION framework_catalog_refuse_published_content_write() RETURNS trigger AS $$
            DECLARE
                target_revision_id bigint;
                target_state text;
                target_is_baseline boolean;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    target_revision_id := OLD.revision_id;
                ELSE
                    target_revision_id := NEW.revision_id;
                END IF;

                -- FOR SHARE, not a plain SELECT (gga review finding,
                -- blocking): PublishRevision::publish() takes SELECT ...
                -- FOR UPDATE on the revision row for the duration of its
                -- sweep-and-flip. A plain read here does not wait for that
                -- lock, so a concurrent content write mid-publish could
                -- still see 'draft' and commit — the sweep already
                -- verified, the content then changes anyway, and a
                -- published revision ships whatever landed after. FOR
                -- SHARE queues behind the FOR UPDATE lock and re-reads the
                -- now-'published' row once it releases.
                SELECT state, is_baseline INTO target_state, target_is_baseline
                    FROM framework_catalog_revisions WHERE id = target_revision_id FOR SHARE;

                -- Draft (mutable) or the baseline (governed by the seeder's
                -- own gate, never this trigger) — allowed.
                IF target_state IS DISTINCT FROM 'published' OR target_is_baseline THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;

                RAISE EXCEPTION 'framework_catalog_published_content_immutable: % on table % refused — revision % is published',
                    TG_OP, TG_TABLE_NAME, target_revision_id
                    USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
