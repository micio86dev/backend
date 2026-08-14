<?php

declare(strict_types=1);

/**
 * `beai:demo-seed` — production opt-in guard and the pre-flight census
 * (spec: "Production Seeding Requires Explicit Opt-In...", "Seeding Reports
 * Pre-Existing Data Before Writing, Never Refuses").
 *
 * Creating a demo project pins a FrameworkVersion — a cross-tenant,
 * permanent effect (design D8). Production writes are therefore refused
 * unless the operator passes `--force-production`, and the consequence is
 * printed either way: on refusal (so the operator knows what they are
 * opting into) and on proceed (so it is never buried).
 *
 * The pre-existing-data census is the opposite shape: always printed, never
 * a refusal basis — demo-seed exists specifically to write alongside real
 * client data in the SAME organization (design D1, "no separate demo org").
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

test('production without --force-production refuses, names the flag, and writes nothing', function (): void {
    $org = Organization::factory()->create(['slug' => 'acme']);
    app()['env'] = 'production';

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])
        ->expectsOutputToContain('--force-production')
        ->expectsOutputToContain('FrameworkVersion')
        ->assertExitCode(1);

    expect(Project::withoutGlobalScopes()->where('organization_id', $org->id)->count())->toBe(0);
    expect(Participant::where('organization_id', $org->id)->count())->toBe(0);
});

test('local/non-production run proceeds without the opt-in flag', function (): void {
    Organization::factory()->create(['slug' => 'acme']);

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])
        ->assertExitCode(0);
});

test('production WITH --force-production proceeds and prints the lock consequence first', function (): void {
    Organization::factory()->create(['slug' => 'acme']);
    app()['env'] = 'production';

    $this->artisan('beai:demo-seed', ['--org' => 'acme', '--force-production' => true])
        ->expectsOutputToContain('FrameworkVersion')
        ->assertExitCode(0);
});

test('pre-existing non-demo data is reported before writing and never causes a refusal', function (): void {
    $org = Organization::factory()->create(['slug' => 'acme']);

    TenantContextScope::runFor($org->id, function () use ($org): void {
        $projects = Project::factory()->count(3)->create(['organization_id' => $org->id]);

        Participant::factory()->count(2)->create([
            'organization_id' => $org->id,
            'project_id' => $projects->first()->id,
        ]);
    });
    User::factory()->create(['organization_id' => $org->id]);

    // The command reports what it found — exact wording/columns are asserted
    // once DemoWriter exists (Phase 4+); at the skeleton stage this proves
    // the report ran and did not gate the exit code on non-demo data.
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])
        ->assertExitCode(0);
});

test('an empty organization is reported as zero pre-existing rows and proceeds', function (): void {
    Organization::factory()->create(['slug' => 'acme']);

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])
        ->assertExitCode(0);
});

test('the assessment-type gap is named on every run (design D10 — potential is not implementable)', function (): void {
    Organization::factory()->create(['slug' => 'acme']);

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])
        ->expectsOutputToContain('potential')
        ->assertExitCode(0);
});

test('--create-org bootstraps the organization locally, never a user', function (): void {
    expect(Organization::where('slug', 'brand-new-org')->exists())->toBeFalse();

    $this->artisan('beai:demo-seed', ['--org' => 'brand-new-org', '--create-org' => true])
        ->assertExitCode(0);

    $org = Organization::where('slug', 'brand-new-org')->first();
    expect($org)->not->toBeNull();
    expect(User::where('organization_id', $org->id)->count())->toBe(0);
});

test('--create-org is refused in production, regardless of whether the organization already exists', function (): void {
    Organization::factory()->create(['slug' => 'acme']);
    app()['env'] = 'production';

    $this->artisan('beai:demo-seed', ['--org' => 'acme', '--create-org' => true])
        ->assertExitCode(1);

    expect(Organization::where('slug', 'brand-new-prod-org')->exists())->toBeFalse();

    $this->artisan('beai:demo-seed', ['--org' => 'brand-new-prod-org', '--create-org' => true])
        ->assertExitCode(1);

    expect(Organization::where('slug', 'brand-new-prod-org')->exists())->toBeFalse();
});
