<?php

declare(strict_types=1);

/**
 * POST /api/entry-links accepts the optional external reference
 * (candidate-external-reference, slice A2).
 *
 * - IMMEDIATE path (no `scheduled_at`): no participant row exists at mint time;
 *   the values travel in the sso-link token as claims (only when present) and
 *   the exchange persists them.
 * - SCHEDULED path (`scheduled_at`): the row is created eagerly with the
 *   values persisted. The 201 body's new keys are asserted in slice A3a, when
 *   the response resource is split; here only the database is asserted.
 *
 * Both paths validate through the shared `ExternalReference` rules, before any
 * side effect: a refused request mints no token and writes no row.
 *
 * REQ: Operator Entry Link Persists The External Reference,
 *      External Reference Validation Is One Shared Contract,
 *      Operator-Facing Entry Link Mint Endpoint
 *      (sdd/candidate-external-reference/spec/participant-sso)
 */

use App\Jobs\SendCandidateInvitationJob;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\ExternalReferenceCases;

function extRefEntryProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create([
        'framework_version_id' => FrameworkVersion::factory()->create(['organization_id' => $org->id])->id,
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'goes_live_at' => null,
        'deadline_at' => null,
    ]);

    makeProjectInterviewable($project);

    return $project;
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function extRefEntryBody(Project $project, array $extra = []): array
{
    return array_merge([
        'project_id' => $project->id,
        'candidate_ref' => 'entry-ref-001',
        'display_name' => 'Entry Reference Candidate',
        'email' => uniqid('entry-').'@example.test',
        'lang' => 'en',
    ], $extra);
}

/**
 * @return array<string, mixed>
 */
function extRefEntryScheduled(array $extra = []): array
{
    return array_merge(['scheduled_at' => now('UTC')->addMinutes(30)->toIso8601String()], $extra);
}

/**
 * The claims of the sso-link embedded in an `entry_url`, read straight from
 * the token's payload segment (see SsoLinkExternalReferenceTest for why not
 * through JWTAuth).
 *
 * @return array<string, mixed>
 */
