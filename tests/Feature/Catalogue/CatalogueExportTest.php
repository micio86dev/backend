<?php

declare(strict_types=1);

/**
 * RED/GREEN — 14.2/15.3/16.1 (framework-catalogue-authoring PR4, D4):
 * `catalogue:export {revision?}` renders a revision back to the split-file
 * JSON shape on STDOUT ONLY — no `--dir`, no `--write`, no path argument at
 * all, so no repository tree is reachable (Threat Matrix: "Git repository
 * selection / commit state / push state ... answered by removal"). Proven
 * with `Storage::fake()` (the command never touches Storage, so this is
 * structural, not incidental) plus an explicit assertion that no file on the
 * fake disk was ever written.
 *
 * See `CatalogueImportTest.php` for the read side (`catalogue:import
 * --into-draft`, task 15.5) and the round-trip proof that the two commands
 * are genuinely symmetric.
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\Role;
use App\Services\FrameworkCatalog\CompetencyNormalizer;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * @return array<string, mixed>
 */
function catalogueExportOutput(?int $revisionId = null): array
{
    $arguments = $revisionId !== null ? ['revision' => $revisionId] : [];
    Artisan::call('catalogue:export', $arguments);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

test('exporting a revision makes zero filesystem writes — STDOUT only', function (): void {
    Storage::fake();

    (new FrameworkCatalogSeeder)->run();

    catalogueExportOutput();

    expect(Storage::allFiles())->toBe([]);
    expect(Storage::allDirectories())->toBe([]);
});

test('an exported published revision matches its stored rows byte-for-byte', function (): void {
    (new FrameworkCatalogSeeder)->run();

    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();

    $export = catalogueExportOutput($baseline->id);

    expect($export['roles'])->not->toBeEmpty();
    expect($export['competencies'])->not->toBeEmpty();
    expect($export['bars'])->not->toBeEmpty();

    $role = Role::where('revision_id', $baseline->id)->orderBy('code')->firstOrFail();
    expect($export['roles'][$role->code]['name'])->toBe($role->getTranslations('name'));
    expect($export['roles'][$role->code]['responsibilities'])->toBe($role->getTranslations('responsibilities'));

    $expectedCodes = $role->competencies()->pluck('code')->values()->all();
    expect($export['roles'][$role->code]['competencies'])->toBe($expectedCodes);

    $competency = Competency::where('revision_id', $baseline->id)->orderBy('code')->firstOrFail();
    expect($export['competencies'][$competency->code]['name'])->toBe($competency->getTranslations('name'));
    expect($export['competencies'][$competency->code]['definition'])->toBe($competency->getTranslations('definition'));

    $indicator = BarsIndicator::where('revision_id', $baseline->id)
        ->where('role_id', $role->id)
        ->where('competency_id', $competency->id)
        ->orderBy('position')
        ->first();

    if ($indicator !== null) {
        $exported = $export['bars'][$role->code][$competency->code][$indicator->position];
        expect($exported['indicator'])->toBe($indicator->getTranslations('text'));
        expect($exported['scale']['5'])->toBe($indicator->getTranslations('anchor_5'));
        expect($exported['scale']['3'])->toBe($indicator->getTranslations('anchor_3'));
        expect($exported['scale']['1'])->toBe($indicator->getTranslations('anchor_1'));
    }

    // Role-less (potential) indicators are exported under the POTENTIAL key.
    $potentialIndicator = BarsIndicator::where('revision_id', $baseline->id)->whereNull('role_id')->first();
    expect($potentialIndicator)->not->toBeNull();
    $potentialCompetency = Competency::find($potentialIndicator->competency_id);
    expect($export['bars']['POTENTIAL'][$potentialCompetency->code][$potentialIndicator->position]['indicator'])
        ->toBe($potentialIndicator->getTranslations('text'));
});

/**
 * Z1 (R3-export-position-loss, REQUIRED BEFORE ARCHIVE): a CRUD-authored,
 * non-sequential position set (5, 10 — legal: `position` only requires
 * `min:0`, never contiguity) must not be silently reindexed to 0, 1 by
 * `array_values()`. Uses a scratch DRAFT revision (G3.2's `BarsIndicator`
 * factory), not the baseline — writing arbitrary extra content directly onto
 * the published baseline is exactly what this change's immutability work
 * exists to prevent.
 */
test('Z1: exported indicators carry their stored, possibly non-sequential position', function (): void {
    $scratch = FrameworkCatalogRevision::factory()->draft()->create();
    $role = Role::factory()->create(['revision_id' => $scratch->id]);
    $competency = Competency::factory()->create(['revision_id' => $scratch->id]);

    $first = BarsIndicator::factory()->create([
        'revision_id' => $scratch->id,
        'role_id' => $role->id,
        'competency_id' => $competency->id,
        'position' => 5,
    ]);
    $second = BarsIndicator::factory()->create([
        'revision_id' => $scratch->id,
        'role_id' => $role->id,
        'competency_id' => $competency->id,
        'position' => 10,
    ]);

    $export = catalogueExportOutput($scratch->id);

    $exportedList = $export['bars'][$role->code][$competency->code];
    expect($exportedList)->toHaveCount(2);

    // Order is preserved (ascending position), and each entry carries its
    // OWN stored position rather than the array index it happens to sit at.
    expect($exportedList[0]['position'])->toBe($first->position);
    expect($exportedList[1]['position'])->toBe($second->position);
    expect($exportedList[0]['position'])->not->toBe(0);
    expect($exportedList[1]['position'])->not->toBe(1);
});

/**
 * Z1, import side: `CompetencyNormalizer` reads the explicit `position` key
 * `catalogue:export` now emits, instead of only the array's own order —
 * proving the two commands are genuinely symmetric for a non-sequential set.
 */
test('Z1: CompetencyNormalizer honors an explicit position key over array order', function (): void {
    $barsArray = [
        [
            'indicator' => ['en' => 'first, stored at position 10'],
            'scale' => ['5' => ['en' => 'a5'], '3' => ['en' => 'a3'], '1' => ['en' => 'a1']],
            'position' => 10,
        ],
        [
            'indicator' => ['en' => 'second, stored at position 5'],
            'scale' => ['5' => ['en' => 'a5'], '3' => ['en' => 'a3'], '1' => ['en' => 'a1']],
            'position' => 5,
        ],
    ];

    $dto = (new CompetencyNormalizer)->normalize(
        ['code' => 'PRS', 'name' => ['en' => 'x'], 'definition' => ['en' => 'y']],
        $barsArray,
    );

    expect($dto->indicators[0]->position)->toBe(10);
    expect($dto->indicators[1]->position)->toBe(5);
});

/**
 * Backward compatibility: a vendored entry with no `position` key at all
 * (every file under `docs/app_description/.../bars/*.json` today) still
 * falls back to array order — the fallback this fix must not remove.
 */
test('Z1: CompetencyNormalizer falls back to array order when position is absent', function (): void {
    $barsArray = [
        [
            'indicator' => ['en' => 'first'],
            'scale' => ['5' => ['en' => 'a5'], '3' => ['en' => 'a3'], '1' => ['en' => 'a1']],
        ],
        [
            'indicator' => ['en' => 'second'],
            'scale' => ['5' => ['en' => 'a5'], '3' => ['en' => 'a3'], '1' => ['en' => 'a1']],
        ],
    ];

    $dto = (new CompetencyNormalizer)->normalize(
        ['code' => 'PRS', 'name' => ['en' => 'x'], 'definition' => ['en' => 'y']],
        $barsArray,
    );

    expect($dto->indicators[0]->position)->toBe(0);
    expect($dto->indicators[1]->position)->toBe(1);
});
