<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * `php artisan catalogue:export {revision?}` (framework-catalogue-authoring
 * PR4, design D4, catalogue-authoring spec — "Read-Only Revision Export").
 *
 * Renders a catalogue revision back to the split-file JSON shape
 * `FrameworkCatalogSeeder` reads (`roles.json`, `competencies.json`,
 * `bars/{ROLE}.json`, `bars/POTENTIAL.json` for role-less indicators) — for
 * human review as a diff, and for deliberately refreshing the vendored
 * baseline trees in a reviewed PR.
 *
 * STDOUT ONLY, structurally — there is no `--dir`, no `--write` mode, and no
 * path argument at all, so there is no path to validate and nothing for a
 * future edit to loosen (design D4). Because a single stream cannot carry
 * multiple files, the split-file trees are bundled into one JSON envelope
 * (`roles`, `competencies`, `bars`) — a human (or `catalogue:import`, once
 * split back apart) is what turns this into the actual multi-file tree; this
 * command itself never touches a filesystem disk or a repository path.
 *
 * `catalogue:import --into-draft` is the read side, and reads THIS shape
 * split back into files — see that command's own docblock.
 */
final class CatalogueExportCommand extends Command
{
    protected $signature = 'catalogue:export {revision? : Revision id (defaults to the latest published revision)}';

    protected $description = 'Render a catalogue revision to the split-file JSON shape, printed to STDOUT only — never writes a file';

    public function handle(): int
    {
        $revision = $this->resolveRevision();

        if ($revision === null) {
            $this->error('No matching catalogue revision found.');

            return self::FAILURE;
        }

        $this->line(json_encode(
            $this->export($revision),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }

    private function resolveRevision(): ?FrameworkCatalogRevision
    {
        $revisionId = $this->argument('revision');

        if ($revisionId !== null) {
            return FrameworkCatalogRevision::find((int) $revisionId);
        }

        // K7 (framework-catalogue-authoring PR4b): the ONE `latestPublished()`
        // implementation every "latest published revision" reader now shares.
        return FrameworkCatalogRevision::latestPublished();
    }

    /**
     * @return array{roles: array<string, mixed>, competencies: array<string, mixed>, bars: array<string, mixed>}
     */
    private function export(FrameworkCatalogRevision $revision): array
    {
        $competencies = Competency::where('revision_id', $revision->id)->orderBy('code')->get();
        $roles = Role::where('revision_id', $revision->id)->orderBy('code')->get();

        $competenciesJson = [];
        foreach ($competencies as $competency) {
            $competenciesJson[$competency->code] = [
                'name' => $competency->getTranslations('name'),
                'definition' => $competency->getTranslations('definition'),
            ];
        }

        $rolesJson = [];
        $barsJson = [];

        foreach ($roles as $role) {
            $rolesJson[$role->code] = [
                'name' => $role->getTranslations('name'),
                'responsibilities' => $role->getTranslations('responsibilities'),
                // Pivot-ordered (the relation's own `orderBy('position')`),
                // so array index reproduces the exact authored position.
                'competencies' => $role->competencies()->pluck('code')->values()->all(),
            ];

            $roleIndicators = BarsIndicator::where('revision_id', $revision->id)
                ->where('role_id', $role->id)
                ->orderBy('position')
                ->get()
                ->groupBy('competency_id');

            $barsJson[$role->code] = $this->indicatorsByCompetencyCode($roleIndicators, $competencies);
        }

        $potentialIndicators = BarsIndicator::where('revision_id', $revision->id)
            ->whereNull('role_id')
            ->orderBy('position')
            ->get()
            ->groupBy('competency_id');

        $potentialJson = $this->indicatorsByCompetencyCode($potentialIndicators, $competencies);

        if ($potentialJson !== []) {
            $barsJson['POTENTIAL'] = $potentialJson;
        }

        return [
            'roles' => $rolesJson,
            'competencies' => $competenciesJson,
            'bars' => $barsJson,
        ];
    }

    /**
     * @param  Collection<int|string, EloquentCollection<int, BarsIndicator>>  $indicatorsByCompetencyId
     * @param  EloquentCollection<int, Competency>  $competencies
     * @return array<string, list<array{indicator: array<string, string>, scale: array{5: array<string, string>, 3: array<string, string>, 1: array<string, string>}, position: int}>>
     */
    private function indicatorsByCompetencyCode(Collection $indicatorsByCompetencyId, EloquentCollection $competencies): array
    {
        $result = [];

        foreach ($indicatorsByCompetencyId as $competencyId => $indicators) {
            $code = $competencies->firstWhere('id', $competencyId)?->code;

            if ($code === null) {
                continue;
            }

            // `array_values()`, not a bare `->all()` — `->map()` over a
            // freshly `->values()`-reindexed Collection is still typed as a
            // possibly-non-sequential array by PHPStan/Larastan; this is
            // what actually pins it down to a `list<...>`, matching this
            // method's own declared return shape.
            //
            // Z1 (framework-catalogue-authoring, REQUIRED BEFORE ARCHIVE):
            // the list is still ORDERED by position (`sortBy('position')`),
            // but each entry now also carries its own STORED `position`
            // explicitly — a CRUD-authored, non-sequential position set
            // (e.g. 5, 10) previously exported as list index 0, 1 with no
            // way to recover the original values; `CompetencyNormalizer`
            // reads this field back on import (falls back to array index
            // only for the vendored trees, which never carry it).
            $result[$code] = array_values($indicators->sortBy('position')->values()->map(
                fn (BarsIndicator $indicator): array => [
                    'indicator' => $indicator->getTranslations('text'),
                    'scale' => [
                        '5' => $indicator->getTranslations('anchor_5'),
                        '3' => $indicator->getTranslations('anchor_3'),
                        '1' => $indicator->getTranslations('anchor_1'),
                    ],
                    'position' => $indicator->position,
                ],
            )->all());
        }

        return $result;
    }
}
