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
 * @param string|null $rolesFile     Override path to roles.json (for testing)
 * @param string|null $competenciesFile Override path to competencies.json (for testing)
 * @param string|null $barsDir       Override path to bars/ directory (for testing)
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
        // JSON source files live in the wrapper repo's docs/ directory,
        // one level ABOVE the api/ submodule (base_path() = api/).
        // dirname(base_path()) resolves to the wrapper root.
        $frameworkBase = dirname(base_path()).'/docs/app_description/02-domain/framework';
        $this->rolesFile = $rolesFile ?? "{$frameworkBase}/roles.json";
        $this->competenciesFile = $competenciesFile ?? "{$frameworkBase}/competencies.json";
        $this->barsDir = $barsDir ?? "{$frameworkBase}/bars";
    }

    public function run(): void
    {
        $normalizer = new CompetencyNormalizer();
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
                    // Already existed — in unlocked mode we just re-saved; that's fine.
                    // In locked mode we only reach here for new rows (exists was false above).
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
                // Pre-existing role row in locked mode: capture only, do NOT save.
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

            // 3d. Record competency_no_bars gaps for assigned competencies absent from BARS file
            foreach ($assignedCodes as $competencyCode) {
                if (! in_array($competencyCode, $coveredCompetencyCodes, true)) {
                    FrameworkGap::updateOrCreate(
                        ['kind' => 'competency_no_bars', 'role_code' => $roleCode, 'competency_code' => $competencyCode],
                        ['note' => "Competency {$competencyCode} assigned to {$roleCode} but absent from BARS file", 'status' => 'pending_authoring'],
                    );
                }
            }
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
