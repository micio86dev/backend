<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BarsIndicator;
use App\Models\CatalogMeta;
use App\Models\Competency;
use App\Models\FrameworkGap;
use App\Models\FrameworkVersion;
use App\Models\Role;
use App\Services\FrameworkCatalog\CompetencyNormalizer;
use App\Services\FrameworkCatalog\DTO\IndicatorDTO;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * FrameworkCatalogSeeder (C3 + C4 lock-guard + framework-catalog-it-translations locale writing).
 *
 * Seeds the global BEAI framework catalog from split-file JSON:
 *   docs/app_description/02-domain/framework/roles.json
 *   docs/app_description/02-domain/framework/competencies.json
 *   docs/app_description/02-domain/framework/bars/{ROLE}.json
 *
 * Locale dimension (framework-catalog-it-translations, design D1/D4): every
 * translatable field's JSON value is a locale map — `{"en": "...", "it":
 * "..."}`, `en` mandatory, `it` optional until authored. The seeder writes
 * `setTranslation($field, $locale, $value)` for EVERY locale present in the
 * source map, not `en` only — so an authored `it` value is picked up by the
 * SAME write path as `en`, with no separate "IT-writing mode".
 *
 * Idempotent + delete-stale (sync):
 *   - updateOrCreate() for roles and competencies (by code)
 *   - sync() for role_competency pivot (removes stale assignments) [when NOT locked]
 *   - syncWithoutDetaching() for role_competency pivot [when locked]
 *   - upsert + delete-stale for bars_indicators per (role, competency) [when NOT locked]
 *   - updateOrCreate() for all framework_gaps (NULLS NOT DISTINCT at DB layer)
 *
 * C4 lock-guard: when any FrameworkVersion has is_locked=true, the seeder becomes
 * PURELY ADDITIVE:
 *   - No existing catalog rows are mutated (setTranslation/save skipped for existing rows)
 *   - No rows are deleted (sync → syncWithoutDetaching; BarsIndicator::delete() suppressed)
 *   - Only genuinely new rows (not yet in DB by natural key) are inserted
 *   - framework_gaps upserts and CatalogMeta::bump() are EXEMPT (operational tables)
 *   - seeder_lock_guard_active signal emitted ONCE (as FrameworkGap + Log::warning)
 *
 * **Fill-empty-locale exception under lock** (framework-catalog-it-translations
 * design D4, the PRIMARY path — not an edge case: `ProjectController::store`
 * locks a FrameworkVersion the moment any tenant creates a project, so
 * production has zero locked FVs only until the first real signup). Extends
 * the existing `Role.responsibilities` EN-fill-when-empty precedent (fix 5b,
 * unchanged, see below) with a SEPARATE, more general exception: for ANY
 * translatable field on an EXISTING row, under lock, a NON-`en` locale MAY be
 * written iff the row does not already carry a translation for that locale.
 * `en` is NEVER touched by this exception, and a non-empty existing non-`en`
 * value is NEVER overwritten. See `fillEmptyLocalesUnderLock()`.
 *
 * **Per-pair `missing_translation` gap resolution** (design D5): computed
 * from the SOURCE JSON, not DB state (proceeds under lock, like every other
 * gap-resolution point here) — a role×competency pair's 12 strings (3
 * indicators × {text, anchor_5, anchor_3, anchor_1}) must ALL carry an `it`
 * value before that pair's gap resolves. 11 of 12 counts as 0 (mirrors the
 * scoring-engine's own per-competency hard-fail unit).
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
 * through this method, INCLUDING the locked-FrameworkVersion additive-only
 * branch (it still sets $structuralChange for genuinely new rows, still
 * inside the same transaction) and INCLUDING a hypothetical failure AFTER
 * bump() (bump() is the last statement in the same transaction, so such a
 * failure would roll the bump back together with the rows that justified it
 * — retry then recomputes cleanly from a truly empty diff, not a corrupted
 * one).
 *
 * A before/after state-diff (hash the catalog, bump if it moved, in a
 * finally-block that runs even on exception) was considered and rejected: it
 * does not fix the root cause (non-atomic writes), it is strictly more
 * expensive (a full-catalog scan/hash on every run instead of O(1)
 * bookkeeping already threaded through the mutation sites), and — worse — it
 * would bump the revision for a PARTIAL, crashed catalog state and make it
 * visible to caches/ETags as if it were a completed seed. That directly
 * contradicts this seeder's own discipline elsewhere (C4 lock-guard: purely
 * additive, never partial) — a half-written catalog going live with a fresh
 * revision number is a worse outcome than the seed command exiting non-zero
 * with nothing changed.
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
        $frameworkBase = env('FRAMEWORK_CATALOG_PATH') ?: database_path('framework');
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

        // ─── C4 Lock-Guard ────────────────────────────────────────────────────
        // Must use withoutGlobalScopes() — no HTTP request / tenant context during artisan seeding.
        // This is a cross-tenant aggregate check ("does ANY locked FV exist?") — intentionally unscoped.
        $locked = $this->hasLockedVersions();

        if ($locked) {
            // Emit the lock-guard signal ONCE, before any catalog processing begins.
            // This is EXEMPT from mutation-suppression (it is an operational signal, not catalog content).
            FrameworkGap::updateOrCreate(
                ['kind' => 'seeder_lock_guard_active', 'role_code' => null, 'competency_code' => null],
                ['note' => 'FrameworkCatalogSeeder is running in ADDITIVE mode (a locked FrameworkVersion exists). No catalog mutations or deletes will be performed.', 'status' => 'info'],
            );
            Log::warning('FrameworkCatalogSeeder: running in ADDITIVE mode (locked FrameworkVersion detected). No catalog mutations or deletes performed.', [
                'locked_fv_count' => FrameworkVersion::withoutGlobalScopes()->where('is_locked', true)->count(),
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
            $competency = Competency::firstOrNew(['code' => $code]);
            $nameLocales = $this->readLocaleMap($data['name'] ?? null, "competencies.json:{$code}.name");
            $definitionLocales = $this->readLocaleMap($data['definition'] ?? null, "competencies.json:{$code}.definition");

            if ($locked && $competency->exists) {
                // Pre-existing row in locked mode: capture id. `en` is NEVER touched.
                $competencyIdsByCode[$code] = $competency->id;

                // Fill-empty-locale exception (design D4) — non-en locales only.
                $changed = $this->fillEmptyLocalesUnderLock($competency, 'name', $nameLocales);
                $changed = $this->fillEmptyLocalesUnderLock($competency, 'definition', $definitionLocales) || $changed;

                if ($changed) {
                    $competency->save();
                    $this->recordLockedFillEmptyLocaleGap(null, $code, "Competency {$code}");

                    if ($competency->wasChanged()) {
                        $structuralChange = true;
                    }
                }
            } else {
                // New row (or unlocked mode): insert / upsert is allowed.
                $competency->type = 'standard';
                $this->setAllLocales($competency, 'name', $nameLocales);
                $this->setAllLocales($competency, 'definition', $definitionLocales);
                $competency->save();
                $competencyIdsByCode[$code] = $competency->id;

                if ($locked && ! $competency->wasRecentlyCreated) {
                    // This branch is reachable, not merely theoretical: the
                    // `$competency->exists` check above and this save() are
                    // two separate statements, and nothing between them holds
                    // a DB lock (no lockForUpdate, no unique-constraint
                    // upsert, no advisory lock). A CONCURRENT seeder run that
                    // inserts this same competency row in that window makes
                    // `firstOrNew` above see exists=false (this branch's own
                    // guard), yet `wasRecentlyCreated` come back false here
                    // too — the other process's insert won the race, and this
                    // one silently re-saved a row it did not create. Left
                    // unguarded on purpose for this fix: the seeder is not run
                    // concurrently in any deploy path today, and this is a
                    // no-op either way, but the invariant this comment used to
                    // assert ("we only reach here for new rows") does not
                    // actually hold — do not rely on it.
                }

                // Track structural change for a genuinely new row OR an
                // actual mutation of an existing one (Eloquent's own answer
                // to "did this row actually change" — see design D4 /
                // framework-catalog-it-translations Phase 3). A true no-op
                // re-seed leaves wasChanged() false, so idempotency survives.
                if ($competency->wasRecentlyCreated || $competency->wasChanged()) {
                    $structuralChange = true;
                }
            }
        }

        // ─── 3. Seed roles + pivot + BARS ────────────────────────────────────
        foreach ($rolesJson as $roleCode => $roleData) {
            // 3a. Upsert role — use firstOrNew to set translations before initial INSERT
            $role = Role::firstOrNew(['code' => $roleCode]);
            $roleNameLocales = $this->readLocaleMap($roleData['name'] ?? null, "roles.json:{$roleCode}.name");
            $roleResponsibilitiesLocales = $this->readLocaleMap($roleData['responsibilities'] ?? null, "roles.json:{$roleCode}.responsibilities", allowBlankEn: true);

            if ($locked && $role->exists) {
                // Pre-existing role row in locked mode: `name` is NEVER touched under lock.
                //
                // Fill-empty-only exception (fix 5b, UNCHANGED precedent): `responsibilities`
                // is display-only (its one consumer is RoleResource — it feeds no scoring, no
                // prompt), so filling an EMPTY stored EN value from the JSON cannot move a
                // score in a locked version, which is what the lock exists to protect. A
                // non-empty stored value is NEVER overwritten, even under this exception. This
                // is EN-specific and pre-dates the locale dimension — kept exactly as-is.
                $storedResponsibilitiesEn = $role->getTranslation('responsibilities', 'en');
                $jsonResponsibilitiesEn = $roleResponsibilitiesLocales['en'] ?? '';

                if (($storedResponsibilitiesEn === null || $storedResponsibilitiesEn === '') && $jsonResponsibilitiesEn !== '') {
                    $role->setTranslation('responsibilities', 'en', $jsonResponsibilitiesEn);
                    $role->save();

                    FrameworkGap::updateOrCreate(
                        ['kind' => 'locked_fill_empty_role_meta', 'role_code' => $roleCode, 'competency_code' => null],
                        ['note' => "Role {$roleCode} responsibilities filled under a locked FrameworkVersion (fill-empty-only exception)", 'status' => 'info'],
                    );
                    Log::warning("FrameworkCatalogSeeder: filled empty responsibilities for role {$roleCode} under a locked FrameworkVersion (fill-empty-only exception).", [
                        'role' => $roleCode,
                    ]);

                    if ($role->wasChanged()) {
                        $structuralChange = true;
                    }
                }

                // NEW fill-empty-locale exception (design D4, framework-catalog-it-translations
                // Phase 4) — any NON-en locale, on `name` and `responsibilities` alike, may fill
                // an EMPTY slot. `en` is never touched by this path (see
                // fillEmptyLocalesUnderLock's own doc) and a non-empty existing non-en value is
                // never overwritten.
                $nameChanged = $this->fillEmptyLocalesUnderLock($role, 'name', $roleNameLocales);
                $responsibilitiesChanged = $this->fillEmptyLocalesUnderLock($role, 'responsibilities', $roleResponsibilitiesLocales);

                if ($nameChanged || $responsibilitiesChanged) {
                    $role->save();
                    $this->recordLockedFillEmptyLocaleGap($roleCode, null, "Role {$roleCode}");

                    if ($role->wasChanged()) {
                        $structuralChange = true;
                    }
                }
            } else {
                $this->setAllLocales($role, 'name', $roleNameLocales);
                $this->setAllLocales($role, 'responsibilities', $roleResponsibilitiesLocales);
                $role->save();

                if ($role->wasRecentlyCreated || $role->wasChanged()) {
                    $structuralChange = true;
                }
            }

            // Flag empty responsibilities (always — operational gap, not catalog mutation)
            if (($roleResponsibilitiesLocales['en'] ?? '') === '') {
                FrameworkGap::updateOrCreate(
                    ['kind' => 'missing_role_meta', 'role_code' => $roleCode, 'competency_code' => null],
                    ['note' => "Role {$roleCode} responsibilities is empty string — pending authoring", 'status' => 'pending_authoring'],
                );
            } else {
                // Gap resolution (fix 5a): responsibilities is now authored in the JSON —
                // resolve any pending gap. Computed from the JSON, not DB state, so this
                // proceeds even while a FrameworkVersion is locked.
                FrameworkGap::where('kind', 'missing_role_meta')
                    ->where('role_code', $roleCode)
                    ->where('status', 'pending_authoring')
                    ->update(['status' => 'resolved']);
            }

            // 3b. Sync pivot
            $assignedCodes = $roleData['competencies'];
            $assignedIds = [];
            foreach ($assignedCodes as $position => $competencyCode) {
                if (isset($competencyIdsByCode[$competencyCode])) {
                    $assignedIds[$competencyIdsByCode[$competencyCode]] = ['position' => $position];
                }
            }

            if ($locked) {
                // Additive mode: only attach new pivots — NEVER detach existing ones.
                // syncWithoutDetaching preserves DB-pivot rows for competencies removed from JSON.
                $role->competencies()->syncWithoutDetaching($assignedIds);
                // Stale-pivot-removal block (L126-132 in the original) is SKIPPED entirely when locked.
            } else {
                // Normal mode: before sync, capture current pivot IDs to detect removals
                $previousPivotIds = DB::table('framework_role_competency')
                    ->where('role_id', $role->id)
                    ->pluck('competency_id')
                    ->toArray();

                $role->competencies()->sync($assignedIds);

                // Delete bars_indicators for any competencies removed from this role
                $newPivotIds = array_keys($assignedIds);
                $removedIds = array_diff($previousPivotIds, $newPivotIds);
                if (! empty($removedIds)) {
                    BarsIndicator::where('role_id', $role->id)
                        ->whereIn('competency_id', $removedIds)
                        ->delete();
                }
            }

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

            // Gap resolution (fix 5a): bars file now exists — resolve any pending
            // role_no_bars gap. Computed from the filesystem, not DB state, so this
            // proceeds even while a FrameworkVersion is locked.
            FrameworkGap::where('kind', 'role_no_bars')
                ->where('role_code', $roleCode)
                ->where('status', 'pending_authoring')
                ->update(['status' => 'resolved']);

            /** @var array<string, list<array{indicator: array<string,string>, scale: array{5: array<string,string>, 3: array<string,string>, 1: array<string,string>}}>> $barsJson */
            $barsJson = json_decode(file_get_contents($barsFile), true, 512, JSON_THROW_ON_ERROR);

            $coveredCompetencyCodes = array_keys($barsJson);

            // The current assigned competency IDs (from the CURRENT JSON, NOT DB pivot state).
            // In locked mode, syncWithoutDetaching preserves DB-pivot rows for JSON-removed competencies;
            // those competencies are absent from $currentAssignedIds (JSON-derived) but their DB pivot exists.
            $currentAssignedIds = array_keys($assignedIds);

            foreach ($barsJson as $competencyCode => $indicatorArray) {
                if (! isset($competencyIdsByCode[$competencyCode])) {
                    continue;
                }

                $competencyId = $competencyIdsByCode[$competencyCode];

                // Only seed bars for competencies currently in the JSON-derived assigned set
                if (! in_array($competencyId, $currentAssignedIds, true)) {
                    // Competency is in bars file but absent from the current JSON assignment list.
                    if ($locked) {
                        // Locked mode: suppress BarsIndicator::delete() for JSON-removed-but-DB-preserved competency.
                        // Keep the continue to skip bars processing for this competency.
                        continue;
                    }

                    // Unlocked mode: delete stale indicators, then skip.
                    BarsIndicator::where('role_id', $role->id)
                        ->where('competency_id', $competencyId)
                        ->delete();

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
                    // Upsert by (role_id, competency_id, position)
                    $indicator = BarsIndicator::firstOrNew([
                        'role_id' => $role->id,
                        'competency_id' => $competencyId,
                        'position' => $indicatorDto->position,
                    ]);

                    if ($locked && $indicator->exists) {
                        // Pre-existing indicator in locked mode: `en` is NEVER touched.
                        $presentPositions[] = $indicatorDto->position;

                        // Fill-empty-locale exception (design D4) — non-en locales only. This
                        // is the PRIMARY path this exception exists for: an `it` project pinned
                        // to a locked FrameworkVersion cannot be interviewed at all today (422
                        // on the first indicator), so filling the empty `it` slot is strictly
                        // monotone — no assessment previously producible changes; it goes from
                        // "cannot be scored" to "can be scored", never the reverse.
                        $changed = $this->fillEmptyLocalesUnderLock($indicator, 'text', $indicatorDto->text);
                        $changed = $this->fillEmptyLocalesUnderLock($indicator, 'anchor_5', $indicatorDto->anchor5) || $changed;
                        $changed = $this->fillEmptyLocalesUnderLock($indicator, 'anchor_3', $indicatorDto->anchor3) || $changed;
                        $changed = $this->fillEmptyLocalesUnderLock($indicator, 'anchor_1', $indicatorDto->anchor1) || $changed;

                        if ($changed) {
                            $indicator->save();
                            $this->recordLockedFillEmptyLocaleGap(
                                $roleCode,
                                $competencyCode,
                                "BarsIndicator ({$roleCode}, {$competencyCode}, position {$indicatorDto->position})",
                            );

                            if ($indicator->wasChanged()) {
                                $structuralChange = true;
                            }
                        }
                    } else {
                        // New row (or unlocked mode): insert / upsert. Every locale present in
                        // the source map is written — not `en` only (design D4).
                        $this->setAllLocales($indicator, 'text', $indicatorDto->text);
                        $this->setAllLocales($indicator, 'anchor_5', $indicatorDto->anchor5);
                        $this->setAllLocales($indicator, 'anchor_3', $indicatorDto->anchor3);
                        $this->setAllLocales($indicator, 'anchor_1', $indicatorDto->anchor1);
                        $indicator->save();
                        $presentPositions[] = $indicatorDto->position;

                        if ($indicator->wasRecentlyCreated || $indicator->wasChanged()) {
                            $structuralChange = true;
                        }
                    }
                }

                if (! $locked) {
                    // Delete stale indicators (positions no longer in JSON) — only in unlocked mode.
                    BarsIndicator::where('role_id', $role->id)
                        ->where('competency_id', $competencyId)
                        ->whereNotIn('position', $presentPositions)
                        ->delete();
                }
                // In locked mode: delete-stale-positions block is SKIPPED entirely.

                // Per-pair `missing_translation` gap resolution (design D5), evaluated at PAIR
                // granularity from the SOURCE JSON (via $dto, already validated) — ALL 12
                // strings (3 indicators × {text, anchor_5, anchor_3, anchor_1}) must carry a
                // non-empty `it` value before this pair counts as translated. 11 of 12 is
                // treated as 0 (mirrors the scoring-engine per-competency hard-fail unit).
                // Computed from JSON, not DB state, so this proceeds even under lock.
                //
                // This is ALSO where the global-row denominator (step 5) is accumulated:
                // every pair that reaches this line is a currently-assigned, anchored pair
                // (it has a BARS entry AND is in the JSON-derived assigned set — see the two
                // `continue`s above), so it is unconditionally counted in the total, whether
                // or not it is itself translated. That is the fix for the production defect:
                // the total must include pairs that were NEVER missing anything, not just
                // pairs that at some point had a gap row recorded for them.
                $translationPairsTotal++;
                if (! $this->resolveOrRecordTranslationGap($roleCode, $competencyCode, $dto->indicators)) {
                    $translationPairsPending++;
                }
            }

            // 3d. Record competency_no_bars gaps for assigned competencies absent from BARS file;
            // resolve any pending gap for a pair that is now covered (fix 5a).
            foreach ($assignedCodes as $competencyCode) {
                if (! in_array($competencyCode, $coveredCompetencyCodes, true)) {
                    FrameworkGap::updateOrCreate(
                        ['kind' => 'competency_no_bars', 'role_code' => $roleCode, 'competency_code' => $competencyCode],
                        ['note' => "Competency {$competencyCode} assigned to {$roleCode} but absent from BARS file", 'status' => 'pending_authoring'],
                    );
                } else {
                    FrameworkGap::where('kind', 'competency_no_bars')
                        ->where('role_code', $roleCode)
                        ->where('competency_code', $competencyCode)
                        ->where('status', 'pending_authoring')
                        ->update(['status' => 'resolved']);
                }
            }

            // Gap resolution (fix 5a), orphan case: a competency_no_bars gap whose pair
            // roles.json no longer assigns to this role at all is moot — resolve it too.
            // Mirrors CI Direction 2 of catalog_stale_competency_gap_exemptions.
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

        // ─── 4. Record MTG/LAT potential competency gaps ──────────────────────
        foreach (['MTG', 'LAT'] as $potentialCode) {
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
        //     `continue`s in the bars-indicator loop above. This is unaffected
        //     by the C4 lock-guard: resolveOrRecordTranslationGap() runs
        //     unconditionally (not inside `if (! $locked)`), so a locked
        //     FrameworkVersion never drops a pair from this count either —
        //     both counters and the per-pair write are always computed from
        //     the source JSON, never from what was or wasn't allowed to be
        //     persisted this run.
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

        FrameworkGap::updateOrCreate(
            ['kind' => 'missing_translation', 'role_code' => null, 'competency_code' => null],
            [
                'note' => $translationGapNote,
                'status' => $translationGapResolved ? 'resolved' : 'pending_authoring',
            ],
        );

        // ─── 6. Bump catalog_meta revision if structural changes occurred ─────
        // CatalogMeta::bump() is EXEMPT from lock-guard suppression (operational table).
        // It fires for a genuinely new row OR an actual mutation of an existing one
        // (design D4 — see the widened predicate applied at every save() call site above).
        if ($structuralChange) {
            CatalogMeta::bump();
        }
    }

    /**
     * Check whether any locked FrameworkVersion exists across all tenants.
     *
     * MUST use withoutGlobalScopes() — the seeder runs in a CLI context with no
     * HTTP request and no TenantContext middleware, so the TenantScoped global scope
     * would resolve to null organization_id and return zero rows, silently missing
     * locked FVs. This is intentional: the check is a cross-tenant aggregate,
     * not a per-tenant data-access query.
     */
    private function hasLockedVersions(): bool
    {
        return FrameworkVersion::withoutGlobalScopes()->where('is_locked', true)->exists();
    }

    /**
     * Validate and read one translatable field's raw JSON value as a locale
     * map (framework-catalog-it-translations design D1). Fails closed
     * (throws) on any shape that is not an explicit `{"en": "...", ...}`
     * object with a mandatory `en` string and no keys outside the
     * known-locale set — mirrors `CompetencyNormalizer::normalizeLocaleMap()`,
     * duplicated here (not shared) because this reads `roles.json` /
     * `competencies.json` fields the normalizer's own contract never covered
     * (it has always been BARS-entry-scoped; see its class docblock).
     *
     * `$allowBlankEn` exists ONLY for `roles.json`'s `responsibilities`
     * field: an empty EN string is a legitimate, pre-existing sentinel for
     * "not yet authored" (see the `missing_role_meta` gap immediately below
     * this method's call sites) — every other translatable field in the
     * catalogue treats a blank `en` as malformed content
     * (`catalog_malformed_bars_entries`'s own `isBlank` rule).
     *
     * @return array<string, string>
     */
    private function readLocaleMap(mixed $value, string $context, bool $allowBlankEn = false): array
    {
        if (! is_array($value) || array_is_list($value)) {
            $got = is_array($value) ? 'a list/array' : get_debug_type($value);

            throw new RuntimeException(
                "FrameworkCatalogSeeder: {$context} must be a locale-map object (e.g. {\"en\": \"...\"}), got {$got}."
            );
        }

        if (! array_key_exists('en', $value) || ! is_string($value['en'])) {
            throw new RuntimeException(
                "FrameworkCatalogSeeder: {$context} is missing a mandatory 'en' locale value."
            );
        }

        if (! $allowBlankEn && $value['en'] === '') {
            throw new RuntimeException(
                "FrameworkCatalogSeeder: {$context} has a blank 'en' locale value."
            );
        }

        $knownLocales = $this->knownLocales();

        foreach ($value as $locale => $text) {
            if (! is_string($locale) || ! in_array($locale, $knownLocales, true)) {
                throw new RuntimeException(
                    "FrameworkCatalogSeeder: {$context} has an unknown locale key [{$locale}]. Known locales: ".implode(', ', $knownLocales).'.'
                );
            }

            if (! is_string($text)) {
                throw new RuntimeException(
                    "FrameworkCatalogSeeder: {$context} locale [{$locale}] must be a string, got ".get_debug_type($text).'.'
                );
            }
        }

        /** @var array<string, string> $value */
        return $value;
    }

    /**
     * The known-locale allowlist — sourced from `config('app.supported_locales')`,
     * the SAME single source of truth `CompetencyNormalizer::knownLocales()` reads.
     *
     * @return list<string>
     */
    private function knownLocales(): array
    {
        /** @var list<string> $configured */
        $configured = config('app.supported_locales', ['en']);

        return in_array('en', $configured, true) ? $configured : [...$configured, 'en'];
    }

    /**
     * Unlocked-mode (or new-row) write: set EVERY locale present in the
     * source map — not `en` only. This is the single code path an authored
     * `it` value and the existing `en` value both flow through.
     *
     * @param  array<string, string>  $localeMap
     */
    private function setAllLocales(Role|Competency|BarsIndicator $model, string $field, array $localeMap): void
    {
        foreach ($localeMap as $locale => $value) {
            $model->setTranslation($field, $locale, $value);
        }
    }

    /**
     * Locked-mode fill-empty-locale exception (design D4). For a PRE-EXISTING
     * row under a locked FrameworkVersion, a NON-`en` locale MAY be written
     * iff the model does not already carry a translation for it. `en` is
     * NEVER touched via this method — the caller never even passes `en`
     * through here for the byte-for-byte-preservation guarantee the lock
     * exists to protect. A pre-existing non-empty non-`en` value is NEVER
     * overwritten.
     *
     * @param  array<string, string>  $localeMap
     * @return bool Whether the model was actually modified in memory (the caller must still save()).
     */
    private function fillEmptyLocalesUnderLock(Role|Competency|BarsIndicator $model, string $field, array $localeMap): bool
    {
        $changed = false;

        foreach ($localeMap as $locale => $value) {
            if ($locale === 'en') {
                continue;
            }

            if (! $model->hasTranslation($field, $locale)) {
                $model->setTranslation($field, $locale, $value);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Emit the `locked_fill_empty_locale` signal (design D4) — a
     * `FrameworkGap` record AND a `Log::warning`, exactly like the existing
     * `locked_fill_empty_role_meta` precedent. This suppression MUST NOT be
     * silent: an operator re-running the seeder against a locked FV must be
     * able to tell, without reading source, that a locale was (or was not)
     * filled.
     */
    private function recordLockedFillEmptyLocaleGap(?string $roleCode, ?string $competencyCode, string $context): void
    {
        FrameworkGap::updateOrCreate(
            ['kind' => 'locked_fill_empty_locale', 'role_code' => $roleCode, 'competency_code' => $competencyCode],
            ['note' => "{$context}: a non-EN locale was filled under a locked FrameworkVersion (fill-empty-locale exception)", 'status' => 'info'],
        );
        Log::warning('FrameworkCatalogSeeder: filled an empty non-EN locale under a locked FrameworkVersion (fill-empty-locale exception).', [
            'role' => $roleCode,
            'competency' => $competencyCode,
            'context' => $context,
        ]);
    }

    /**
     * Per-pair `missing_translation` gap resolution (design D5), evaluated at
     * role×competency PAIR granularity: ALL 12 strings across the pair's 3
     * indicators must carry a non-empty `it` value before the pair counts as
     * translated. Computed from the (already-normalized, already-validated)
     * DTO — i.e. from the SOURCE JSON, never DB state — so this proceeds
     * identically whether or not a FrameworkVersion is locked.
     *
     * @param  list<IndicatorDTO>  $indicators
     * @return bool Whether this pair's `it` locale is fully translated (12 of 12 strings).
     *              The caller uses this to accumulate the global-row denominator (step 5)
     *              instead of re-deriving it from framework_gaps rows afterwards.
     */
    private function resolveOrRecordTranslationGap(string $roleCode, string $competencyCode, array $indicators): bool
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
            FrameworkGap::where('kind', 'missing_translation')
                ->where('role_code', $roleCode)
                ->where('competency_code', $competencyCode)
                ->where('status', 'pending_authoring')
                ->update(['status' => 'resolved']);

            return true;
        }

        FrameworkGap::updateOrCreate(
            ['kind' => 'missing_translation', 'role_code' => $roleCode, 'competency_code' => $competencyCode],
            ['note' => "{$roleCode}×{$competencyCode}: it locale not fully translated (12 strings required, 11 of 12 counts as 0)", 'status' => 'pending_authoring'],
        );

        return false;
    }
}
