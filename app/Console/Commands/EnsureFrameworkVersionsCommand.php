<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Organizations\EnsureDefaultFrameworkVersion;
use App\Exceptions\Console\BackfillDryRunRollback;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan beai:ensure-framework-versions [--org=] [--dry-run]`
 *
 * Gives every organization that has NO FrameworkVersion a default one. Such
 * organizations predate `CreateOrganization` creating the version, and cannot
 * create a project (`POST /api/projects` only accepts a version of the
 * caller's own organization).
 *
 * Idempotent: an organization with any version is left alone, so it is safe
 * to run on every deploy (`beai:deploy` does). It must run AFTER the
 * catalogue seed — with no published revision there is nothing to pin to, and
 * the organization is skipped with a warning rather than given a null pin.
 *
 * `--dry-run` runs the SAME action inside a transaction it then rolls back
 * (as `beai:backfill-project-questions` does), so the preview cannot drift
 * from what a real run does.
 */
final class EnsureFrameworkVersionsCommand extends Command
{
    protected $signature = 'beai:ensure-framework-versions
        {--org= : Slug of one organization to fix. Omit to fix every organization.}
        {--dry-run : Report what would be created without writing anything}';

    protected $description = 'Create a default framework version for every organization that has none, so it can create projects';

    public function handle(EnsureDefaultFrameworkVersion $ensure): int
    {
        $orgSlug = trim((string) $this->option('org'));
        $dryRun = (bool) $this->option('dry-run');

        $organizations = $orgSlug === ''
            ? Organization::withoutGlobalScopes()->orderBy('id')->get()
            : Organization::withoutGlobalScopes()->where('slug', $orgSlug)->get();

        if ($organizations->isEmpty()) {
            $this->error($orgSlug === ''
                ? 'No organizations exist. Nothing to do.'
                : "No organization with slug '{$orgSlug}'.");

            return self::FAILURE;
        }

        $createdCount = 0;
        /** @var list<string> $skipped */
        $skipped = [];

        foreach ($organizations as $organization) {
            $hasVersion = DB::table('framework_versions')->where('organization_id', $organization->id)->exists();

            if ($hasVersion) {
                continue;
            }

            if ($this->createFor($organization, $ensure, $dryRun)) {
                $createdCount++;
                $this->line(sprintf(
                    '  %s a default framework version for %s',
                    $dryRun ? '[dry-run] would create' : 'created',
                    $organization->slug,
                ));
            } else {
                $skipped[] = $organization->slug;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d default framework version(s) across %d organization(s).',
            $dryRun ? 'Would create' : 'Created',
            $createdCount,
            $organizations->count(),
        ));

        if ($skipped !== []) {
            $this->warn(sprintf(
                'Skipped %d organization(s): no published catalogue revision to pin to (%s). Seed the catalogue and re-run.',
                count($skipped),
                implode(', ', $skipped),
            ));
        }

        return self::SUCCESS;
    }

    private function createFor(Organization $organization, EnsureDefaultFrameworkVersion $ensure, bool $dryRun): bool
    {
        $created = false;

        try {
            DB::transaction(function () use ($organization, $ensure, $dryRun, &$created): void {
                $created = $ensure->ensure($organization);

                if ($dryRun) {
                    throw new BackfillDryRunRollback;
                }
            });
        } catch (BackfillDryRunRollback) {
            // Deliberate: the writes above are rolled back; $created was
            // captured inside the transaction and reflects a real run.
        }

        return $created;
    }
}
