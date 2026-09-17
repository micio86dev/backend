<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BarsIndicator;
use App\Models\CatalogMeta;
use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkGap;
use App\Models\Role;
use App\Services\FrameworkCatalog\CompetencyNormalizer;
use App\Services\FrameworkCatalog\DTO\IndicatorDTO;
use App\Support\Catalogue\CatalogueRules;
use App\Support\Catalogue\Concerns\ReadsCatalogueLocaleMaps;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * FrameworkCatalogSeeder (C3 + framework-catalog-it-translations locale
 * writing + framework-catalogue-authoring PR2 revision gate).
 *
 * Seeds the **baseline** `framework_catalog_revisions` row from split-file
 * JSON:
 *   docs/app_description/02-domain/framework/roles.json
 *   docs/app_description/02-domain/framework/competencies.json
 *   docs/app_description/02-domain/framework/bars/{ROLE}.json
 *
 * Every catalogue-content row this seeder writes is scoped to the baseline
 * revision's id (framework-catalogue-authoring PR1, D1) — this seeder never
 * targets any other revision. PR 3's `OpenDraftRevision`/`PublishRevision`
 * own every other revision's lifecycle.
 *
 * Locale dimension (framework-catalog-it-translations, design D1/D4): every
 * translatable field's JSON value is a locale map — `{"en": "...", "it":
 * "..."}`, `en` mandatory, `it` optional until authored. The seeder writes
 * `setTranslation($field, $locale, $value)` for EVERY locale present in the
 * source map, not `en` only.
 *
 * Idempotent + delete-stale (sync):
 *   - upsert (find-by-revision-and-code, else new) for roles and competencies
 *   - sync() for the role_competency pivot (removes stale assignments)
 *   - upsert + delete-stale for bars_indicators per (role, competency)
 *   - updateOrCreate() for all framework_gaps (NULLS NOT DISTINCT at DB layer)
 *
 * **Baseline-revision write gate (framework-catalogue-authoring PR2, D2)**,
 * replacing the old platform-wide `FrameworkVersion.is_locked` lock-guard
 * entirely — that flag no longer has any bearing on this seeder:
 *
 *   - The baseline revision is ALWAYS `published` by construction (PR1's
 *     backfill migration inserts it that way unconditionally — see that
 *     migration's own docblock: "already-shipped, already-scored content ...
 *     not mutable"). A bare `state === 'published'` gate would therefore
 *     refuse the very FIRST seed on a freshly migrated, empty database —
 *     `php artisan migrate --seed` runs this seeder immediately after the
 *     backfill migration creates a published baseline with zero rows in it
 *     (see `DatabaseSeeder::run()`). That is the fresh-install contradiction
 *     PR2's own task notes flag, and it is resolved here rather than by
 *     making the migration conditionally insert a draft baseline: every
 *     existing PR1 invariant test asserts the baseline is unconditionally
 *     `published` immediately after migration, with no seeding —
 *     `FrameworkCatalogRevisionInvariantsTest::test('the baseline revision is
 *     published, not draft')` chief among them — and changing that would
 *     reopen settled, tested PR1 behaviour rather than fix PR2's own gap.
 *   - The gate therefore asks "does the baseline already carry content", not
 *     merely "is it published": `writesAreBlocked()` is true only when the
 *     baseline is `published` AND `framework_competencies` already has a row
 *     for it. A published-but-empty baseline is populated normally; a
 *     published baseline that already carries content is immutable, full
 *     stop — no additive insert, no mutation of an existing row, nothing.
 *   - A **draft** baseline (never produced by this schema today — the
 *     Eloquent immutability guard on `FrameworkCatalogRevision` refuses to
 *     flip a published row back to draft; only a raw `DB::table()` write
 *     bypassing that guard can construct one, which is exactly how this
 *     seeder's own tests exercise the branch) still runs the full
 *     delete-stale sync unconditionally, matching the framework-catalog spec
 *     delta's literal "draft baseline still syncs" scenario.
 *   - `catalog_meta` bookkeeping is UNTOUCHED BY THE GATE: `CatalogMeta::
 *     bump()` needs no special-casing, since it already fires only when
 *     `$structuralChange` was set, and nothing sets it while writes are
 *     blocked.
 *   - `framework_gaps` reconciliation RUNS regardless of the gate, but its
 *     WRITES do not all mean the same thing while blocked (post-PR2-review
 *     correction — the original text here read "computed from the source
 *     JSON ... keeps running even while catalogue-content writes are
 *     blocked" for every gap kind, unconditionally, which was the defect).
 *     **Gap resolution reflects DATABASE state, not the JSON.** Recording a
 *     gap as still `pending_authoring` is always safe — it describes what
 *     the JSON currently declares, never a claim about the database — and
 *     stays unconditional. MARKING a gap `resolved` is a claim that the
 *     database now satisfies the rule, and while writes are blocked nothing
 *     reached the database this run: role_no_bars, competency_no_bars,
 *     missing_role_meta, and the per-pair/global `missing_translation`
 *     resolutions are therefore skipped entirely while blocked, leaving
 *     whatever a prior unblocked run left. Before this fix, editing the
 *     source JSON on a published, already-populated baseline could mark
 *     these gaps resolved even though the corresponding write never landed
 *     — while the `missing_potential_competency` check (below) already read
 *     `BarsIndicator` existence from the DATABASE and was never affected;
 *     it is the pattern the fix generalizes. `seeder_lock_guard_active` is
 *     unaffected — it is not derived from JSON at all.
 *
 * **Everything the old per-row ADDITIVE lock-guard did is deleted, not
 * adapted.** The prior `hasLockedVersions()` gate ran a nuanced per-call-site
 * `$model->exists` mode — new rows inserted, existing rows preserved, plus a
 * "fill an empty locale slot even on an existing row" exception
 * (`fillEmptyLocalesUnderLock()` / `recordLockedFillEmptyLocaleGap()`) so a
 * catalogue locked by ANY tenant's first project could still gain an `it`
 * translation. Revision immutability replaces that nuance entirely (design
 * D2): a `published`-with-content baseline accepts NO writes at all,
 * additive or otherwise, because immutability is now a property of the
 * revision row a superadmin explicitly publishes (PR 3), not something
 * inferred from any `FrameworkVersion` being locked anywhere on the
 * platform. There is no more partially-writable state, so there is nothing
 * left for a fill-empty-locale exception to fill.
 *
 * Atomicity (post-verification hardening, finding #1): the whole run, INCLUDING
 * CatalogMeta::bump(), executes inside a single DB::transaction(). Before this,
 * every write committed as it happened while bump() was gated on an in-memory
 * $structuralChange flag computed across the whole method. A throw partway
 * through (a malformed bars/{ROLE}.json — json_decode(..., JSON_THROW_ON_ERROR)
 * — is the natural example, but any exception anywhere in the method has the
 * same shape) left everything already processed committed, while bump() was
 * never reached. A clean retry then found every structurally-new row from that
 * partial run ALREADY EXISTING, so nothing looked newly created THAT run, so
 * $structuralChange stayed false, so bump() was skipped again — permanently.
 * There was no self-healing path and no operator-visible signal.
 *
 * A transaction closes this cleanly rather than requiring a second mechanism
 * (e.g. deriving "did the catalog change" from a before/after content hash or
 * row-count diff instead of the flag): once the entire method is one atomic
 * unit, "rows exist but bump() did not run" becomes unreachable by
 * construction — either the run (all writes + bump) commits together, or a
 * throw rolls back EVERYTHING it did, including any rows a partial attempt
 * had "committed" before the throw. The in-memory flag is then trustworthy
 * again, because it is computed within the same execution that determines
 * what actually lands in the DB — there is no window where the flag says
 * false while something is nonetheless persisted. This holds for every path
 * through this method, INCLUDING the write-blocked branch (it never sets
 * $structuralChange, still inside the same transaction) and INCLUDING a
 * hypothetical failure AFTER bump() (bump() is the last statement in the same
 * transaction, so such a failure would roll the bump back together with the
 * rows that justified it — retry then recomputes cleanly from a truly empty
 * diff, not a corrupted one).
 *
 * A before/after state-diff (hash the catalog, bump if it moved, in a
 * finally-block that runs even on exception) was considered and rejected: it
 * does not fix the root cause (non-atomic writes), it is strictly more
 * expensive (a full-catalog scan/hash on every run instead of O(1)
 * bookkeeping already threaded through the mutation sites), and — worse — it
 * would bump the revision for a PARTIAL, crashed catalog state and make it
 * visible to caches/ETags as if it were a completed seed. That directly
 * contradicts this seeder's own discipline elsewhere (published-baseline
 * gate: all-or-nothing, never partial) — a half-written catalog going live
 * with a fresh revision number is a worse outcome than the seed command
 * exiting non-zero with nothing changed.
 *
 * NOT everything in this method is DB work, and none of the non-DB work
 * needs to be excluded from the transaction: Log::warning() calls write to
 * the log channel immediately and are NOT affected by a DB rollback either
 * way (a rolled-back attempt's log lines are a record of that attempt, not a
 * promise about final DB state — which is already how Log:: and DB writes
 * relate everywhere else in Laravel). file_get_contents()/json_decode() are
 * plain reads with no DB locks to hold. There is no queue dispatch, cache
 * write, or outbound HTTP call in this method, and the surrounding
 * `php artisan db:seed` command does not depend on this seeder's internal
 * statement ordering — so nothing here needs to run outside the transaction.
 *
 * @param  string|null  $rolesFile  Override path to roles.json (for testing)
 * @param  string|null  $competenciesFile  Override path to competencies.json (for testing)
 * @param  string|null  $barsDir  Override path to bars/ directory (for testing)
 */
class FrameworkCatalogSeeder extends Seeder
{
    use ReadsCatalogueLocaleMaps;

    private string $rolesFile;

    private string $competenciesFile;

    private string $barsDir;

    public function __construct(
        ?string $rolesFile = null,
        ?string $competenciesFile = null,
        ?string $barsDir = null,
    ) {
        // The catalog ships INSIDE this repository, at database/framework.
        //
        // It used to be read from the wrapper — dirname(base_path())/docs/… —
        // which resolves only in a developer's checkout. Inside the Docker
        // image WORKDIR is /var/www so it became /var/docs, a path nothing
        // mounts; and on Railway this submodule deploys alone, with no wrapper
        // above it at all. `php artisan db:seed` was therefore impossible in
        // every container, which meant a production database with no
        // competencies, no roles and no BARS anchors — one no project can be
        // created against.
        //
        // The wrapper's docs/app_description/02-domain/framework remains the
        // AUTHORED source: a human edits it, and CLAUDE.md marks it binding.
        // This is a vendored copy so the API can carry its own seed data, and
        // the wrapper's Cross-Stack Consistency job fails if the two diverge —
        // the same treatment openapi.json already gets, for the same reason.
        $frameworkBase = config('framework_catalog.catalog_path') ?: database_path('framework');
        $this->rolesFile = $rolesFile ?? "{$frameworkBase}/roles.json";
        $this->competenciesFile = $competenciesFile ?? "{$frameworkBase}/competencies.json";
        $this->barsDir = $barsDir ?? "{$frameworkBase}/bars";
    }

    public function run(): void
    {
        // The ENTIRE method body — every write plus the final bump() — runs
        // as one atomic unit. See the class docblock ("Atomicity") for why
        // this is what makes $structuralChange trustworthy again after a
        // mid-run crash. DB::transaction() composes correctly whether or not
        // this seeder is itself invoked from inside another transaction
        // (Laravel uses a SAVEPOINT for the nested case), so no special
        // handling is needed for `$this->call(FrameworkCatalogSeeder::class)`
        // from DatabaseSeeder.
        DB::transaction(function (): void {
            $this->syncCatalog();
        });
    }

    private function syncCatalog(): void
    {
        $normalizer = new CompetencyNormalizer;
        $structuralChange = false;

        // Global IT translation-gap counters (step 5, design D5), accumulated
        // WHILE walking the catalogue below — NOT queried afterwards from
        // framework_gaps rows. See resolveOrRecordTranslationGap()'s own
        // docblock for why a gap-row-derived denominator undercounts.
        $translationPairsTotal = 0;
        $translationPairsPending = 0;

        // ─── Baseline-revision write gate (framework-catalogue-authoring PR2, D2) ──
        $baseline = $this->resolveBaselineRevision();
        $baselineId = $baseline->id;
        $writesBlocked = $this->writesAreBlocked($baseline);

        if ($writesBlocked) {
            // Emitted ONCE per run, before any catalog processing begins —
            // exempt from the gate itself (bookkeeping, not catalogue
            // content), mirroring the old lock-guard signal's kind/shape so
            // an operator's existing runbook check still finds it.
            FrameworkGap::updateOrCreate(
                ['kind' => 'seeder_lock_guard_active', 'role_code' => null, 'competency_code' => null],
                ['note' => "FrameworkCatalogSeeder is running against a PUBLISHED baseline revision (id={$baselineId}) that already carries content. No catalog writes will be performed.", 'status' => 'info'],
            );
            Log::warning('FrameworkCatalogSeeder: baseline revision is published and already carries content. No catalog writes performed.', [
                'baseline_revision_id' => $baselineId,
            ]);
        }

        // ─── 1. Load JSON data ────────────────────────────────────────────────
        // Checked explicitly, because file_get_contents on a missing path emits
        // a warning and returns false, which json_decode then reports as a
        // syntax error — sending whoever hits it looking for a malformed JSON
        // file that is not malformed and, in the container case, not there.
        foreach ([$this->rolesFile, $this->competenciesFile] as $required) {
            if (! is_readable($required)) {
                throw new RuntimeException(
                    "Framework catalog not found at [{$required}]. The catalog lives in the WRAPPER repository "
                    .'(docs/app_description/02-domain/framework), which is not present inside the api container. '
                    .'Either run this seeder from a wrapper checkout, or set FRAMEWORK_CATALOG_PATH to a readable directory.'
                );
            }
        }

        /** @var array<string, array{name: array<string,string>, responsibilities: array<string,string>, competencies: list<string>}> $rolesJson */
        $rolesJson = json_decode(file_get_contents($this->rolesFile), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, array{name: array<string,string>, definition: array<string,string>}> $competenciesJson */
        $competenciesJson = json_decode(file_get_contents($this->competenciesFile), true, 512, JSON_THROW_ON_ERROR);

        // ─── 2. Seed competencies ─────────────────────────────────────────────
        /** @var array<string, int> $competencyIdsByCode */
        $competencyIdsByCode = [];

        foreach ($competenciesJson as $code => $data) {
            $nameLocales = $this->readLocaleMap($data['name'] ?? null, "competencies.json:{$code}.name", 'FrameworkCatalogSeeder');
            $definitionLocales = $this->readLocaleMap($data['definition'] ?? null, "competencies.json:{$code}.definition", 'FrameworkCatalogSeeder');

            // Scoped to the baseline — natural-key lookup by code ALONE would
            // bind to whichever revision's row Postgres happens to return
            // first once a second revision (with its own cloned "code") can
            // exist (framework-catalogue-authoring PR2 landmine, flagged by
            // PR1's review gate). See SeederRevisionScopedLookupTest.
            $competency = Competency::where('revision_id', $baselineId)->where('code', $code)->first();

            if ($writesBlocked) {
                if ($competency !== null) {
                    $competencyIdsByCode[$code] = $competency->id;
                }
                // A competency not yet in the baseline is never created while
                // writes are blocked — no additive insert (D2).

                continue;
            }

            $competency ??= new Competency(['revision_id' => $baselineId, 'code' => $code]);
            $competency->type = in_array($code, CatalogueRules::POTENTIAL_CODES, true)
                ? 'potential'
                : 'standard';
            $this->setAllLocales($competency, 'name', $nameLocales);
            $this->setAllLocales($competency, 'definition', $definitionLocales);
            $competency->save();
            $competencyIdsByCode[$code] = $competency->id;

            // Track structural change for a genuinely new row OR an actual
            // mutation of an existing one (Eloquent's own answer to "did
            // this row actually change"). A true no-op re-seed leaves
            // wasChanged() false, so idempotency survives.
            if ($competency->wasRecentlyCreated || $competency->wasChanged()) {
                $structuralChange = true;
            }
        }

        // ─── 3. Seed roles + pivot + BARS ────────────────────────────────────
        foreach ($rolesJson as $roleCode => $roleData) {
            $roleNameLocales = $this->readLocaleMap($roleData['name'] ?? null, "roles.json:{$roleCode}.name", 'FrameworkCatalogSeeder');
            $roleResponsibilitiesLocales = $this->readLocaleMap($roleData['responsibilities'] ?? null, "roles.json:{$roleCode}.responsibilities", 'FrameworkCatalogSeeder', allowBlankEn: true);

            // Same landmine fix as competencies, above: scoped to the baseline.
            $role = Role::where('revision_id', $baselineId)->where('code', $roleCode)->first();

            if ($writesBlocked && $role === null) {
                // A role not yet in the baseline has no pivot, BARS, or gaps
                // to reconcile against either — nothing more to do for it.
                continue;
            }

            if (! $writesBlocked) {
                $role ??= new Role(['revision_id' => $baselineId, 'code' => $roleCode]);
                $this->setAllLocales($role, 'name', $roleNameLocales);
                $this->setAllLocales($role, 'responsibilities', $roleResponsibilitiesLocales);
                $role->save();

                if ($role->wasRecentlyCreated || $role->wasChanged()) {
                    $structuralChange = true;
                }
            }

            // From here, $role is guaranteed non-null: either found above, or
            // just created in the branch immediately preceding.

            // Flag empty responsibilities (always — recording a still-pending
            // gap is safe regardless of the write gate: it describes what the
            // JSON currently declares, not a claim about the database).
            if (($roleResponsibilitiesLocales['en'] ?? '') === '') {
                FrameworkGap::updateOrCreate(
                    ['kind' => 'missing_role_meta', 'role_code' => $roleCode, 'competency_code' => null],
                    ['note' => "Role {$roleCode} responsibilities is empty string — pending authoring", 'status' => 'pending_authoring'],
                );
            } elseif (! $writesBlocked) {
                // Gap RESOLUTION, by contrast, is a claim that the database now
                // satisfies the rule — see "Gap resolution reflects DATABASE
                // state, not the JSON" in the class docblock. Only reachable
                // while writes are not blocked, i.e. the write just made
                // (or a prior unblocked run) actually landed this text.
                FrameworkGap::where('kind', 'missing_role_meta')
                    ->where('role_code', $roleCode)
                    ->where('status', 'pending_authoring')
                    ->update(['status' => 'resolved']);
            }

            // 3b. Compute the JSON-derived assigned competency ids (read-only —
            // used below to scope the BARS walk regardless of the gate).
            $assignedCodes = $roleData['competencies'];
            $assignedIds = [];
            foreach ($assignedCodes as $position => $competencyCode) {
                if (isset($competencyIdsByCode[$competencyCode])) {
                    $assignedIds[$competencyIdsByCode[$competencyCode]] = ['position' => $position];
                }
            }

            if (! $writesBlocked) {
                // Capture current pivot ids before sync() to detect removals.
                $previousPivotIds = DB::table('framework_role_competency')
                    ->where('role_id', $role->id)
                    ->where('revision_id', $baselineId)
                    ->pluck('competency_id')
                    ->toArray();

                $role->competencies()->sync($assignedIds);

                // Delete bars_indicators for any competencies removed from this role.
                $newPivotIds = array_keys($assignedIds);
                $removedIds = array_diff($previousPivotIds, $newPivotIds);
                if (! empty($removedIds)) {
                    BarsIndicator::where('revision_id', $baselineId)
                        ->where('role_id', $role->id)
                        ->whereIn('competency_id', $removedIds)
                        ->delete();
                }
            }
            // While writes are blocked: no pivot writes at all — not even the
            // additive syncWithoutDetaching() the old lock-guard used. $assignedIds
            // above is still computed, purely to scope the BARS/gap walk below.

            // 3c. Seed BARS indicators for this role
            $barsFile = "{$this->barsDir}/{$roleCode}.json";

            if (! file_exists($barsFile)) {
                Log::warning("FrameworkCatalogSeeder: BARS file missing for role {$roleCode}", [
                    'role' => $roleCode,
                    'expected_path' => $barsFile,
                ]);
                FrameworkGap::updateOrCreate(
                    ['kind' => 'role_no_bars', 'role_code' => $roleCode, 'competency_code' => null],
                    ['note' => "No bars file found for role {$roleCode}", 'status' => 'pending_authoring'],
                );

                continue;
            }

            // Gap resolution: bars file now exists — resolve any pending
            // role_no_bars gap. Gated by the write gate: the file existing in
            // the JSON tree is not evidence the rows were actually written to
            // the database this run (see the class docblock).
            if (! $writesBlocked) {
                FrameworkGap::where('kind', 'role_no_bars')
                    ->where('role_code', $roleCode)
                    ->where('status', 'pending_authoring')
                    ->update(['status' => 'resolved']);
            }

            /** @var array<string, list<array{indicator: array<string,string>, scale: array{5: array<string,string>, 3: array<string,string>, 1: array<string,string>}}>> $barsJson */
            $barsJson = json_decode(file_get_contents($barsFile), true, 512, JSON_THROW_ON_ERROR);

            $coveredCompetencyCodes = array_keys($barsJson);

            // The current assigned competency IDs (from the CURRENT JSON, NOT
            // DB pivot state).
            $currentAssignedIds = array_keys($assignedIds);

            foreach ($barsJson as $competencyCode => $indicatorArray) {
                if (! isset($competencyIdsByCode[$competencyCode])) {
                    continue;
                }

                $competencyId = $competencyIdsByCode[$competencyCode];

                // Only seed bars for competencies currently in the JSON-derived assigned set
                if (! in_array($competencyId, $currentAssignedIds, true)) {
                    if (! $writesBlocked) {
                        // Competency is in the bars file but absent from the
                        // current JSON assignment list — delete stale indicators.
                        BarsIndicator::where('revision_id', $baselineId)
                            ->where('role_id', $role->id)
                            ->where('competency_id', $competencyId)
                            ->delete();
                    }

                    continue;
                }

                // 'name'/'definition' here are throwaway placeholders — this call site only
                // ever reads $dto->indicators below; competency name/definition are seeded
                // separately, directly from competenciesJson (step 2, above). They must still
                // be valid locale maps (CompetencyNormalizer::normalize() fails closed on a
                // bare string per design D1), so a harmless non-empty 'en' placeholder is used.
                $dto = $normalizer->normalize(
                    ['code' => $competencyCode, 'name' => ['en' => $competencyCode], 'definition' => ['en' => $competencyCode], 'type' => 'standard'],
                    $indicatorArray,
                );

                $presentPositions = [];

                foreach ($dto->indicators as $indicatorDto) {
                    $presentPositions[] = $indicatorDto->position;

                    if ($writesBlocked) {
                        // No content write — not even for a brand-new position
                        // on an already-anchored pair.
                        continue;
                    }

                    // Upsert by (revision, role, competency, position). Every
                    // locale present in the source map is written — not `en`
                    // only (design D4).
                    $indicator = BarsIndicator::where('revision_id', $baselineId)
                        ->where('role_id', $role->id)
                        ->where('competency_id', $competencyId)
                        ->where('position', $indicatorDto->position)
                        ->first()
                        ?? new BarsIndicator([
                            'revision_id' => $baselineId,
                            'role_id' => $role->id,
                            'competency_id' => $competencyId,
                            'position' => $indicatorDto->position,
                        ]);
                    $this->setAllLocales($indicator, 'text', $indicatorDto->text);
                    $this->setAllLocales($indicator, 'anchor_5', $indicatorDto->anchor5);
                    $this->setAllLocales($indicator, 'anchor_3', $indicatorDto->anchor3);
                    $this->setAllLocales($indicator, 'anchor_1', $indicatorDto->anchor1);
                    $indicator->save();

                    if ($indicator->wasRecentlyCreated || $indicator->wasChanged()) {
                        $structuralChange = true;
                    }
                }

                if (! $writesBlocked) {
                    // Delete stale indicators (positions no longer in JSON).
                    BarsIndicator::where('revision_id', $baselineId)
                        ->where('role_id', $role->id)
                        ->where('competency_id', $competencyId)
                        ->whereNotIn('position', $presentPositions)
                        ->delete();
                }

                // Per-pair `missing_translation` gap resolution (design D5), evaluated at PAIR
                // granularity from the SOURCE JSON (via $dto, already validated) — ALL 12
                // strings (3 indicators × {text, anchor_5, anchor_3, anchor_1}) must carry a
                // non-empty `it` value before this pair counts as translated. 11 of 12 is
                // treated as 0 (mirrors the scoring-engine per-competency hard-fail unit).
                // The determination itself is computed from JSON, not DB state, so it always
                // runs — `framework_gaps` is exempt from the gate (D2) — but
                // `resolveOrRecordTranslationGap()` does NOT unconditionally act on it: recording
                // a still-pending gap proceeds unconditionally (it describes the JSON), while
                // marking one RESOLVED is gated behind `$writesBlocked` (it is a claim the
                // DATABASE now satisfies the rule — see this class's own docblock, "Gap
                // resolution reflects DATABASE state, not the JSON", and that method's own
                // docblock for the full split).
                //
                // This is ALSO where the global-row denominator (step 5) is accumulated:
                // every pair that reaches this line is a currently-assigned, anchored pair
                // (it has a BARS entry AND is in the JSON-derived assigned set — see the two
                // `continue`s above), so it is unconditionally counted in the total, whether
                // or not it is itself translated. That is the fix for the production defect:
                // the total must include pairs that were NEVER missing anything, not just
                // pairs that at some point had a gap row recorded for them.
                $translationPairsTotal++;
                if (! $this->resolveOrRecordTranslationGap($roleCode, $competencyCode, $dto->indicators, $writesBlocked)) {
                    $translationPairsPending++;
                }
            }

            // 3d. Record competency_no_bars gaps for assigned competencies absent from BARS file;
            // resolve any pending gap for a pair that is now covered.
            foreach ($assignedCodes as $competencyCode) {
                if (! in_array($competencyCode, $coveredCompetencyCodes, true)) {
                    FrameworkGap::updateOrCreate(
                        ['kind' => 'competency_no_bars', 'role_code' => $roleCode, 'competency_code' => $competencyCode],
                        ['note' => "Competency {$competencyCode} assigned to {$roleCode} but absent from BARS file", 'status' => 'pending_authoring'],
                    );
                } elseif (! $writesBlocked) {
                    FrameworkGap::where('kind', 'competency_no_bars')
                        ->where('role_code', $roleCode)
                        ->where('competency_code', $competencyCode)
                        ->where('status', 'pending_authoring')
                        ->update(['status' => 'resolved']);
                }
            }

            if (! $writesBlocked) {
                // Gap resolution, orphan case: a competency_no_bars gap whose pair
                // roles.json no longer assigns to this role at all is moot — resolve it too.
                // Mirrors CI Direction 2 of catalog_stale_competency_gap_exemptions.
                // Gated: "moot" is a claim about the pivot the database actually
                // holds, which a blocked run never wrote.
                FrameworkGap::where('kind', 'competency_no_bars')
                    ->where('role_code', $roleCode)
                    ->where('status', 'pending_authoring')
                    ->whereNotIn('competency_code', $assignedCodes)
                    ->update(['status' => 'resolved']);

                // Orphan sweep (design D5) for missing_translation, same shape as
                // competency_no_bars above: a per-pair gap whose pair is no longer assigned to
                // this role at all is moot.
                FrameworkGap::where('kind', 'missing_translation')
                    ->where('role_code', $roleCode)
                    ->where('status', 'pending_authoring')
                    ->whereNotIn('competency_code', $assignedCodes)
                    ->update(['status' => 'resolved']);
            }
        }

        // ─── 4. Potential competencies: seed their BARS, then reconcile the gap ──
        //
        // This block used to record both codes as `pending_authoring`
        // UNCONDITIONALLY, on every run, without ever asking whether the
        // competency it was reporting missing had since been authored. The gap
        // was therefore permanent by construction, and `potential` projects
        // stayed unusable even after the catalogue gained the definitions.
        $this->seedPotentialIndicators($competencyIdsByCode, $baselineId, $writesBlocked);

        foreach (CatalogueRules::POTENTIAL_CODES as $potentialCode) {
            $authored = isset($competencyIdsByCode[$potentialCode])
                && BarsIndicator::where('revision_id', $baselineId)
                    ->whereNull('role_id')
                    ->where('competency_id', $competencyIdsByCode[$potentialCode])
                    ->exists();

            if ($authored) {
                // Resolve rather than delete, for the installations that
                // already carry the row: it is the record that this was once
                // missing. On a fresh catalogue no row is ever written, which
                // is the correct state and not an omission.
                FrameworkGap::where('kind', 'missing_potential_competency')
                    ->where('competency_code', $potentialCode)
                    ->where('status', 'pending_authoring')
                    ->update(['status' => 'resolved']);

                continue;
            }

            FrameworkGap::updateOrCreate(
                ['kind' => 'missing_potential_competency', 'role_code' => null, 'competency_code' => $potentialCode],
                ['note' => "{$potentialCode} potential competency definition absent — pending expert authoring", 'status' => 'pending_authoring'],
            );
        }

        // ─── 5. Record global IT translation gap (design D5) ──────────────────
        // Denominator fix (post-production incident): $translationPairsTotal /
        // $translationPairsPending are accumulated WHILE walking the catalogue
        // above (see the call site of resolveOrRecordTranslationGap()), NOT
        // derived from framework_gaps rows here. A gap ROW is only ever
        // written for a pair that is NOT fully translated
        // (resolveOrRecordTranslationGap never inserts a row for a complete
        // pair — it only resolves-or-no-ops one). Querying rows for the total
        // therefore silently excludes every pair that was translated from the
        // very first run: seeding a catalogue that is 100% `it`-translated
        // from scratch created zero rows, so total=0, pending=0, and this
        // global row stayed `pending_authoring` forever — production's actual
        // failure ("0 of 0 ... translated" beside `pending_authoring`).
        //
        // What is counted, and why:
        //   - Counted: every currently-assigned, anchored role×competency pair
        //     — i.e. every pair that reaches resolveOrRecordTranslationGap()'s
        //     call site. That requires (a) the role has a BARS file, (b) the
        //     competency appears in that file, and (c) the competency is in
        //     the CURRENT JSON-derived assignment list — see the two
        //     `continue`s in the bars-indicator loop above. The COUNT is
        //     unaffected by the write gate: `resolveOrRecordTranslationGap()`
        //     is CALLED unconditionally (not inside `if (! $writesBlocked)`),
        //     so a blocked run never drops a pair from this count either —
        //     both counters are always computed from the source JSON. Its
        //     OWN internal writes are NOT uniformly unconditional, though
        //     (corrected post-PR2-review, see the method's own docblock):
        //     recording a still-pending gap describes the JSON regardless of
        //     `$writesBlocked`, but RESOLVING one (`pending_authoring` ->
        //     `resolved`) is a claim the DATABASE now satisfies the rule,
        //     which a blocked run has not made true — that half is gated.
        //   - Excluded: a role with no BARS file at all (tracked separately as
        //     `role_no_bars`) and an assigned competency absent from a role's
        //     BARS file (tracked separately as `competency_no_bars`). Neither
        //     has any indicator text to translate in the first place, so
        //     "translated" is not a meaningful predicate for them — counting
        //     them here would conflate "nothing to translate" with "pending
        //     translation" and reintroduce a silently-wrong denominator in a
        //     different shape.
        //
        // Status and note are both derived from the SAME boolean below, so
        // "all N of N translated" beside `pending_authoring` is not
        // expressible — the exact self-contradiction production exposed.
        $translationGapResolved = $translationPairsPending === 0;

        $translationGapNote = match (true) {
            $translationGapResolved && $translationPairsTotal === 0 => 'it locale: no role×competency pairs are anchored — nothing to translate',
            $translationGapResolved => "it locale: all {$translationPairsTotal} of {$translationPairsTotal} role×competency pairs translated",
            default => "it locale: {$translationPairsPending} of {$translationPairsTotal} role×competency pairs pending",
        };

        // Gated as a whole, not just the resolve direction: this one
        // `updateOrCreate` can flip the row EITHER way from JSON-derived
        // counters that are meaningless while blocked (no pair's indicators
        // were actually written this run), so leaving the row exactly as a
        // prior unblocked run left it is the only claim that stays true.
        if (! $writesBlocked) {
            FrameworkGap::updateOrCreate(
                ['kind' => 'missing_translation', 'role_code' => null, 'competency_code' => null],
                [
                    'note' => $translationGapNote,
                    'status' => $translationGapResolved ? 'resolved' : 'pending_authoring',
                ],
            );
        }

        // ─── 6. Bump catalog_meta revision if structural changes occurred ─────
        // CatalogMeta::bump() needs no gate-specific handling: it fires for a
        // genuinely new row OR an actual mutation of an existing one, and
        // nothing sets $structuralChange while writes are blocked.
        if ($structuralChange) {
            CatalogMeta::bump();
        }
    }

    /**
     * Resolve the ONE `framework_catalog_revisions` row this seeder owns
     * (`is_baseline = true`). Throws rather than silently seeding nowhere if
     * migrations have not run yet — the same fail-closed posture as the
     * missing-JSON-file check above.
     */
    private function resolveBaselineRevision(): FrameworkCatalogRevision
    {
        $baseline = FrameworkCatalogRevision::where('is_baseline', true)->first();

        if ($baseline === null) {
            throw new RuntimeException(
                'FrameworkCatalogSeeder: no baseline FrameworkCatalogRevision exists. Run migrations first — '
                .'the framework-catalogue-authoring PR1 backfill migration creates the baseline row this seeder owns.'
            );
        }

        return $baseline;
    }

    /**
     * Whether this run must perform zero catalogue-content writes
     * (framework-catalogue-authoring PR2, D2). See the class docblock's
     * "Baseline-revision write gate" section for the full reasoning — in
     * short: `published` alone is not the gate, because the baseline is
     * ALWAYS published by construction and a bare state check would refuse
     * the very first seed on a fresh install. A published baseline that has
     * not yet been populated is not yet the "already-shipped content" D2
     * protects; a published baseline that already carries content is.
     */
    private function writesAreBlocked(FrameworkCatalogRevision $baseline): bool
    {
        return $baseline->state === 'published' && $this->baselineHasContent($baseline->id);
    }

    /**
     * Competencies are this seeder's own first catalogue write (step 2,
     * below) on every mutating run, so their presence is sufficient to
     * answer "has this baseline already been populated" without querying
     * all four catalogue-content tables.
     */
    private function baselineHasContent(int $baselineId): bool
    {
        return Competency::where('revision_id', $baselineId)->exists();
    }

    /**
     * Per-pair `missing_translation` gap resolution (design D5), evaluated at
     * role×competency PAIR granularity: ALL 12 strings across the pair's 3
     * indicators must carry a non-empty `it` value before the pair counts as
     * translated. `$itComplete` is computed from the (already-normalized,
     * already-validated) DTO — i.e. from the SOURCE JSON, never DB state —
     * identically whether or not catalogue-content writes are blocked (D2:
     * `framework_gaps` is exempt from the gate). The two WRITES below are
     * NOT both unconditional, though (post-PR2-review correction — see the
     * class docblock, "Gap resolution reflects DATABASE state, not the
     * JSON"): recording a still-pending gap describes the JSON, so it always
     * runs; resolving one is a claim the DATABASE now satisfies the rule, so
     * it runs only when `$writesBlocked` is false.
     *
     * @param  list<IndicatorDTO>  $indicators
     * @return bool Whether this pair's `it` locale is fully translated (12 of 12 strings).
     *              The caller uses this to accumulate the global-row denominator (step 5)
     *              instead of re-deriving it from framework_gaps rows afterwards. Returned
     *              regardless of `$writesBlocked` — the boolean itself is harmless; only the
     *              RESOLUTION write below is gated.
     */
    private function resolveOrRecordTranslationGap(string $roleCode, string $competencyCode, array $indicators, bool $writesBlocked): bool
    {
        $itComplete = true;

        foreach ($indicators as $indicatorDto) {
            foreach ([$indicatorDto->text, $indicatorDto->anchor5, $indicatorDto->anchor3, $indicatorDto->anchor1] as $localeMap) {
                if (($localeMap['it'] ?? '') === '') {
                    $itComplete = false;
                    break 2;
                }
            }
        }

        if ($itComplete) {
            // Gated: the JSON being complete is not evidence the `it` values
            // were actually written to this pair's indicator rows — a blocked
            // run never wrote them. See the class docblock, "Gap resolution
            // reflects DATABASE state, not the JSON".
            if (! $writesBlocked) {
                FrameworkGap::where('kind', 'missing_translation')
                    ->where('role_code', $roleCode)
                    ->where('competency_code', $competencyCode)
                    ->where('status', 'pending_authoring')
                    ->update(['status' => 'resolved']);
            }

            return true;
        }

        FrameworkGap::updateOrCreate(
            ['kind' => 'missing_translation', 'role_code' => $roleCode, 'competency_code' => $competencyCode],
            ['note' => "{$roleCode}×{$competencyCode}: it locale not fully translated (12 strings required, 11 of 12 counts as 0)", 'status' => 'pending_authoring'],
        );

        return false;
    }

    /**
     * Seed the BARS indicators for the competencies that belong to no role.
     *
     * Separate from the per-role loop rather than folded into it, and that is
     * the honest shape: a potential competency has no role, no
     * `framework_role_competency` pivot row, and none of the per-role gap
     * semantics — orphan sweeps, `competency_no_bars`, per-role translation
     * gaps — that loop exists to maintain. Threading a null role through it
     * would mean a null check at every one of those steps, each of which could
     * only ever mean "skip".
     *
     * The file is optional. An installation without it keeps the
     * `pending_authoring` gap the caller records, which is exactly the state
     * this catalogue was in before the definitions were written.
     *
     * Content writes are gated identically to the per-role loop (D2) — this
     * previously bypassed the old lock-guard entirely (no `$locked` check
     * existed here at all), which was itself a gap the published-baseline
     * gate closes as a side effect of being unconditional.
     *
     * @param  array<string, int>  $competencyIdsByCode
     */
    private function seedPotentialIndicators(array $competencyIdsByCode, int $baselineId, bool $writesBlocked): void
    {
        if ($writesBlocked) {
            return;
        }

        $file = "{$this->barsDir}/POTENTIAL.json";

        if (! is_file($file)) {
            return;
        }

        /** @var array<string, list<array<string, mixed>>> $json */
        $json = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        foreach ($json as $code => $indicators) {
            if (! isset($competencyIdsByCode[$code])) {
                // Named in the BARS file but absent from competencies.json.
                // Skipped rather than invented: a competency conjured from an
                // anchor file would have no definition to score against.
                continue;
            }

            $competencyId = $competencyIdsByCode[$code];

            foreach (array_values($indicators) as $position => $raw) {
                $indicator = BarsIndicator::where('revision_id', $baselineId)
                    ->where('role_id', null)
                    ->where('competency_id', $competencyId)
                    ->where('position', $position)
                    ->first()
                    ?? new BarsIndicator([
                        'revision_id' => $baselineId,
                        'role_id' => null,
                        'competency_id' => $competencyId,
                        'position' => $position,
                    ]);

                $indicator->role_id = null;
                $this->setAllLocales($indicator, 'text', $this->readLocaleMap(
                    $raw['indicator'] ?? null,
                    "bars/POTENTIAL.json:{$code}[{$position}].indicator",
                    'FrameworkCatalogSeeder',
                ));

                foreach (['5' => 'anchor_5', '3' => 'anchor_3', '1' => 'anchor_1'] as $level => $column) {
                    $this->setAllLocales($indicator, $column, $this->readLocaleMap(
                        $raw['scale'][$level] ?? null,
                        "bars/POTENTIAL.json:{$code}[{$position}].scale.{$level}",
                        'FrameworkCatalogSeeder',
                    ));
                }

                $indicator->save();
            }
        }
    }
}
