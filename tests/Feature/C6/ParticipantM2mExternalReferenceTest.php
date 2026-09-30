<?php

declare(strict_types=1);

/**
 * POST /api/m2m/participants accepts and persists the optional external
 * reference (candidate-external-reference, slice A2), on both the immediate
 * path and the scheduled path (`scheduled_at` present); slice A3a then asserts
 * the response side.
 *
 * Every M2M response that returns a participant (create on both paths, index,
 * show, reschedule, cancel) carries `external_id` (integer or null) and
 * `source` (string or null), always present: they are serialised by
 * `ParticipantEnrolmentResource`, the operator/integration twin of the
 * candidate resource.
 *
 * REQ: M2M Participant Create Accepts And Returns The External Reference,
 *      External Reference Validation Is One Shared Contract
 *      (sdd/candidate-external-reference/spec/participant-sso)
 */

use App\Enums\ParticipantSchedulingStatus;
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
        'abilities' => ['participants:create', 'participants:read', 'participants:schedule'],
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

// ---------------------------------------------------------------------------
// Responses: both keys always present, typed, null when absent
// ---------------------------------------------------------------------------

test('the create response carries both keys with the values that were sent, on both paths', function (string $path, ?int $externalId, ?string $source): void {
    $org = Organization::factory()->create();
    $project = m2mExtRefProject($org);
    $m2m = m2mExtRefClient($org);

    $body = m2mExtRefBody($project, $path === 'scheduled' ? m2mExtRefScheduled() : []);
    if ($externalId !== null) {
        $body['external_id'] = $externalId;
    }
    if ($source !== null) {
        $body['source'] = $source;
    }

    $response = $this->withToken($m2m['key'])->postJson('/api/m2m/participants', $body);

    $response->assertStatus(201);
    // Present even when null: a consumer must be able to tell "no reference"
    // from "a server that predates the field".
    expect($response->json())->toHaveKeys(['external_id', 'source']);
    expect($response->json('external_id'))->toBe($externalId);
    expect($response->json('source'))->toBe($source);
})->with(function (): array {
    $cases = [];
    foreach (['immediate', 'scheduled'] as $path) {
        foreach (ExternalReferenceCases::validCombinations() as $name => [$externalId, $source]) {
            $cases["{$path}: {$name}"] = [$path, $externalId, $source];
        }
    }

    return $cases;
});

test('the create response keeps the candidate-shaped fields alongside the reference', function (): void {
    $org = Organization::factory()->create();
    $project = m2mExtRefProject($org);
    $m2m = m2mExtRefClient($org);

    $response = $this->withToken($m2m['key'])->postJson('/api/m2m/participants', m2mExtRefBody($project, [
        'external_id' => 4471,
        'source' => 'acme-ats',
    ]));

    $response->assertStatus(201)
        ->assertJsonPath('candidate_ref', 'm2m-ref-001')
        ->assertJsonPath('status', 'in_attesa')
        ->assertJsonPath('project.id', $project->id);
    expect($response->json())->toHaveKeys(['id', 'display_name', 'branding', 'scheduled_at', 'scheduling_status']);
});

test('the index returns both keys on every row, null where the participant has no reference', function (): void {
    $org = Organization::factory()->create();
    $project = m2mExtRefProject($org);
    $m2m = m2mExtRefClient($org);

    Participant::factory()->forProject($project)->withExternalReference(4471, 'acme-ats')->create(['candidate_ref' => 'with-reference']);
    Participant::factory()->forProject($project)->create(['candidate_ref' => 'without-reference']);

    $response = $this->withToken($m2m['key'])->getJson('/api/m2m/participants');

    $response->assertOk();
    $rows = collect($response->json('data'))->keyBy('candidate_ref');
    expect($rows)->toHaveCount(2);
    expect($rows['with-reference'])->toHaveKeys(['external_id', 'source']);
    expect($rows['with-reference']['external_id'])->toBe(4471);
    expect($rows['with-reference']['source'])->toBe('acme-ats');
    expect($rows['without-reference'])->toHaveKeys(['external_id', 'source']);
    expect($rows['without-reference']['external_id'])->toBeNull();
    expect($rows['without-reference']['source'])->toBeNull();
});

