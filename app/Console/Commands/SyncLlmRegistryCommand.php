<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\LlmModelRegistrySeeder;
use Illuminate\Console\Command;

/**
 * beai:sync-llm-registry — the ONLY production path that populates or
 * refreshes `llm_models` (pluggable-conversation-llm PR P1, design D1).
 *
 * Production runs no `db:seed` — bootstrap is `beai:provision-organization`,
 * and nothing in the Dockerfile or `railway.json` invokes a seeder. Without
 * this command the registry is empty and every model picker renders
 * nothing. Deploy runbook (design.md "Migration / Rollout"):
 *
 *     php artisan migrate --force && php artisan beai:sync-llm-registry
 *
 * Every input is a flag, and this command accepts none, so it is safe with
 * no TTY by construction — the same posture as `ProvisionOrganizationCommand`.
 * Idempotent: re-running with no seed-array change produces an identical row
 * set (`LlmModelRegistrySeeder`'s upsert-on-`key`, mark-stale-never-delete).
 */
class SyncLlmRegistryCommand extends Command
{
    protected $signature = 'beai:sync-llm-registry';

    protected $description = 'Sync the global conversation-LLM model registry from the committed catalog.';

    public function handle(LlmModelRegistrySeeder $seeder): int
    {
        $diff = $seeder->run();

        foreach ($diff['added'] as $key) {
            $this->info("added: {$key}");
        }

        foreach ($diff['updated'] as $key) {
            $this->line("updated: {$key}");
        }

        foreach ($diff['marked_unavailable'] as $key) {
            $this->warn("marked unavailable: {$key}");
        }

        if ($diff['added'] === [] && $diff['updated'] === [] && $diff['marked_unavailable'] === []) {
            $this->info('No changes.');
        }

        return self::SUCCESS;
    }
}
