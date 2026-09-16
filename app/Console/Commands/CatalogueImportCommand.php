<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Catalogue\OpenDraftRevision;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\Role;
use App\Services\FrameworkCatalog\CompetencyNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * `php artisan catalogue:import --into-draft` (framework-catalogue-authoring
 * PR4, task 15.5 — OQ-A, resolved by the product owner 2026-09-15).
 *
 * Reads the SAME vendored split-file JSON trees `FrameworkCatalogSeeder`
 * already reads (`roles.json`, `competencies.json`, `bars/{ROLE}.json`,
 * `bars/POTENTIAL.json`) — the same shape `catalogue:export` prints — and
 * writes their content into the ONE open draft revision, opened or continued
 * via `OpenDraftRevision`. This is the ingress for ruling 6's expert-
 * authored Italian anchor translations now that a published baseline takes
 * no seeder writes at all (D2): a translator edits the vendored tree
 * directly, exactly as before, and this command is what turns that edit
 * into a reviewable draft again.
 *
 * NEVER writes a published revision, and NEVER publishes:
 *   - the target is always whatever `OpenDraftRevision::open()` returns,
 *     which is by construction always `state = draft`;
 *   - every write below goes through the ordinary Eloquent
 *     create/save/sync path — the same path the CRUD controllers use — so
 *     the content-immutability trigger and `FrameworkCatalogSeeder::
 *     writesAreBlocked()` are exercised exactly as they are for any other
 *     writer, never bypassed;
 *   - publishing stays a separate, reviewable act through `PublishRevision`
 *     and its sweep — this command has no code path that can reach it.
 *
 * Refuses cleanly when an already-open draft carries unrelated edits
 * (`content_version !== 0` — the same "genuinely untouched" signal
 * `DiscardUnusedDraftRevision` uses), unless `--continue` says otherwise: an
 * import silently overwriting a superadmin's in-progress backoffice edits
 * would be a worse outcome than refusing and asking.
 */
final class CatalogueImportCommand extends Command
{
    /**
     * The competencies that belong to `potential` and to no role — see
     * `FrameworkCatalogSeeder::POTENTIAL_CODES`'s own docblock for why this
     * is a constant here too, rather than a JSON-authored `type` key.
     *
     * @var list<string>
     */
    private const POTENTIAL_CODES = ['MTG', 'LAT'];

    protected $signature = 'catalogue:import
        {--into-draft : Import into the open draft revision — the only supported target}
        {--continue : Continue a draft that already carries unrelated edits, instead of refusing}
        {--path= : Override the catalogue source directory (testing only)}';

    protected $description = 'Import the vendored split-file catalogue JSON into the open draft revision — never a published one, never publishes';

