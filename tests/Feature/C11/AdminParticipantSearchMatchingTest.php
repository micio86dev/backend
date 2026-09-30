<?php

declare(strict_types=1);

/**
 * How the admin participant list `q` parameter MATCHES text
 * (`GET /api/participants?q=`): case-insensitively on every text column, and
 * with the LIKE metacharacters `%`, `_` and `\` taken literally.
 *
 * Both were wrong before this file. `candidate_ref` and `display_name` used a
 * case-sensitive `like`, so `maria` did not find "Maria Rossi" while the newer
 * `source` branch did find "Maria" (it is `ilike`). And the term was spliced
 * into the pattern unescaped, so a search for `%` matched EVERY row and `_`
 * matched every row with at least one character — an operator typing an
 * underscore out of a candidate reference got the whole organization back.
 *
 * Every row is labelled through its `candidate_ref`, and the searched columns
 * hold only the text each case plants, so a hit is always attributable.
 *
 * REQ: Participants List Search Matches The External Reference
 *      (sdd/candidate-external-reference/spec/admin-read-api; the literal,
 *      case-insensitive matching is the standing "fix what you find" rule)
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function searchMatchToken(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]));

    return auth('api')->login($user);
}

function searchMatchProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create([
        'framework_version_id' => FrameworkVersion::factory()->create(['organization_id' => $org->id])->id,
    ]);
}

/**
 * @param  array<string, mixed>  $columns  candidate_ref, display_name and/or source
 */
function searchMatchRow(Project $project, array $columns): Participant
{
    $participant = Participant::factory()->forProject($project)->create([
        'candidate_ref' => $columns['candidate_ref'],
        'display_name' => $columns['display_name'] ?? 'Anonymous',
    ]);
    $participant->forceFill(['source' => $columns['source'] ?? null])->save();

    return $participant;
}

/**
 * The candidate_ref of every row a search returned, sorted.
 *
 * @return list<string>
 */
function searchMatchRefs(mixed $test, string $token, string $term): array
{
    $response = $test->withToken($token)->getJson('/api/participants?q='.rawurlencode($term));
    $response->assertOk();

    $refs = $response->json('data.*.candidate_ref') ?? [];
    sort($refs);

    return $refs;
}

test('q matches candidate_ref and display_name regardless of case', function (): void {
    $org = Organization::factory()->create();
    $token = searchMatchToken($org);
    $project = searchMatchProject($org);

    searchMatchRow($project, ['candidate_ref' => 'EXT-ABC-001']);
    searchMatchRow($project, ['candidate_ref' => 'unrelated', 'display_name' => 'Maria Rossi']);
    searchMatchRow($project, ['candidate_ref' => 'other', 'display_name' => 'Luca Bianchi']);

    expect(searchMatchRefs($this, $token, 'ext-abc'))->toBe(['EXT-ABC-001']);
    expect(searchMatchRefs($this, $token, 'EXT-abc'))->toBe(['EXT-ABC-001']);
    expect(searchMatchRefs($this, $token, 'maria'))->toBe(['unrelated']);
    expect(searchMatchRefs($this, $token, 'ROSSI'))->toBe(['unrelated']);
});

test('q=_ matches only text that contains a literal underscore', function (): void {
    $org = Organization::factory()->create();
    $token = searchMatchToken($org);
    $project = searchMatchProject($org);

    searchMatchRow($project, ['candidate_ref' => 'plain']);
    searchMatchRow($project, ['candidate_ref' => 'ref_under']);
    searchMatchRow($project, ['candidate_ref' => 'in-name', 'display_name' => 'Jo_hn']);
    searchMatchRow($project, ['candidate_ref' => 'in-source', 'source' => 'job_4']);

    expect(searchMatchRefs($this, $token, '_'))->toBe(['in-name', 'in-source', 'ref_under']);
});

test('q=% matches only text that contains a literal percent sign', function (): void {
    $org = Organization::factory()->create();
    $token = searchMatchToken($org);
    $project = searchMatchProject($org);

    searchMatchRow($project, ['candidate_ref' => 'plain']);
    searchMatchRow($project, ['candidate_ref' => 'in-ref-100%']);
    searchMatchRow($project, ['candidate_ref' => 'in-name', 'display_name' => 'Fifty % Person']);
    searchMatchRow($project, ['candidate_ref' => 'in-source', 'source' => '50%']);

    expect(searchMatchRefs($this, $token, '%'))->toBe(['in-name', 'in-ref-100%', 'in-source']);
});

test('q=\\ is a literal backslash, not an escape character', function (): void {
    $org = Organization::factory()->create();
    $token = searchMatchToken($org);
    $project = searchMatchProject($org);

    searchMatchRow($project, ['candidate_ref' => 'plain']);
    searchMatchRow($project, ['candidate_ref' => 'in-ref\\x']);
    searchMatchRow($project, ['candidate_ref' => 'in-source', 'source' => 'C:\\dir']);

    expect(searchMatchRefs($this, $token, '\\'))->toBe(['in-ref\\x', 'in-source']);
    // A backslash before a wildcard must not turn that wildcard back on.
    expect(searchMatchRefs($this, $token, '\\%'))->toBe([]);
});

test('an underscore is one literal character, never "any character"', function (): void {
    $org = Organization::factory()->create();
    $token = searchMatchToken($org);
    $project = searchMatchProject($org);

    searchMatchRow($project, ['candidate_ref' => 'abc']);
    searchMatchRow($project, ['candidate_ref' => 'a_c']);
    searchMatchRow($project, ['candidate_ref' => 'by-source', 'source' => 'abc']);

    expect(searchMatchRefs($this, $token, 'a_c'))->toBe(['a_c']);
});

test('a wildcard in the middle of a term is literal on every searched column', function (): void {
    $org = Organization::factory()->create();
    $token = searchMatchToken($org);
    $project = searchMatchProject($org);

    searchMatchRow($project, ['candidate_ref' => 'ref-ax', 'display_name' => 'Anonymous']);
    searchMatchRow($project, ['candidate_ref' => 'ref-a%x', 'display_name' => 'Anonymous']);
    searchMatchRow($project, ['candidate_ref' => 'by-name', 'display_name' => 'a%x']);
    searchMatchRow($project, ['candidate_ref' => 'by-source', 'source' => 'a%x']);
    searchMatchRow($project, ['candidate_ref' => 'decoy-source', 'source' => 'ax']);

    expect(searchMatchRefs($this, $token, 'a%x'))->toBe(['by-name', 'by-source', 'ref-a%x']);
});

test('matching stays inside the organization even for a bare wildcard', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $token = searchMatchToken($orgA);

    searchMatchRow(searchMatchProject($orgA), ['candidate_ref' => 'own_ref']);
    searchMatchRow(searchMatchProject($orgB), ['candidate_ref' => 'foreign_ref']);

    // The unescaped `%` used to match every row; the org scope must hold either way.
    expect(searchMatchRefs($this, $token, '_'))->toBe(['own_ref']);
    expect(searchMatchRefs($this, $token, '%'))->toBe([]);
});
