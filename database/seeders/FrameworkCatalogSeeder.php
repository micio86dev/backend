<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BarsIndicator;
use App\Models\CatalogMeta;
use App\Models\Competency;
use App\Models\FrameworkGap;
use App\Models\Role;
use App\Services\FrameworkCatalog\CompetencyNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FrameworkCatalogSeeder (C3).
 *
 * Seeds the global BEAI framework catalog from split-file JSON:
 *   docs/app_description/02-domain/framework/roles.json
 *   docs/app_description/02-domain/framework/competencies.json
 *   docs/app_description/02-domain/framework/bars/{ROLE}.json
 *
 * Idempotent + delete-stale (sync):
 *   - updateOrCreate() for roles and competencies (by code)
 *   - sync() for role_competency pivot (removes stale assignments)
 *   - upsert + delete-stale for bars_indicators per (role, competency)
 *   - updateOrCreate() for all framework_gaps (NULLS NOT DISTINCT at DB layer)
 *
 * EN-only authoring: setTranslation('field', 'en', ...) per field.
 * IT translations absent → recorded as missing_translation gap.
 * Re-seeding PRESERVES manually-added translations in other locales.
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

        // ─── 1. Load JSON data ────────────────────────────────────────────────
        /** @var array<string, array{name: string, responsibilities: string, competencies: list<string>}> $rolesJson */
        $rolesJson = json_decode(file_get_contents($this->rolesFile), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, array{name: string, definition: string}> $competenciesJson */
        $competenciesJson = json_decode(file_get_contents($this->competenciesFile), true, 512, JSON_THROW_ON_ERROR);

        // ─── 2. Seed competencies ─────────────────────────────────────────────
        /** @var array<string, int> $competencyIdsByCode */
        $competencyIdsByCode = [];

        foreach ($competenciesJson as $code => $data) {
            // Use firstOrNew so we can set translations before the initial INSERT.
            // This avoids NOT NULL violations on json columns during updateOrCreate.
            $competency = Competency::firstOrNew(['code' => $code]);
            $competency->type = 'standard';

            // Write EN translations via setTranslation — does NOT overwrite other locales.
            // Per spec: re-seeding MUST preserve manually-added translations in other locales.
            $competency->setTranslation('name', 'en', $data['name']);
            $competency->setTranslation('definition', 'en', $data['definition']);
            $competency->save();

            $competencyIdsByCode[$code] = $competency->id;
        }

        // ─── 3. Seed roles + pivot + BARS ────────────────────────────────────
        foreach ($rolesJson as $roleCode => $roleData) {
            // 3a. Upsert role — use firstOrNew to set translations before initial INSERT
            $role = Role::firstOrNew(['code' => $roleCode]);
            $role->setTranslation('name', 'en', $roleData['name']);
            $role->setTranslation('responsibilities', 'en', $roleData['responsibilities']);
            $role->save();

            // Flag empty responsibilities
            if ($roleData['responsibilities'] === '') {
                FrameworkGap::updateOrCreate(
                    ['kind' => 'missing_role_meta', 'role_code' => $roleCode, 'competency_code' => null],
                    ['note' => "Role {$roleCode} responsibilities is empty string — pending authoring", 'status' => 'pending_authoring'],
                );
            }

            // 3b. Sync pivot — remove stale assignments
            $assignedCodes = $roleData['competencies'];
            $assignedIds = [];
            foreach ($assignedCodes as $position => $competencyCode) {
                if (isset($competencyIdsByCode[$competencyCode])) {
                    $assignedIds[$competencyIdsByCode[$competencyCode]] = ['position' => $position];
                }
            }

            // Before sync: capture current pivot competency IDs to detect removals
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

            // The current assigned competency IDs (after sync) — only process bars for these
            $currentAssignedIds = array_keys($assignedIds);

            foreach ($barsJson as $competencyCode => $indicatorArray) {
                if (! isset($competencyIdsByCode[$competencyCode])) {
                    continue;
                }

                $competencyId = $competencyIdsByCode[$competencyCode];

                // Only seed bars for competencies actually assigned to this role
                if (! in_array($competencyId, $currentAssignedIds, true)) {
                    // Competency exists in bars file but is no longer assigned to this role
                    // Delete any stale indicators for this pair
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

                    $indicator->setTranslation('text', 'en', $indicatorDto->text);
                    $indicator->setTranslation('anchor_5', 'en', $indicatorDto->anchor5);
                    $indicator->setTranslation('anchor_3', 'en', $indicatorDto->anchor3);
                    $indicator->setTranslation('anchor_1', 'en', $indicatorDto->anchor1);
                    $indicator->save();

                    $presentPositions[] = $indicatorDto->position;
                    $structuralChange = true;
                }

                // Delete stale indicators (positions no longer in JSON)
                BarsIndicator::where('role_id', $role->id)
                    ->where('competency_id', $competencyId)
                    ->whereNotIn('position', $presentPositions)
                    ->delete();
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
        if ($structuralChange) {
            CatalogMeta::bump();
        }
    }
}
