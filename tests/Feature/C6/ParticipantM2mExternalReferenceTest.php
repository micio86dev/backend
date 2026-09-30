<?php

declare(strict_types=1);

/**
 * POST /api/m2m/participants accepts and persists the optional external
 * reference (candidate-external-reference, slice A2), on both the immediate
 * path and the scheduled path (`scheduled_at` present).
 *
 * Only what reaches the database is asserted here. The response keys
 * (`external_id`, `source` always present, null when absent) arrive with the
 * `ParticipantEnrolmentResource` split in slice A3a, which extends this file.
 *
 * REQ: M2M Participant Create Accepts And Returns The External Reference,
 *      External Reference Validation Is One Shared Contract
 *      (sdd/candidate-external-reference/spec/participant-sso)
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ApiKeyGenerator;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\ExternalReferenceCases;

/**
 * @return array{client: ApiClient, key: string}
 */
function m2mExtRefClient(Organization $org): array
{
    $rawKey = ApiKeyGenerator::generate();
    $client = ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'is_active' => true,
        'abilities' => ['participants:create', 'participants:read'],
    ]);

    return ['client' => $client, 'key' => $rawKey];
}

function m2mExtRefProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active']);
    makeProjectInterviewable($project);

    return $project;
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function m2mExtRefBody(Project $project, array $extra = []): array
{
    return array_merge([
        'project_id' => $project->id,
        'candidate_ref' => 'm2m-ref-001',
        'display_name' => 'M2M Reference Candidate',
        'email' => uniqid('m2m-').'@example.test',
    ], $extra);
}

/**
 * @return array<string, string>
 */
function m2mExtRefScheduled(): array
{
    return ['scheduled_at' => now('UTC')->addMinutes(30)->toIso8601String()];
}

// ---------------------------------------------------------------------------
// Persisted on both paths
// ---------------------------------------------------------------------------

test('the immediate path persists exactly the values that were sent', function (?int $externalId, ?string $source): void {
    $org = Organization::factory()->create();
    $project = m2mExtRefProject($org);
    $m2m = m2mExtRefClient($org);

    $body = m2mExtRefBody($project);
    if ($externalId !== null) {
        $body['external_id'] = $externalId;
    }
    if ($source !== null) {
        $body['source'] = $source;
    }

    $this->withToken($m2m['key'])->postJson('/api/m2m/participants', $body)->assertStatus(201);

    $row = DB::table('participants')->where('project_id', $project->id)->where('candidate_ref', 'm2m-ref-001')->first();
    expect($row->external_id)->toBe($externalId)->and($row->source)->toBe($source);
})->with(fn () => ExternalReferenceCases::validCombinations());

test('the scheduled path persists exactly the values that were sent', function (?int $externalId, ?string $source): void {
    $org = Organization::factory()->create();
    $project = m2mExtRefProject($org);
    $m2m = m2mExtRefClient($org);

    $body = m2mExtRefBody($project, m2mExtRefScheduled());
    if ($externalId !== null) {
        $body['external_id'] = $externalId;
    }
    if ($source !== null) {
        $body['source'] = $source;
    }

    $this->withToken($m2m['key'])->postJson('/api/m2m/participants', $body)->assertStatus(201);

    $row = DB::table('participants')->where('project_id', $project->id)->where('candidate_ref', 'm2m-ref-001')->first();
    expect($row->external_id)->toBe($externalId)->and($row->source)->toBe($source);
    expect($row->scheduling_status)->toBe('pending');
})->with(fn () => ExternalReferenceCases::validCombinations());

test('an empty or whitespace-only source is stored as null, never as an empty string', function (string $blank): void {
    $org = Organization::factory()->create();
    $project = m2mExtRefProject($org);
    $m2m = m2mExtRefClient($org);

    $this->withToken($m2m['key'])->postJson('/api/m2m/participants', m2mExtRefBody($project, ['source' => $blank]))->assertStatus(201);
    $this->withToken($m2m['key'])->postJson('/api/m2m/participants', m2mExtRefBody($project, m2mExtRefScheduled() + [
        'candidate_ref' => 'm2m-ref-blank-scheduled',
        'source' => $blank,
    ]))->assertStatus(201);

    expect(DB::table('participants')->where('project_id', $project->id)->whereNotNull('source')->count())->toBe(0);
})->with(['empty' => [''], 'whitespace' => ['   ']]);

