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
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * FrameworkCatalogSeeder (C3 + C4 lock-guard).
 *
 * Seeds the global BEAI framework catalog from split-file JSON:
 *   docs/app_description/02-domain/framework/roles.json
 *   docs/app_description/02-domain/framework/competencies.json
 *   docs/app_description/02-domain/framework/bars/{ROLE}.json
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

        /** @var array<string, array{name: string, responsibilities: string, competencies: list<string>}> $rolesJson */
        $rolesJson = json_decode(file_get_contents($this->rolesFile), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, array{name: string, definition: string}> $competenciesJson */
        $competenciesJson = json_decode(file_get_contents($this->competenciesFile), true, 512, JSON_THROW_ON_ERROR);

        // ─── 2. Seed competencies ─────────────────────────────────────────────
        /** @var array<string, int> $competencyIdsByCode */
        $competencyIdsByCode = [];

        foreach ($competenciesJson as $code => $data) {
            $competency = Competency::firstOrNew(['code' => $code]);

            if ($locked && $competency->exists) {
                // Pre-existing row in locked mode: capture id only. DO NOT setTranslation or save.
                $competencyIdsByCode[$code] = $competency->id;
            } else {
                // New row (or unlocked mode): insert / upsert is allowed.
                $competency->type = 'standard';
                $competency->setTranslation('name', 'en', $data['name']);
                $competency->setTranslation('definition', 'en', $data['definition']);
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

                // Track structural change only for genuinely new rows.
                if ($competency->wasRecentlyCreated) {
                    $structuralChange = true;
                }
            }
        }

        // ─── 3. Seed roles + pivot + BARS ────────────────────────────────────
        foreach ($rolesJson as $roleCode => $roleData) {
            // 3a. Upsert role — use firstOrNew to set translations before initial INSERT
            $role = Role::firstOrNew(['code' => $roleCode]);

            if ($locked && $role->exists) {
                // Pre-existing role row in locked mode: `name` is NEVER touched under lock.
                //
                // Fill-empty-only exception (fix 5b): `responsibilities` is display-only
                // (its one consumer is RoleResource — it feeds no scoring, no prompt), so
                // filling an EMPTY stored value from the JSON cannot move a score in a
                // locked version, which is what the lock exists to protect. A non-empty
                // stored value is NEVER overwritten, even under this exception.
                $storedResponsibilities = $role->getTranslation('responsibilities', 'en');
                $jsonResponsibilities = $roleData['responsibilities'];

                if (($storedResponsibilities === null || $storedResponsibilities === '') && $jsonResponsibilities !== '') {
                    $role->setTranslation('responsibilities', 'en', $jsonResponsibilities);
                    $role->save();

                    FrameworkGap::updateOrCreate(
                        ['kind' => 'locked_fill_empty_role_meta', 'role_code' => $roleCode, 'competency_code' => null],
                        ['note' => "Role {$roleCode} responsibilities filled under a locked FrameworkVersion (fill-empty-only exception)", 'status' => 'info'],
                    );
                    Log::warning("FrameworkCatalogSeeder: filled empty responsibilities for role {$roleCode} under a locked FrameworkVersion (fill-empty-only exception).", [
                        'role' => $roleCode,
                    ]);
                }
            } else {
                $role->setTranslation('name', 'en', $roleData['name']);
                $role->setTranslation('responsibilities', 'en', $roleData['responsibilities']);
                $role->save();

                if ($role->wasRecentlyCreated) {
                    $structuralChange = true;
                }
            }

            // Flag empty responsibilities (always — operational gap, not catalog mutation)
            if ($roleData['responsibilities'] === '') {
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

            /** @var array<string, list<array{indicator: string, scale: array{5: string, 3: string, 1: string}}>> $barsJson */
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

                $dto = $normalizer->normalize(
                    ['code' => $competencyCode, 'name' => '', 'definition' => '', 'type' => 'standard'],
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
                        // Pre-existing indicator in locked mode: DO NOT setTranslation or save.
                        // Track position only (needed to know what "present" means for delete-stale check,
                        // but the delete-stale block below is skipped in locked mode anyway).
                        $presentPositions[] = $indicatorDto->position;
                    } else {
                        // New row (or unlocked mode): insert / upsert.
                        $indicator->setTranslation('text', 'en', $indicatorDto->text);
                        $indicator->setTranslation('anchor_5', 'en', $indicatorDto->anchor5);
                        $indicator->setTranslation('anchor_3', 'en', $indicatorDto->anchor3);
                        $indicator->setTranslation('anchor_1', 'en', $indicatorDto->anchor1);
                        $indicator->save();
                        $presentPositions[] = $indicatorDto->position;

                        if ($indicator->wasRecentlyCreated) {
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
        }

        // ─── 4. Record MTG/LAT potential competency gaps ──────────────────────
        foreach (['MTG', 'LAT'] as $potentialCode) {
            FrameworkGap::updateOrCreate(
                ['kind' => 'missing_potential_competency', 'role_code' => null, 'competency_code' => $potentialCode],
                ['note' => "{$potentialCode} potential competency definition absent — pending expert authoring", 'status' => 'pending_authoring'],
            );
        }

        // ─── 5. Record global IT translation gap ─────────────────────────────
        FrameworkGap::updateOrCreate(
            ['kind' => 'missing_translation', 'role_code' => null, 'competency_code' => null],
            ['note' => 'it locale not yet authored', 'status' => 'pending_authoring'],
        );

        // ─── 6. Bump catalog_meta revision if structural changes occurred ─────
        // CatalogMeta::bump() is EXEMPT from lock-guard suppression (operational table).
        // It fires only when genuinely new rows were inserted — NOT when mutations are suppressed.
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
}
