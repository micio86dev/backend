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
 * hold only the text each case plants, so a hit is always attributable. The email
 * is searched too (reusable-link-visitor-identity): a row's default address is a
 * deterministic `row-{n}@example.test`, never a random factory address, so it can
 * never contain a term a case is looking for.
 *
 * REQ: Participants List Search Matches The External Reference
 *      (sdd/candidate-external-reference/spec/admin-read-api; the literal,
 *      case-insensitive matching is the standing "fix what you find" rule),
 *      A Visitor Is Found By The Email Or Name It Declared, The Former Address No
 *      Longer Resolves Anyone
 *      (sdd/reusable-link-visitor-identity/spec/reusable-interview-links and
 *      /spec/data-retention)
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Participant\PlaceholderEmail;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\Helpers\ReusableLinkFixtures as Fx;

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
 * @param  array<string, mixed>  $columns  candidate_ref, display_name, email and/or source
 */
function searchMatchRow(Project $project, array $columns): Participant
{
    static $sequence = 0;

    $participant = Participant::factory()->forProject($project)->create([
        'candidate_ref' => $columns['candidate_ref'],
        'display_name' => $columns['display_name'] ?? 'Anonymous',
        // Deterministic and free of `_`, `%` and `\`: a random factory address
        // could contain the very term a case searches for.
        'email' => $columns['email'] ?? 'row-'.++$sequence.'@example.test',
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
    searchMatchRow($project, ['candidate_ref' => 'in-email', 'email' => 'jo_hn@example.test']);

    expect(searchMatchRefs($this, $token, '_'))->toBe(['in-email', 'in-name', 'in-source', 'ref_under']);
});

test('q=% matches only text that contains a literal percent sign', function (): void {
    $org = Organization::factory()->create();
    $token = searchMatchToken($org);
    $project = searchMatchProject($org);

    searchMatchRow($project, ['candidate_ref' => 'plain']);
    searchMatchRow($project, ['candidate_ref' => 'in-ref-100%']);
    searchMatchRow($project, ['candidate_ref' => 'in-name', 'display_name' => 'Fifty % Person']);
    searchMatchRow($project, ['candidate_ref' => 'in-source', 'source' => '50%']);
    searchMatchRow($project, ['candidate_ref' => 'in-email', 'email' => '50%@example.test']);

    expect(searchMatchRefs($this, $token, '%'))->toBe(['in-email', 'in-name', 'in-ref-100%', 'in-source']);
});

test('q=\\ is a literal backslash, not an escape character', function (): void {
    $org = Organization::factory()->create();
    $token = searchMatchToken($org);
    $project = searchMatchProject($org);

    searchMatchRow($project, ['candidate_ref' => 'plain']);
    searchMatchRow($project, ['candidate_ref' => 'in-ref\\x']);
    searchMatchRow($project, ['candidate_ref' => 'in-source', 'source' => 'C:\\dir']);
    searchMatchRow($project, ['candidate_ref' => 'in-email', 'email' => 'dom\\user@example.test']);

    expect(searchMatchRefs($this, $token, '\\'))->toBe(['in-email', 'in-ref\\x', 'in-source']);
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
    searchMatchRow($project, ['candidate_ref' => 'by-email', 'email' => 'a%x@example.test']);
    searchMatchRow($project, ['candidate_ref' => 'decoy-email', 'email' => 'ax@example.test']);

    expect(searchMatchRefs($this, $token, 'a%x'))->toBe(['by-email', 'by-name', 'by-source', 'ref-a%x']);
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

// ─── The email is searched too (reusable-link-visitor-identity, api-5) ───────

test('q matches the email as a case-insensitive substring, however the address was stored', function (): void {
    $org = Organization::factory()->create();
    $token = searchMatchToken($org);
    $project = searchMatchProject($org);

    // The address stored mixed-case by an operator path, and the lower-case one.
    searchMatchRow($project, ['candidate_ref' => 'mixed-case', 'email' => 'Ada@Example.test']);
    searchMatchRow($project, ['candidate_ref' => 'lower-case', 'email' => 'ada@example.test']);
    searchMatchRow($project, ['candidate_ref' => 'someone-else', 'email' => 'bob@example.test']);

    expect(searchMatchRefs($this, $token, 'ADA@example'))->toBe(['lower-case', 'mixed-case']);
    expect(searchMatchRefs($this, $token, 'ada@example.test'))->toBe(['lower-case', 'mixed-case']);
    expect(searchMatchRefs($this, $token, 'bob@'))->toBe(['someone-else']);
    expect(searchMatchRefs($this, $token, 'nobody@'))->toBe([]);
});

test('matching an email never crosses the organization, in either direction', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $tokenA = searchMatchToken($orgA);
    $tokenB = searchMatchToken($orgB);
    $projectA = searchMatchProject($orgA);
    $projectB = searchMatchProject($orgB);

    // The same person, enrolled by two organisations: two rows that never see each other.
    searchMatchRow($projectA, ['candidate_ref' => 'a-ref', 'email' => 'ada@example.test']);
    searchMatchRow($projectB, ['candidate_ref' => 'b-ref', 'email' => 'ada@example.test']);

    // Two admins in one test process: the auth guard keeps the user of the last
    // login unless it is reset, which would answer both requests as organisation B.
    resetAuthGuardState();
    expect(searchMatchRefs($this, $tokenA, 'ada@example'))->toBe(['a-ref']);
    resetAuthGuardState();
    $this->flushHeaders();
    expect(searchMatchRefs($this, $tokenB, 'ada@example'))->toBe(['b-ref']);
});

test('a reusable-link visitor is found by the email and by the name it declared', function (): void {
    $world = Fx::redeemable();
    $token = searchMatchToken($world['org']);
    config([
        'reusable_links.redeem.per_ip_per_minute' => 1000,
        'reusable_links.redeem.per_link_per_hour' => 1000,
    ]);

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($world['token'], Fx::identity('ada.lovelace@example.com', 'Ada Lovelace')))->assertOk();
    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($world['token'], Fx::identity('grace.hopper@example.com', 'Grace Hopper')))->assertOk();
    $visitor = Fx::visitorsOf($world['link'])[0];

    expect(searchMatchRefs($this, $token, 'ada.lovelace@'))->toBe([$visitor->candidate_ref]);
    expect(searchMatchRefs($this, $token, 'Ada Lovelace'))->toBe([$visitor->candidate_ref]);
    expect(searchMatchRefs($this, $token, 'LOVELACE'))->toBe([$visitor->candidate_ref]);
});

test('a purged participant is not found by its former address, is found by its placeholder, and the address can be enrolled again', function (): void {
    $org = Organization::factory()->create();
    $token = searchMatchToken($org);
    $project = searchMatchProject($org);
    $old = searchMatchRow($project, ['candidate_ref' => 'ref-1', 'email' => 'ada@example.com']);
    DB::table('participants')->where('id', $old->id)->update(['created_at' => now()->subDays(90)]);

    config()->set('retention.enabled', true);
    config()->set('retention.days.participant_pii', 30);
    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    expect(searchMatchRefs($this, $token, 'ada@example.com'))->toBe([]);
    expect(searchMatchRefs($this, $token, PlaceholderEmail::forPurged('ref-1')))->toBe(['ref-1']);

    // The unique index no longer holds the address: the same person enrols again.
    searchMatchRow($project, ['candidate_ref' => 'ref-2', 'email' => 'ada@example.com']);

    expect(searchMatchRefs($this, $token, 'ada@example.com'))->toBe(['ref-2']);
});
