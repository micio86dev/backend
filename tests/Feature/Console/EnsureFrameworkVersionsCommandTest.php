<?php

declare(strict_types=1);

/**
 * `beai:ensure-framework-versions` backfills a default FrameworkVersion for
 * organizations created before `CreateOrganization` did it (owner report: a
 * client with no version cannot create a project).
 */

use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function ensureCmdVersionCount(Organization $org): int
{
    return FrameworkVersion::withoutGlobalScopes()->where('organization_id', $org->id)->count();
}

test('a dry run reports what it would create and writes nothing', function (): void {
    $org = Organization::factory()->create(['slug' => 'no-version']);

    $this->artisan('beai:ensure-framework-versions', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run] would create a default framework version for no-version')
        ->expectsOutputToContain('Would create 1 default framework version(s)')
        ->assertExitCode(0);

    expect(ensureCmdVersionCount($org))->toBe(0);
});

test('a real run fixes only organizations lacking a version and leaves the others untouched', function (): void {
    $lacking = Organization::factory()->create(['slug' => 'lacking']);
    $has = Organization::factory()->create(['slug' => 'has-one']);
    $existing = TenantContextScope::runFor($has->id, fn () => FrameworkVersion::factory()->create([
        'organization_id' => $has->id,
        'version' => '7.0.0',
    ]));

    $this->artisan('beai:ensure-framework-versions')
        ->expectsOutputToContain('created a default framework version for lacking')
        ->doesntExpectOutputToContain('for has-one')
        ->expectsOutputToContain('Created 1 default framework version(s)')
        ->assertExitCode(0);

    expect(ensureCmdVersionCount($lacking))->toBe(1);
    expect(FrameworkVersion::withoutGlobalScopes()->where('organization_id', $lacking->id)->first()->revision_id)
        ->toBe(FrameworkCatalogRevision::latestPublished()->id);
    expect(ensureCmdVersionCount($has))->toBe(1);
    expect(FrameworkVersion::withoutGlobalScopes()->find($existing->id)->version)->toBe('7.0.0');
});

test('a second run is a no-op', function (): void {
    $org = Organization::factory()->create();

    $this->artisan('beai:ensure-framework-versions')->assertExitCode(0);
    $this->artisan('beai:ensure-framework-versions')
        ->expectsOutputToContain('Created 0 default framework version(s)')
        ->assertExitCode(0);

    expect(ensureCmdVersionCount($org))->toBe(1);
});

test('--org limits the scope to one organization', function (): void {
    $target = Organization::factory()->create(['slug' => 'target']);
    $bystander = Organization::factory()->create(['slug' => 'bystander']);

    $this->artisan('beai:ensure-framework-versions', ['--org' => 'target'])->assertExitCode(0);

    expect(ensureCmdVersionCount($target))->toBe(1);
    expect(ensureCmdVersionCount($bystander))->toBe(0);
});

test('an unknown --org slug fails', function (): void {
    $this->artisan('beai:ensure-framework-versions', ['--org' => 'nope'])
        ->expectsOutputToContain("No organization with slug 'nope'")
        ->assertExitCode(1);
});

test('with no published revision it warns, creates nothing and still succeeds', function (): void {
    $org = Organization::factory()->create();
    // Bypasses the immutability triggers for this transaction only, without
    // the table-level lock of DISABLE TRIGGER (which would block parallel workers).
    DB::statement('SET LOCAL session_replication_role = replica');
    DB::table('framework_catalog_revisions')->update(['state' => 'draft']);
    DB::statement('SET LOCAL session_replication_role = DEFAULT');

    $this->artisan('beai:ensure-framework-versions')
        ->expectsOutputToContain('no published catalogue revision')
        ->assertExitCode(0);

    expect(ensureCmdVersionCount($org))->toBe(0);
});