    public function handle(OpenDraftRevision $openDraftRevision): int
    {
        if (! $this->option('into-draft')) {
            $this->error('catalogue_import_target_required: pass --into-draft — there is no other supported import target.');

            return self::FAILURE;
        }

        $existingDraft = FrameworkCatalogRevision::openDraft();

        if ($existingDraft !== null && $existingDraft->content_version !== 0 && ! $this->option('continue')) {
            $this->error('catalogue_import_draft_has_unrelated_edits: the open draft already carries edits. Pass --continue to import into it anyway.');

            return self::FAILURE;
        }

        $basePath = $this->option('path') ?: (config('framework_catalog.catalog_path') ?: database_path('framework'));
        $rolesFile = "{$basePath}/roles.json";
        $competenciesFile = "{$basePath}/competencies.json";
        $barsDir = "{$basePath}/bars";

        foreach ([$rolesFile, $competenciesFile] as $required) {
            if (! is_readable($required)) {
                $this->error("catalogue_import_source_unreadable: {$required}");

                return self::FAILURE;
            }
        }

        /** @var array<string, array{name: array<string,string>, definition: array<string,string>}> $competenciesJson */
        $competenciesJson = json_decode((string) file_get_contents($competenciesFile), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, array{name: array<string,string>, responsibilities: array<string,string>, competencies: list<string>}> $rolesJson */
        $rolesJson = json_decode((string) file_get_contents($rolesFile), true, 512, JSON_THROW_ON_ERROR);

        $draft = $openDraftRevision->open();
        $normalizer = new CompetencyNormalizer;

        DB::transaction(function () use ($draft, $competenciesJson, $rolesJson, $barsDir, $normalizer): void {
            $competencyIdsByCode = $this->importCompetencies($draft->id, $competenciesJson);
            $this->importRolesAndBars($draft->id, $rolesJson, $barsDir, $competencyIdsByCode, $normalizer);
            $this->importPotentialBars($draft->id, $barsDir, $competencyIdsByCode, $normalizer);
        });

        $this->info("Imported catalogue content into draft revision {$draft->id}.");

        return self::SUCCESS;
    }

    /**
     * Untrusted-file shape (gga review finding, PHPStan `nullCoalesce.offset`):
     * `name`/`definition` are marked optional here, not guaranteed present —
     * a vendored JSON file read from disk can be malformed, and
     * `readLocaleMap()`'s own `?? null` fallback exists precisely to turn a
     * missing key into that method's own explicit `RuntimeException` rather
     * than a raw PHP notice.
     *
     * @param  array<string, array{name?: array<string,string>, definition?: array<string,string>}>  $competenciesJson
     * @return array<string, int>
     */
    private function importCompetencies(int $draftId, array $competenciesJson): array
    {
        $competencyIdsByCode = [];

        foreach ($competenciesJson as $code => $data) {
            $competency = Competency::where('revision_id', $draftId)->where('code', $code)->first()
                ?? new Competency(['revision_id' => $draftId, 'code' => $code]);

            $competency->type = in_array($code, self::POTENTIAL_CODES, true) ? 'potential' : 'standard';
            $this->setAllLocales($competency, 'name', $this->readLocaleMap($data['name'] ?? null, "competencies.json:{$code}.name"));
            $this->setAllLocales($competency, 'definition', $this->readLocaleMap($data['definition'] ?? null, "competencies.json:{$code}.definition"));
            $competency->save();

            $competencyIdsByCode[$code] = $competency->id;
        }

        return $competencyIdsByCode;
    }

    /**
     * Same untrusted-file rationale as `importCompetencies()` above — every
     * key here is optional in the type, not guaranteed present.
     *
     * @param  array<string, array{name?: array<string,string>, responsibilities?: array<string,string>, competencies?: list<string>}>  $rolesJson
     * @param  array<string, int>  $competencyIdsByCode
     */
    private function importRolesAndBars(int $draftId, array $rolesJson, string $barsDir, array $competencyIdsByCode, CompetencyNormalizer $normalizer): void
    {
        foreach ($rolesJson as $roleCode => $roleData) {
            $role = Role::where('revision_id', $draftId)->where('code', $roleCode)->first()
                ?? new Role(['revision_id' => $draftId, 'code' => $roleCode]);

            $this->setAllLocales($role, 'name', $this->readLocaleMap($roleData['name'] ?? null, "roles.json:{$roleCode}.name"));
            $this->setAllLocales($role, 'responsibilities', $this->readLocaleMap($roleData['responsibilities'] ?? null, "roles.json:{$roleCode}.responsibilities", allowBlankEn: true));
            $role->save();

            $assignedIds = [];
            // Already a JSON-array-derived list (0..n-1 keys in authored
            // order) once present at all — `array_values()` would be a
            // redundant no-op on that shape; `?? []` alone is what makes the
            // "key absent from a malformed file" case safe to iterate.
            foreach ($roleData['competencies'] ?? [] as $position => $competencyCode) {
                if (isset($competencyIdsByCode[$competencyCode])) {
                    $assignedIds[$competencyIdsByCode[$competencyCode]] = ['position' => $position];
                }
            }
            $role->competencies()->sync($assignedIds);

            $barsFile = "{$barsDir}/{$roleCode}.json";

            if (! is_file($barsFile)) {
                continue;
            }

            /** @var array<string, list<array<string, mixed>>> $barsJson */
            $barsJson = json_decode((string) file_get_contents($barsFile), true, 512, JSON_THROW_ON_ERROR);

            foreach ($barsJson as $competencyCode => $indicatorArray) {
                if (! isset($competencyIdsByCode[$competencyCode]) || ! isset($assignedIds[$competencyIdsByCode[$competencyCode]])) {
                    continue;
                }

                $competencyId = $competencyIdsByCode[$competencyCode];

                $dto = $normalizer->normalize(
                    ['code' => $competencyCode, 'name' => ['en' => $competencyCode], 'definition' => ['en' => $competencyCode], 'type' => 'standard'],
                    $indicatorArray,
                );

                foreach ($dto->indicators as $indicatorDto) {
                    $indicator = BarsIndicator::where('revision_id', $draftId)
                        ->where('role_id', $role->id)
                        ->where('competency_id', $competencyId)
                        ->where('position', $indicatorDto->position)
                        ->first()
                        ?? new BarsIndicator([
                            'revision_id' => $draftId,
                            'role_id' => $role->id,
                            'competency_id' => $competencyId,
                            'position' => $indicatorDto->position,
                        ]);

                    $this->setAllLocales($indicator, 'text', $indicatorDto->text);
                    $this->setAllLocales($indicator, 'anchor_5', $indicatorDto->anchor5);
                    $this->setAllLocales($indicator, 'anchor_3', $indicatorDto->anchor3);
                    $this->setAllLocales($indicator, 'anchor_1', $indicatorDto->anchor1);
                    $indicator->save();
                }
            }
        }
    }

    /**
     * `bars/POTENTIAL.json` — role-less indicators for MTG/LAT, mirroring
     * `FrameworkCatalogSeeder::seedPotentialIndicators()`.
     *
     * @param  array<string, int>  $competencyIdsByCode
     */
    private function importPotentialBars(int $draftId, string $barsDir, array $competencyIdsByCode, CompetencyNormalizer $normalizer): void
    {
        $file = "{$barsDir}/POTENTIAL.json";

        if (! is_file($file)) {
            return;
        }

        /** @var array<string, list<array<string, mixed>>> $json */
        $json = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        foreach ($json as $code => $indicatorArray) {
            if (! isset($competencyIdsByCode[$code])) {
                continue;
            }

            $competencyId = $competencyIdsByCode[$code];

            $dto = $normalizer->normalize(
                ['code' => $code, 'name' => ['en' => $code], 'definition' => ['en' => $code], 'type' => 'potential'],
                $indicatorArray,
            );

            foreach ($dto->indicators as $indicatorDto) {
                $indicator = BarsIndicator::where('revision_id', $draftId)
                    ->where('role_id', null)
                    ->where('competency_id', $competencyId)
                    ->where('position', $indicatorDto->position)
                    ->first()
                    ?? new BarsIndicator([
                        'revision_id' => $draftId,
                        'role_id' => null,
                        'competency_id' => $competencyId,
                        'position' => $indicatorDto->position,
                    ]);

                $this->setAllLocales($indicator, 'text', $indicatorDto->text);
                $this->setAllLocales($indicator, 'anchor_5', $indicatorDto->anchor5);
                $this->setAllLocales($indicator, 'anchor_3', $indicatorDto->anchor3);
                $this->setAllLocales($indicator, 'anchor_1', $indicatorDto->anchor1);
                $indicator->save();
            }
        }
    }

    /**
     * Write EVERY locale present in the source map — mirrors
     * `FrameworkCatalogSeeder::setAllLocales()` exactly, for the same reason:
     * an authored `it` value and the existing `en` value both flow through
     * this one code path.
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
     * Mirrors `FrameworkCatalogSeeder::readLocaleMap()` exactly — the source
     * files are the SAME vendored trees, so the same validation applies:
     * `en` mandatory (blank permitted only for `allowBlankEn`, the role-
     * responsibilities "not yet authored" sentinel), every other key a known
     * locale.
     *
     * @return array<string, string>
     */
    private function readLocaleMap(mixed $value, string $context, bool $allowBlankEn = false): array
    {
        if (! is_array($value) || array_is_list($value)) {
            $got = is_array($value) ? 'a list/array' : get_debug_type($value);

            throw new RuntimeException("catalogue:import: {$context} must be a locale-map object (e.g. {\"en\": \"...\"}), got {$got}.");
        }

        if (! array_key_exists('en', $value) || ! is_string($value['en'])) {
            throw new RuntimeException("catalogue:import: {$context} is missing a mandatory 'en' locale value.");
        }

        if (! $allowBlankEn && $value['en'] === '') {
            throw new RuntimeException("catalogue:import: {$context} has a blank 'en' locale value.");
        }

        /** @var list<string> $knownLocales */
        $knownLocales = config('app.supported_locales', ['en']);
        $knownLocales = in_array('en', $knownLocales, true) ? $knownLocales : [...$knownLocales, 'en'];

        foreach ($value as $locale => $text) {
            if (! is_string($locale) || ! in_array($locale, $knownLocales, true)) {
                throw new RuntimeException("catalogue:import: {$context} has an unknown locale key [{$locale}].");
            }

            if (! is_string($text)) {
                throw new RuntimeException("catalogue:import: {$context} locale [{$locale}] must be a string, got ".get_debug_type($text).'.');
            }
        }

        /** @var array<string, string> $value */
        return $value;
    }
}
