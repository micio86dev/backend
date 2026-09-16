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
