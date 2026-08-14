<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AvatarTemplate;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Demo\DemoDatasetValidator;
use App\Support\Demo\DemoMarker;
use App\Support\Demo\DemoWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Provisions a rich, BARS-valid demo dataset into an organization that
 * already exists (design: no separate demo organization, D1).
 *
 * Replaces `database/seeders/DemoSeeder.php` (deleted — design D2): a seeder
 * that silently no-ops in production is exactly what produced the gap this
 * command exists to close. Every write here is explicit, auditable, and
 * gated behind `--force-production` in production.
 *
 * No user is EVER created by this command, in any environment (design
 * override, ratified over the proposal's "generated admin credential" idea —
 * the demo lives in the organization's OWN existing account). `--create-org`
 * creates the organization (and nothing else) when it is missing, for local
 * bootstrap only; it is refused in production.
 */
final class DemoSeedCommand extends Command
{
    protected $signature = 'beai:demo-seed
        {--org= : Slug of the organization to seed into (required)}
        {--force-production : Required to write when APP_ENV=production}
        {--create-org : Create the organization (org only — never a user) if it does not exist; refused in production}';

    protected $description = 'Provision a rich, BARS-valid demo dataset into an existing organization.';

    public function handle(): int
    {
        $orgSlug = trim((string) $this->option('org'));

        if ($orgSlug === '') {
            $this->error('The --org option is required.');

            return self::FAILURE;
        }

        $isProduction = app()->environment('production');
        $forceProduction = (bool) $this->option('force-production');
        $createOrg = (bool) $this->option('create-org');

        if ($createOrg && $isProduction) {
            $this->error('--create-org is refused in production. Seed into an organization that already exists.');

            return self::FAILURE;
        }

        $organization = Organization::where('slug', $orgSlug)->first();

        if ($organization === null) {
            if (! $createOrg) {
                $this->error("Organization [{$orgSlug}] not found. Pass --create-org to create it (local only), or seed into an existing organization.");

                return self::FAILURE;
            }

            $organization = Organization::create(['name' => Str::headline($orgSlug), 'slug' => $orgSlug]);
            $this->info("Organization [{$orgSlug}] created (id={$organization->id}). No user was created.");
        }

        // Printed BEFORE any write, unconditionally — the consequence must
        // never be buried, whether the run proceeds or is about to be refused.
        $this->printFrameworkLockWarning();

        if ($isProduction && ! $forceProduction) {
            $this->error('Refusing to write into a PRODUCTION organization.');
            $this->line('Creating a demo project pins a FrameworkVersion (is_locked=true) via ProjectController::store — a CROSS-TENANT, PERMANENT effect once triggered through the backoffice UI.');
            $this->line('Pass --force-production to proceed anyway.');

            return self::FAILURE;
        }

        $this->printPreflightCensus($organization);
        $this->printAssessmentTypeGap();

        DemoDatasetValidator::assertValid();

        $gateResult = $this->checkCensusGate($organization);

        if ($gateResult !== null) {
            return $gateResult;
        }

        return $this->seed($organization);
    }

    /**
     * Delegates to DemoWriter, which is idempotent per row.
     */
    private function seed(Organization $organization): int
    {
        return app(DemoWriter::class)->write($this, $organization);
    }

    /**
     * The census gate (design D3): counts the FOUR marked roots
     * (`projects`, `participants`, `framework_versions`, `avatar_templates`)
     * against the fixture's expected counts.
     *
     * - All zero            → null (proceed — DemoWriter seeds everything)
     * - Exact match         → null (proceed — DemoWriter is idempotent per
     *                          row and writes nothing new; this is also how
     *                          a run interrupted between "participant
     *                          committed" and "its evaluation committed" is
     *                          completed: Evaluation is not one of the four
     *                          marked roots, so the shallow census still
     *                          matches and the writer finishes the missing
     *                          aggregate without ever touching the
     *                          participant's `status` column)
     * - Anything else       → refuse (FAILURE), print the diff, instruct
     *                          `beai:demo-teardown`
     *
     * @return int|null null to proceed; an exit code to return immediately
     */
    private function checkCensusGate(Organization $organization): ?int
    {
        $expected = DemoDatasetValidator::expectedCensus();

        $observed = [
            'projects' => Project::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('slug', 'like', DemoMarker::PREFIX.'%')
                ->count(),
            'participants' => Participant::where('organization_id', $organization->id)
                ->where('candidate_ref', 'like', DemoMarker::PREFIX.'%')
                ->count(),
            'framework_versions' => FrameworkVersion::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('version', 'like', DemoMarker::PREFIX.'%')
                ->count(),
            'avatar_templates' => AvatarTemplate::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('name', 'like', DemoMarker::PREFIX.'%')
                ->count(),
        ];

        $expectedShallow = [
            'projects' => $expected['projects'],
            'participants' => $expected['participants'],
            'framework_versions' => $expected['framework_versions'],
            'avatar_templates' => $expected['avatar_templates'],
        ];

        if (array_sum($observed) === 0) {
            return null;
        }

        if ($observed === $expectedShallow) {
            $this->info('Demo dataset already fully provisioned for this organization — verifying idempotently, no new rows expected.');

            return null;
        }

        $this->error('Refusing to seed: a PARTIAL demo dataset already exists for this organization (neither empty nor fully provisioned).');
        $this->table(
            ['Marked root', 'Expected', 'Observed'],
            collect($expectedShallow)->map(fn (int $expectedCount, string $key): array => [$key, $expectedCount, $observed[$key]])->values()->all(),
        );
        $this->line('Run `beai:demo-teardown` to remove the partial dataset, then re-run `beai:demo-seed`.');

        return self::FAILURE;
    }

    /**
     * Design D10: `potential` requires competencies ⊆ {MTG, LAT}
     * (`StoreProjectRequest.php:28`), and neither exists in the catalog
     * (`competencies.json` holds 18 `standard` codes only;
     * `FrameworkCatalogSeeder.php:318-324` records
     * `missing_potential_competency` for both, "pending expert authoring").
     * A `potential` demo project would trip
     * `ZeroCompetenciesInvariantException` rather than demonstrate anything —
     * named here so the gap is never silent.
     */
    private function printAssessmentTypeGap(): void
    {
        $this->line("Assessment-type gap: all demo projects are 'standard'. 'potential' is not seeded — MTG/LAT competencies are absent from the catalog (pending expert authoring, design D10).");
    }

    private function printFrameworkLockWarning(): void
    {
        $anyLocked = FrameworkVersion::withoutGlobalScopes()->where('is_locked', true)->exists();

        // The first sentence used to assert that creating a demo project pins
        // the version. It does not, and saying so contradicted the very next
        // line — an operator reading a warning that argues with itself learns
        // nothing except not to trust the warning.
        $this->line('This command does NOT lock any FrameworkVersion (design D8). Only ProjectController::store sets is_locked, i.e. a project created through the backoffice UI.');
        $this->warn('Framework-lock consequence, for when you do create one there: locking is CROSS-TENANT and PERMANENT — once any FrameworkVersion is locked, FrameworkCatalogSeeder becomes additive-only for EVERY tenant in this deployment.');
        $this->line('Deployment-wide lock state: '.($anyLocked
            ? 'a FrameworkVersion is ALREADY locked somewhere in this deployment.'
            : 'no FrameworkVersion is locked yet.'));
    }

    /**
     * Reported, never a refusal basis: `beai:demo-seed` exists to write
     * alongside real client data in the same organization.
     */
    private function printPreflightCensus(Organization $organization): void
    {
        $nonDemoProjects = Project::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('slug', 'not like', DemoMarker::PREFIX.'%')
            ->count();

        $nonDemoParticipants = Participant::where('organization_id', $organization->id)
            ->where('candidate_ref', 'not like', DemoMarker::PREFIX.'%')
            ->count();

        $userCount = User::where('organization_id', $organization->id)->count();

        $this->table(
            ['Pre-existing (non-demo)', 'Count'],
            [
                ['Projects', $nonDemoProjects],
                ['Participants', $nonDemoParticipants],
                ['Users', $userCount],
            ],
        );
    }
}
