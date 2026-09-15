<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-enforced content immutability for a published, superadmin-authored
 * revision (framework-catalogue-authoring PR3, catalogue-authoring spec —
 * "Publishing freezes every row, including future inserts"; review advisory
 * R3-001 on PR1).
 *
 * `FrameworkCatalogRevision::booted()` (PR1) already refuses mutating the
 * REVISION row itself once published. Nothing refuses a write to its
 * CONTENT — `framework_roles`, `framework_competencies`,
 * `framework_bars_indicators`, `framework_role_competency`,
 * `framework_default_questions`.
 *
 * THE BASELINE (`is_baseline = true`) IS DELIBERATELY EXEMPT FROM THIS
 * TRIGGER — not an oversight, the load-bearing decision. Two independent
 * facts about it collide with a blanket "published = immutable" rule:
 *
 *   1. The baseline is `published` from the moment it is created (PR1,
 *      2026_09_15_090004) and stays that way forever — it is never a
 *      `draft`. `FrameworkCatalogSeeder`'s own write gate (PR2, D2)
 *      already governs it correctly: populate once while empty, zero
 *      writes once it carries content. A blanket trigger duplicating that
 *      exact rule at the DB layer was tried and rejected during this PR —
 *      see the CI note below.
 *   2. Across this suite, the baseline revision (`revision_id = 1` in every
 *      test database) is also the DEFAULT landing spot for every
 *      `Role::factory()`/`Competency::factory()` call that does not care
 *      which revision it belongs to — hundreds of pre-existing, unrelated
 *      tests across the whole suite, established by PR1's own explicit
 *      compatibility promise ("every existing factory/direct-create call
 *      site across the suite keeps working unmodified"). A blanket trigger
 *      firing for ANY published revision broke dozens of them the moment
 *      the baseline held any content at all — a regression across code this
 *      PR does not own and has no reason to change.
 *
 * So the trigger's actual, narrower job is the one this PR actually adds: a
 * SUPERADMIN-PUBLISHED, NON-BASELINE revision (`OpenDraftRevision`'s clone,
 * flipped by `PublishRevision`) refuses INSERT/UPDATE/DELETE on its content,
 * no exceptions. `PublishedRevisionImmutabilityTest` (Phase 12) proves this
 * against exactly that kind of revision. The ordinary CRUD write path (Phase
 * 12) never reaches this trigger at all in the refusing direction — every
 * controller action resolves and writes to the currently OPEN DRAFT, which
 * this trigger always allows; it is the backstop for a write that (by
 * omission or by bug) named an already-published, non-baseline revision
 * directly, exactly what R3-001 flagged.
 *
 * One PL/pgSQL function, attached to all five catalogue-content tables —
 * DELETE included, matching `FrameworkCatalogRevision::booted()`'s own
 * `deleting` guard being a real, separate listener rather than assumed
 * covered by `updating`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `CREATE OR REPLACE`, not a bare `CREATE`: `migrate:fresh` drops
        // every TABLE it finds but never runs a migration's `down()`, so a
        // standalone FUNCTION (attached to no table) survives it untouched
        // — a bare `CREATE FUNCTION` on the next `migrate:fresh` then fails
        // "already exists" even though every table this function's
        // triggers were attached to is gone.
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

        foreach ([
            'framework_roles',
            'framework_competencies',
            'framework_bars_indicators',
            'framework_role_competency',
            'framework_default_questions',
        ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_refuse_published_content_write
                 BEFORE INSERT OR UPDATE OR DELETE ON {$table}
                 FOR EACH ROW EXECUTE FUNCTION framework_catalog_refuse_published_content_write()"
            );
        }
    }

    public function down(): void
    {
        foreach ([
            'framework_roles',
            'framework_competencies',
            'framework_bars_indicators',
            'framework_role_competency',
            'framework_default_questions',
        ] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_refuse_published_content_write ON {$table}");
        }

        DB::unprepared('DROP FUNCTION IF EXISTS framework_catalog_refuse_published_content_write()');
    }
};
