<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LlmModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * LlmModelRegistrySeeder — the global conversation-LLM model catalog
 * (pluggable-conversation-llm PR P1, design D1).
 *
 * Idempotent upsert + mark-stale, NEVER delete-stale, mirroring
 * `FrameworkCatalogSeeder`'s `updateOrCreate()`-on-natural-key pattern. A
 * model absent from a re-run of the seed array becomes `is_available =
 * false` rather than being deleted: a deleted row would break the display
 * name on every historical cost row and bound template that already
 * references it by foreign key.
 *
 * NO factories, NO `fake()` — `fakerphp/faker` is `require-dev` and absent
 * from the `--no-dev` Docker image (see `DatabaseSeeder`'s docblock). Every
 * row here is a literal value from the committed data array.
 *
 * The whole run executes inside ONE `DB::transaction()`, mirroring
 * `FrameworkCatalogSeeder`'s atomicity hardening: a throw partway through
 * must not leave some rows upserted and others marked stale from a half
 * -applied run.
 *
 * `$models` is an override seam for tests only (mirrors
 * `FrameworkCatalogSeeder`'s file-path constructor arguments) — production
 * always reads the committed `database/seeders/data/llm_models.php` array,
 * via `beai:sync-llm-registry`, never `db:seed` (production runs no seeder).
 */
class LlmModelRegistrySeeder extends Seeder
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $models;

    /**
     * @param  list<array<string, mixed>>|null  $models
     */
    public function __construct(?array $models = null)
    {
        $this->models = $models ?? require database_path('seeders/data/llm_models.php');
    }

    /**
     * @return array{added: list<string>, updated: list<string>, marked_unavailable: list<string>}
     */
    public function run(): array
    {
        return DB::transaction(function (): array {
            $added = [];
            $updated = [];
            $seededKeys = [];

            foreach ($this->models as $row) {
                $key = (string) $row['key'];
                $seededKeys[] = $key;

                $existed = LlmModel::where('key', $key)->exists();

                LlmModel::updateOrCreate(
                    ['key' => $key],
                    array_merge($row, ['is_available' => true]),
                );

                if ($existed) {
                    $updated[] = $key;
                } else {
                    $added[] = $key;
                }
            }

            // Mark-stale, never delete-stale. A row absent from this run
            // keeps its id, its display name, and every FK pointing at it —
            // only its availability changes.
            $markedUnavailable = [];

            foreach (LlmModel::whereNotIn('key', $seededKeys)->where('is_available', true)->get() as $stale) {
                $markedUnavailable[] = $stale->key;
            }

            LlmModel::whereNotIn('key', $seededKeys)
                ->where('is_available', true)
                ->update(['is_available' => false]);

            return [
                'added' => $added,
                'updated' => $updated,
                'marked_unavailable' => $markedUnavailable,
            ];
        });
    }
}