function extRefEntryClaims(string $entryUrl): array
{
    $token = basename((string) parse_url($entryUrl, PHP_URL_PATH));
    $segment = explode('.', $token)[1];

    return json_decode((string) base64_decode(strtr($segment, '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    // The immediate path dispatches the invitation email job; a queued mail is
    // not what these tests are about.
    Bus::fake();
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
});

// ---------------------------------------------------------------------------
// Immediate path: claims in the token, no row until the exchange
// ---------------------------------------------------------------------------

test('the immediate path mints a token carrying exactly the claims that were sent', function (?int $externalId, ?string $source): void {
    $org = Organization::factory()->create();
    $project = extRefEntryProject($org);
    $token = authTokenForRole($org, 'operator');

    $body = extRefEntryBody($project);
    if ($externalId !== null) {
        $body['external_id'] = $externalId;
    }
    if ($source !== null) {
        $body['source'] = $source;
    }

    $response = $this->withToken($token)->postJson('/api/entry-links', $body);

    $response->assertStatus(201)->assertJsonStructure(['entry_url', 'expires_at', 'email_sent']);
    $claims = extRefEntryClaims($response->json('entry_url'));

    if ($externalId === null) {
        expect($claims)->not->toHaveKey('external_id');
    } else {
        expect($claims['external_id'])->toBeInt()->toBe($externalId);
    }

    if ($source === null) {
        expect($claims)->not->toHaveKey('source');
    } else {
        expect($claims['source'])->toBe($source);
    }

    // The exchange writes the row, never the mint.
    expect(Participant::where('project_id', $project->id)->count())->toBe(0);
})->with(fn () => ExternalReferenceCases::validCombinations());

test('an empty or whitespace-only source is treated as absent', function (string $blank): void {
    $org = Organization::factory()->create();
    $project = extRefEntryProject($org);
    $token = authTokenForRole($org, 'operator');

    $immediate = $this->withToken($token)->postJson('/api/entry-links', extRefEntryBody($project, ['source' => $blank]));
    $immediate->assertStatus(201);
    expect(extRefEntryClaims($immediate->json('entry_url')))->not->toHaveKey('source');

    $this->withToken($token)->postJson('/api/entry-links', extRefEntryBody($project, extRefEntryScheduled([
        'candidate_ref' => 'entry-ref-blank-scheduled',
        'source' => $blank,
    ])))->assertStatus(201);

    expect(DB::table('participants')->where('candidate_ref', 'entry-ref-blank-scheduled')->value('source'))->toBeNull();
})->with(['empty' => [''], 'whitespace' => ['   ']]);

// ---------------------------------------------------------------------------
// Scheduled path: persisted on the eagerly created row
// ---------------------------------------------------------------------------

test('the scheduled path persists exactly the values that were sent', function (?int $externalId, ?string $source): void {
    $org = Organization::factory()->create();
    $project = extRefEntryProject($org);
    $token = authTokenForRole($org, 'operator');

    $body = extRefEntryBody($project, extRefEntryScheduled());
    if ($externalId !== null) {
        $body['external_id'] = $externalId;
    }
    if ($source !== null) {
        $body['source'] = $source;
    }

    $this->withToken($token)->postJson('/api/entry-links', $body)->assertStatus(201);

    $row = DB::table('participants')->where('project_id', $project->id)->where('candidate_ref', 'entry-ref-001')->first();
    expect($row->external_id)->toBe($externalId)->and($row->source)->toBe($source);
    Bus::assertNotDispatched(SendCandidateInvitationJob::class);
})->with(fn () => ExternalReferenceCases::validCombinations());

test('the boundary values are accepted on both paths: external_id 2^53-1 and a 180-character multibyte source', function (): void {
    $org = Organization::factory()->create();
    $project = extRefEntryProject($org);
    $token = authTokenForRole($org, 'operator');
    $source = str_repeat('é', 180);
    $reference = ['external_id' => 9007199254740991, 'source' => $source];

    $immediate = $this->withToken($token)->postJson('/api/entry-links', extRefEntryBody($project, $reference));
    $immediate->assertStatus(201);
    expect(extRefEntryClaims($immediate->json('entry_url')))->toMatchArray($reference);

    $this->withToken($token)->postJson('/api/entry-links', extRefEntryBody($project, extRefEntryScheduled([
        'candidate_ref' => 'entry-ref-edge-scheduled',
        ...$reference,
    ])))->assertStatus(201);

    $row = DB::table('participants')->where('candidate_ref', 'entry-ref-edge-scheduled')->first();
    expect($row->external_id)->toBe(9007199254740991)->and($row->source)->toBe($source);
});

// ---------------------------------------------------------------------------
// Refusals: no side effect
// ---------------------------------------------------------------------------

test('an invalid reference is refused with 422 naming the field, with no token and no row', function (string $field, mixed $value): void {
    $org = Organization::factory()->create();
    $project = extRefEntryProject($org);
    $token = authTokenForRole($org, 'operator');

    $immediate = $this->withToken($token)->postJson('/api/entry-links', extRefEntryBody($project, [$field => $value]));
    $immediate->assertStatus(422)->assertJsonValidationErrors([$field]);
    expect($immediate->json())->not->toHaveKey('entry_url');

    $scheduled = $this->withToken($token)->postJson('/api/entry-links', extRefEntryBody($project, extRefEntryScheduled([$field => $value])));
    $scheduled->assertStatus(422)->assertJsonValidationErrors([$field]);

    expect(Participant::where('project_id', $project->id)->count())->toBe(0);
    Bus::assertNotDispatched(SendCandidateInvitationJob::class);
})->with(fn () => ExternalReferenceCases::invalid());

test('a viewer is denied with 403 even when the body carries a reference', function (): void {
    $org = Organization::factory()->create();
    $project = extRefEntryProject($org);
    $token = authTokenForRole($org, 'viewer');

    $response = $this->withToken($token)->postJson('/api/entry-links', extRefEntryBody($project, [
        'external_id' => 4471,
        'source' => 'acme-ats',
    ]));

    $response->assertStatus(403);
    expect($response->json())->not->toHaveKey('entry_url');
});

test('a project of another organization is not found and nothing is written', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $projectB = extRefEntryProject($orgB);
    $tokenA = authTokenForRole($orgA, 'operator');

    $this->withToken($tokenA)->postJson('/api/entry-links', extRefEntryBody($projectB, extRefEntryScheduled([
        'external_id' => 4471,
        'source' => 'acme-ats',
    ])))->assertStatus(404);

    expect(DB::table('participants')->where('project_id', $projectB->id)->count())->toBe(0);
});

test('a completed or failed participant still refuses the mint with 409 and keeps the stored reference', function (string $status): void {
    $org = Organization::factory()->create();
    $project = extRefEntryProject($org);
    $token = authTokenForRole($org, 'operator');

    $participant = Participant::factory()->forProject($project)->withExternalReference(4471, 'acme-ats')->create([
        'candidate_ref' => 'entry-ref-terminal',
    ]);
    DB::table('participants')->where('id', $participant->id)->update(['status' => $status]);

    $response = $this->withToken($token)->postJson('/api/entry-links', extRefEntryBody($project, [
        'candidate_ref' => 'entry-ref-terminal',
        'external_id' => 9000,
        'source' => 'other-ats',
    ]));

    $response->assertStatus(409);
    expect($response->json())->not->toHaveKey('entry_url');

    $stored = DB::table('participants')->where('id', $participant->id)->first();
    expect($stored->external_id)->toBe(4471)->and($stored->source)->toBe('acme-ats');
})->with(['completato', 'errore']);

test('a duplicate candidate_ref or email on the scheduled path is refused and leaves the stored reference untouched', function (string $conflict): void {
    $org = Organization::factory()->create();
    $project = extRefEntryProject($org);
    $token = authTokenForRole($org, 'operator');

    $existing = Participant::factory()->forProject($project)->withExternalReference(4471, 'acme-ats')->create([
        'candidate_ref' => 'entry-ref-existing',
        'email' => 'entry-existing@example.test',
    ]);

    $body = $conflict === 'duplicate_candidate_ref'
        ? ['candidate_ref' => 'entry-ref-existing']
        : ['candidate_ref' => 'entry-ref-different', 'email' => 'entry-existing@example.test'];

    $response = $this->withToken($token)->postJson('/api/entry-links', extRefEntryBody($project, extRefEntryScheduled([
        ...$body,
        'external_id' => 9000,
        'source' => 'other-ats',
    ])));

    $response->assertStatus(409)->assertJson(['reason' => $conflict]);

    $stored = DB::table('participants')->where('id', $existing->id)->first();
    expect($stored->external_id)->toBe(4471)->and($stored->source)->toBe('acme-ats');
    expect(Participant::where('project_id', $project->id)->count())->toBe(1);
})->with(['duplicate_candidate_ref', 'duplicate_email']);

// ---------------------------------------------------------------------------
// The scheduled sweep: no reference on its link, the eager row keeps its own
// ---------------------------------------------------------------------------

test('the sweep mints its link with no reference claims and the exchange keeps the values the scheduled row already holds', function (): void {
    $org = Organization::factory()->create();
    $project = extRefEntryProject($org);
    $operator = authTokenForRole($org, 'operator');

    $this->withToken($operator)->postJson('/api/entry-links', extRefEntryBody($project, extRefEntryScheduled([
        'candidate_ref' => 'entry-ref-sweep',
        'external_id' => 4471,
        'source' => 'acme-ats',
    ])))->assertStatus(201);

    // Bring the start time forward: the sweep only acts on rows that are due.
    DB::table('participants')->where('candidate_ref', 'entry-ref-sweep')->update(['scheduled_at' => now()->subMinute()]);

    // The sweep is a platform-wide command: it runs with no ambient tenant.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(null);
    $resolver->setBypass(false);
    Artisan::call('beai:dispatch-scheduled-invitations');

    $jobs = Bus::dispatched(SendCandidateInvitationJob::class);
    expect($jobs)->toHaveCount(1);
    $entryUrl = (new ReflectionProperty($jobs->first(), 'entryUrl'))->getValue($jobs->first());

    // mint() is still called positionally with no reference: the link carries
    // no reference claims at all.
    $claims = extRefEntryClaims($entryUrl);
    expect($claims)->not->toHaveKey('external_id')->and($claims)->not->toHaveKey('source');

    $this->getJson('/api/sso/exchange?token='.basename((string) parse_url($entryUrl, PHP_URL_PATH)))->assertOk();

    $row = DB::table('participants')->where('candidate_ref', 'entry-ref-sweep')->first();
    expect($row->external_id)->toBe(4471)->and($row->source)->toBe('acme-ats');
});