test('the index never returns another organization\'s reference', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $projectB = m2mExtRefProject($orgB);
    Participant::factory()->forProject($projectB)->withExternalReference(4471, 'acme-ats')->create(['candidate_ref' => 'org-b-candidate']);
    $m2mA = m2mExtRefClient($orgA);

    $response = $this->withToken($m2mA['key'])->getJson('/api/m2m/participants');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
    expect($response->getContent())->not->toContain('acme-ats');
});

test('show returns both keys, with values or null', function (?int $externalId, ?string $source): void {
    $org = Organization::factory()->create();
    $project = m2mExtRefProject($org);
    $m2m = m2mExtRefClient($org);

    // Set the exact values: a half-set pair is a legitimate row (no pairing
    // rule), which the factory state's both-or-nothing defaults cannot express.
    $participant = Participant::factory()->forProject($project)->create();
    $participant->forceFill(['external_id' => $externalId, 'source' => $source])->save();

    $response = $this->withToken($m2m['key'])->getJson("/api/m2m/participants/{$participant->id}");

    // `show` returns the resource itself, so the body is wrapped in `data`
    // (unlike create/reschedule/cancel, which return the resource as raw JSON).
    $response->assertOk();
    expect($response->json('data'))->toHaveKeys(['external_id', 'source']);
    expect($response->json('data.external_id'))->toBe($externalId);
    expect($response->json('data.source'))->toBe($source);
})->with(fn () => ExternalReferenceCases::validCombinations());

test('reschedule and cancel responses carry both keys', function (): void {
    $org = Organization::factory()->create();
    $project = m2mExtRefProject($org);
    $m2m = m2mExtRefClient($org);

    $participant = Participant::factory()->forProject($project)->withExternalReference(4471, 'acme-ats')->create([
        'scheduled_at' => now('UTC')->addHours(2),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);

    $reschedule = $this->withToken($m2m['key'])->patchJson("/api/m2m/participants/{$participant->id}/schedule", [
        'scheduled_at' => now('UTC')->addHours(5)->toIso8601String(),
    ]);
    $reschedule->assertOk();
    expect($reschedule->json('external_id'))->toBe(4471);
    expect($reschedule->json('source'))->toBe('acme-ats');

    $cancel = $this->withToken($m2m['key'])->deleteJson("/api/m2m/participants/{$participant->id}/schedule");
    $cancel->assertOk();
    expect($cancel->json('external_id'))->toBe(4471);
    expect($cancel->json('source'))->toBe('acme-ats');
});

test('reschedule and cancel responses carry both keys as null when the participant has no reference', function (): void {
    $org = Organization::factory()->create();
    $project = m2mExtRefProject($org);
    $m2m = m2mExtRefClient($org);

    $participant = Participant::factory()->forProject($project)->create([
        'scheduled_at' => now('UTC')->addHours(2),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);

    $reschedule = $this->withToken($m2m['key'])->patchJson("/api/m2m/participants/{$participant->id}/schedule", [
        'scheduled_at' => now('UTC')->addHours(5)->toIso8601String(),
    ]);
    $reschedule->assertOk();
    expect($reschedule->json())->toHaveKeys(['external_id', 'source']);
    expect($reschedule->json('external_id'))->toBeNull();
    expect($reschedule->json('source'))->toBeNull();

    $cancel = $this->withToken($m2m['key'])->deleteJson("/api/m2m/participants/{$participant->id}/schedule");
    $cancel->assertOk();
    expect($cancel->json())->toHaveKeys(['external_id', 'source']);
    expect($cancel->json('external_id'))->toBeNull();
    expect($cancel->json('source'))->toBeNull();
});
