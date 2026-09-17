<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * H6 (framework-catalogue-authoring PR3b, R3-003): a DB-level cap of 3
 * indicators per `(revision_id, role_id, competency_id)` — and, separately,
 * per `(revision_id, competency_id)` for the role-less `potential`
 * indicators (MTG/LAT) — with no row-expressible constraint able to say it.
 *
 * The existing partial unique indexes on `framework_bars_indicators`
 * (`(revision_id, role_id, competency_id, position)` and the role-less
 * `(revision_id, competency_id, position) WHERE role_id IS NULL`) refuse a
 * DUPLICATE position, never a fourth DISTINCT one — nothing stopped a
 * `position` sequence of 0/1/2/3 from accumulating four rows for the same
 * pair. `StoreBarsIndicatorRequest`'s own 4th-indicator refusal (design D3)
 * is the FormRequest layer of the same rule; this is its DB backstop for a
 * raw, Eloquent-bypassing write — the same posture as the content-
 * immutability trigger.
 *
 * An AFTER trigger, not BEFORE: it needs to COUNT the row's own effect
 * (`count(*) > 3` after the INSERT/UPDATE applied), which a BEFORE trigger
 * cannot see yet without re-deriving NEW's own contribution by hand.
 *
 * THE BASELINE IS EXEMPT — measured, not assumed, and for the SAME reason
 * the content-immutability trigger exempts it (see that migration's own
 * docblock): pre-existing, unrelated tests use the baseline as a generic
 * scratch bucket, and at least one — `IntermediateScaleCassetteTest`'s "D9
 * boundary case" — deliberately builds a competency with 4 indicators to
 * prove the SCORING pipeline (a correctness-critical zone, not catalogue
 * authoring) stays correct against an anomalous count. That is a defensive
 * property of scoring, not a catalogue-authoring rule, and this cap exists
 * to enforce the latter, not to forbid the former. A real superadmin-authored
 * revision (draft or published) still cannot exceed 3 — including under
 * concurrent writers (gga review finding, blocking): a bare `SELECT count(*)`
 * under READ COMMITTED sees only committed rows plus its OWN — two
 * concurrent inserts against a pair holding 2 rows each count 2 + 1 = 3,
 * neither exceeds the cap, and both commit, landing 4. Neither the
 * FormRequest's own count check nor the partial unique index (position is
 * client-supplied, so two callers sending DIFFERENT positions never
 * collide) closes this. `pg_advisory_xact_lock()`, keyed by the exact
 * (revision, role, competency) group, does: the SECOND writer blocks until
 * the FIRST commits, and then re-counts against the now-current, correct
 * row set.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION framework_bars_indicators_enforce_pair_cap() RETURNS trigger AS $$
            DECLARE
                pair_total integer;
                revision_is_baseline boolean;
                lock_key bigint;
            BEGIN
                SELECT is_baseline INTO revision_is_baseline
                    FROM framework_catalog_revisions WHERE id = NEW.revision_id;

                IF revision_is_baseline THEN
                    RETURN NEW;
                END IF;

                IF NEW.role_id IS NOT NULL THEN
                    -- Transaction-scoped advisory lock keyed by the exact
                    -- (revision, role, competency) group — released
                    -- automatically on commit or rollback. A concurrent
                    -- writer targeting the SAME group blocks here until we
                    -- resolve, so its own count() below always reflects our
                    -- committed effect, not a stale snapshot from before it.
                    lock_key := hashtextextended(
                        NEW.revision_id::text || ':' || NEW.role_id::text || ':' || NEW.competency_id::text, 0
                    );
                    PERFORM pg_advisory_xact_lock(lock_key);

                    SELECT count(*) INTO pair_total
                        FROM framework_bars_indicators
                        WHERE revision_id = NEW.revision_id
                          AND role_id = NEW.role_id
                          AND competency_id = NEW.competency_id;

                    IF pair_total > 3 THEN
                        RAISE EXCEPTION 'framework_bars_indicators_role_pair_cap: role % / competency % in revision % would carry % indicators, at most 3 allowed',
                            NEW.role_id, NEW.competency_id, NEW.revision_id, pair_total
                            USING ERRCODE = '23514';
                    END IF;
                ELSE
                    lock_key := hashtextextended(
                        NEW.revision_id::text || ':roleless:' || NEW.competency_id::text, 0
                    );
                    PERFORM pg_advisory_xact_lock(lock_key);

                    SELECT count(*) INTO pair_total
                        FROM framework_bars_indicators
                        WHERE revision_id = NEW.revision_id
                          AND role_id IS NULL
                          AND competency_id = NEW.competency_id;

                    IF pair_total > 3 THEN
                        RAISE EXCEPTION 'framework_bars_indicators_roleless_pair_cap: competency % in revision % would carry % role-less indicators, at most 3 allowed',
                            NEW.competency_id, NEW.revision_id, pair_total
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(
            'CREATE TRIGGER framework_bars_indicators_enforce_pair_cap
             AFTER INSERT OR UPDATE ON framework_bars_indicators
             FOR EACH ROW EXECUTE FUNCTION framework_bars_indicators_enforce_pair_cap()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS framework_bars_indicators_enforce_pair_cap ON framework_bars_indicators');
        DB::unprepared('DROP FUNCTION IF EXISTS framework_bars_indicators_enforce_pair_cap()');
    }
};