test('the boundary values are accepted: external_id 2^53-1 and a 180-character multibyte source', function (): void {
    $org = Organization::factory()->create();
    $project = m2mExtRefProject($org);
    $m2m = m2mExtRefClient($org);
    $source = str_repeat('é', 180);

    $this->withToken($m2m['key'])->postJson('/api/m2m/participants', m2mExtRefBody($project, [
        'external_id' => 9007199254740991,
        'source' => $source,
    ]))->assertStatus(201);

    $row = DB::table('participants')->where('candidate_ref', 'm2m-ref-001')->first();
    expect($row->external_id)->toBe(9007199254740991)->and($row->source)->toBe($source);
});

// ---------------------------------------------------------------------------
// Refusals: no side effect
// ---------------------------------------------------------------------------

test('an invalid reference is refused with 422 naming the field and writes no row', function (string $field, mixed $value): void {
    $org = Organization::factory()->create();
    $project = m2mExtRefProject($org);
    $m2m = m2mExtRefClient($org);

    $this->withToken($m2m['key'])->postJson('/api/m2m/participants', m2mExtRefBody($project, [$field => $value]))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);

    $this->withToken($m2m['key'])->postJson('/api/m2m/participants', m2mExtRefBody($project, m2mExtRefScheduled() + [$field => $value]))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);

    expect(Participant::where('project_id', $project->id)->count())->toBe(0);
})->with(fn () => ExternalReferenceCases::invalid());

test('a refused duplicate leaves the stored reference untouched', function (string $path, string $conflict): void {
    $org = Organization::factory()->create();
    $project = m2mExtRefProject($org);
    $m2m = m2mExtRefClient($org);

    $existing = Participant::factory()->forProject($project)->withExternalReference(4471, 'acme-ats')->create([
        'candidate_ref' => 'm2m-ref-existing',
        'email' => 'm2m-existing@example.test',
    ]);

    $identity = $conflict === 'duplicate_candidate_ref'
        ? ['candidate_ref' => 'm2m-ref-existing']
        : ['candidate_ref' => 'm2m-ref-different', 'email' => 'm2m-existing@example.test'];

    $body = m2mExtRefBody($project, [...$identity, 'external_id' => 9000, 'source' => 'other-ats']);
    if ($path === 'scheduled') {
        $body += m2mExtRefScheduled();
    }

    $this->withToken($m2m['key'])->postJson('/api/m2m/participants', $body)
        ->assertStatus(409)
        ->assertJson(['reason' => $conflict]);

    $stored = DB::table('participants')->where('id', $existing->id)->first();
    expect($stored->external_id)->toBe(4471)->and($stored->source)->toBe('acme-ats');
    expect(Participant::where('project_id', $project->id)->count())->toBe(1);
})->with([
    'immediate duplicate candidate_ref' => ['immediate', 'duplicate_candidate_ref'],
    'immediate duplicate email' => ['immediate', 'duplicate_email'],
    'scheduled duplicate candidate_ref' => ['scheduled', 'duplicate_candidate_ref'],
    'scheduled duplicate email' => ['scheduled', 'duplicate_email'],
]);

test('a project of another organization is not found and nothing is written', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $projectB = m2mExtRefProject($orgB);
    $m2mA = m2mExtRefClient($orgA);

    $this->withToken($m2mA['key'])->postJson('/api/m2m/participants', m2mExtRefBody($projectB, [
        'external_id' => 4471,
        'source' => 'acme-ats',
    ]))->assertStatus(404);

    expect(DB::table('participants')->where('project_id', $projectB->id)->count())->toBe(0);
});
