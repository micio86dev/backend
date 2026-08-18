<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Surfaces the framework catalogue's specialist sign-off state — machine
 * readable, and observable without a database console (`railway ssh` + this
 * command), per openspec/specs/framework-catalog/spec.md's "Calibrated
 * Draft Pending Specialist Sign-Off" requirement. Before this command,
 * nothing in code recorded or surfaced that state at all — a process gate
 * that existed only in prose.
 *
 * Reads `config('framework_catalog.specialist_signed_off')` only — see
 * config/framework_catalog.php's own header for why this is a config value
 * ("Ratification is a config change, never a code change") rather than a
 * database flag, and specifically why it is NOT
 * `FrameworkVersion::is_locked`: that flag answers a different question (is
 * a per-tenant pinned snapshot immutable because a Project now depends on
 * it), flips the moment ANY organization creates a Project — an ordinary
 * user action with no connection to specialist review — and production can
 * show it false forever for a reviewed catalogue or true within minutes of
 * launch for an unreviewed one. Neither reading would be an honest answer to
 * "has a specialist signed off on this content", so this command does not
 * consult it.
 *
 * Read-only, on purpose: this command REPORTS sign-off state, it does not
 * enforce it. Exit code is always SUCCESS regardless of the answer — gating
 * production scoring on sign-off is the spec's own requirement too, but
 * wiring an actual block into the scoring path is a separate, deliberately
 * scoped change against that same requirement, not a drive-by addition
 * alongside a status command on a live system.
 */
final class FrameworkCatalogStatusCommand extends Command
{
    protected $signature = 'beai:framework-catalog-status';

    protected $description = 'Report whether the framework catalogue has recorded specialist sign-off (config-driven, honest by default)';

    public function handle(): int
    {
        $signedOff = (bool) config('framework_catalog.specialist_signed_off');

        if (! $signedOff) {
            $this->warn('NOT RATIFIED — the framework catalogue has no recorded specialist sign-off.');
            $this->line('The newly authored indicators and SRX responsibilities remain a calibrated draft (openspec/specs/framework-catalog/spec.md, "Calibrated Draft Pending Specialist Sign-Off").');
            $this->line('Set FRAMEWORK_CATALOG_SPECIALIST_SIGNED_OFF=true (and, ideally, FRAMEWORK_CATALOG_SPECIALIST_SIGNED_OFF_BY / _AT) once an assessment specialist has reviewed and approved the content.');

            return self::SUCCESS;
        }

        $this->info('RATIFIED — the framework catalogue has a recorded specialist sign-off.');

        $by = config('framework_catalog.specialist_signed_off_by');
        $at = config('framework_catalog.specialist_signed_off_at');

        if ($by !== null && $by !== '') {
            $this->line("Signed off by: {$by}");
        }

        if ($at !== null && $at !== '') {
            $this->line("Signed off at: {$at}");
        }

        return self::SUCCESS;
    }
}
