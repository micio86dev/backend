<?php

declare(strict_types=1);

/**
 * GET /api/sso/exchange persists the optional external reference
 * (candidate-external-reference, slice A2).
 *
 * The exchange upsert writes `external_id` and `source` from the sso-link
 * claims with preserve-on-absent semantics: per column, a PRESENT claim
 * overwrites the stored value and an ABSENT claim keeps it
 * (`COALESCE(EXCLUDED.x, participants.x)`). Rows past `in_attesa` are
 * untouched by the statement's own WHERE guard.
 *
 * Design decision (AD-5, supersedes the spec's 401 scenario): a malformed
 * claim is NARROWED TO NULL and the exchange still succeeds. The token is
 * HS256-signed by our own minter, and a candidate standing at an interview
 * door must never be turned away over optional data.
 *
 * Tokens are forged here with `extRefForgeSsoLink()` rather than through
 * `CandidateTokenFactory::mintSsoLink()` so these tests exercise the exchange
 * in isolation, and so a claim of ANY type (a string "abc", a float, an
 * array) can be put in the token exactly as written.
 *
 * REQ: Exchange Persists The External Reference With Preserve-On-Absent
 *      Semantics, Surfaces That Never Carry The External Reference
 *      (sdd/candidate-external-reference/spec/participant-sso)
 */

use App\Enums\ParticipantSchedulingStatus;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\ExternalReferenceCases;
use Tymon\JWTAuth\JWTAuth as JwtAuthManager;

// The first exchange of a new candidate dispatches `ParticipantCreated`, whose
// listener records a webhook delivery and (under QUEUE_CONNECTION=sync) would
// make a real HTTP call. Same pairing the C10 seam tests document.
beforeEach(function (): void {
    Queue::fake();
    Http::fake();
});

/**
 * @param  array<string, mixed>  $attrs
 */
function extRefExchangeProject(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(array_merge([
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'goes_live_at' => null,
        'deadline_at' => null,
    ], $attrs));

    makeProjectInterviewable($project);

    return $project;
}

/**
 * Forge a signed sso-link JWT carrying exactly the claims the minter would,
 * plus `$extraClaims` written verbatim (any type).
 *
 * @param  array<string, mixed>  $extraClaims
 */
function extRefForgeSsoLink(Project $project, string $ref, array $extraClaims = []): string
{
    $jwt = app(JwtAuthManager::class);
    $jwt->factory()->setTTL(30);

    $payload = $jwt->factory()->customClaims(array_merge([
        'sub' => $ref,
        'typ' => 'sso-link',
        'candidate_ref' => $ref,
        'display_name' => 'Reference Candidate',
        'email' => uniqid('cand-').'@example.test',
        'project_id' => $project->id,
        'org_id' => $project->organization_id,
        'role_code' => $project->role_code,
        'lang' => 'en',
    ], $extraClaims))->make();

    $token = $jwt->manager()->encode($payload)->get();

    // tymon's factory is a container singleton that ACCUMULATES custom claims
    // across make() calls. Forging here must not leak `external_id`/`source`
    // into the candidate JWT the exchange mints later in the same test.
    $jwt->factory()->emptyClaims();

    return $token;
}

/**
 * @return array{external_id: int|null, source: string|null}
 */
function extRefStored(Project $project, string $ref): array
{
    $row = DB::table('participants')
        ->where('project_id', $project->id)
        ->where('candidate_ref', $ref)
        ->first(['external_id', 'source']);

    return ['external_id' => $row->external_id, 'source' => $row->source];
}

/**
 * Every array key at any depth.
 *
 * @param  array<array-key, mixed>  $data
 * @return list<string>
 */
function extRefAllKeys(array $data): array
{
    $keys = [];
    foreach ($data as $key => $value) {
        $keys[] = (string) $key;
        if (is_array($value)) {
            $keys = array_merge($keys, extRefAllKeys($value));
        }
    }

    return $keys;
}

// ---------------------------------------------------------------------------
// Insert
// ---------------------------------------------------------------------------

test('the first exchange stores exactly the claims the link carried', function (?int $externalId, ?string $source): void {
    $org = Organization::factory()->create();
    $project = extRefExchangeProject($org);

    $claims = array_filter(
        ['external_id' => $externalId, 'source' => $source],
        static fn (mixed $value): bool => $value !== null,
    );

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-insert', $claims))->assertOk();

    expect(extRefStored($project, 'ref-insert'))->toBe(['external_id' => $externalId, 'source' => $source]);
})->with(fn () => ExternalReferenceCases::validCombinations());

test('a pre-change token with no reference claims leaves both columns null', function (): void {
    $org = Organization::factory()->create();
    $project = extRefExchangeProject($org);

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-legacy'))->assertOk();

    expect(extRefStored($project, 'ref-legacy'))->toBe(['external_id' => null, 'source' => null]);
});

// ---------------------------------------------------------------------------
// Re-issue while in_attesa: preserve on absent, overwrite on present
// ---------------------------------------------------------------------------

test('a re-issue without the claims preserves the stored reference', function (): void {
    $org = Organization::factory()->create();
    $project = extRefExchangeProject($org);

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-reissue', [
        'external_id' => 4471,
        'source' => 'acme-ats',
    ]))->assertOk();

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-reissue'))->assertOk();

    expect(extRefStored($project, 'ref-reissue'))->toBe(['external_id' => 4471, 'source' => 'acme-ats']);
    expect(Participant::where('project_id', $project->id)->where('candidate_ref', 'ref-reissue')->count())->toBe(1);
});

test('a re-issue with the claims overwrites the stored reference', function (): void {
    $org = Organization::factory()->create();
    $project = extRefExchangeProject($org);

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-overwrite', [
        'external_id' => 4471,
        'source' => 'acme-ats',
    ]))->assertOk();

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-overwrite', [
        'external_id' => 9000,
        'source' => 'other-ats',
    ]))->assertOk();

    expect(extRefStored($project, 'ref-overwrite'))->toBe(['external_id' => 9000, 'source' => 'other-ats']);
    expect(Participant::where('project_id', $project->id)->where('candidate_ref', 'ref-overwrite')->count())->toBe(1);
});

test('a re-issue carrying only source overwrites only source', function (): void {
    $org = Organization::factory()->create();
    $project = extRefExchangeProject($org);

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-partial-source', [
        'external_id' => 4471,
        'source' => 'acme-ats',
    ]))->assertOk();

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-partial-source', [
        'source' => 'other-ats',
    ]))->assertOk();

    expect(extRefStored($project, 'ref-partial-source'))->toBe(['external_id' => 4471, 'source' => 'other-ats']);
});

test('a re-issue carrying only external_id overwrites only external_id', function (): void {
    $org = Organization::factory()->create();
    $project = extRefExchangeProject($org);

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-partial-id', [
        'external_id' => 4471,
        'source' => 'acme-ats',
    ]))->assertOk();

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-partial-id', [
        'external_id' => 9000,
    ]))->assertOk();

    expect(extRefStored($project, 'ref-partial-id'))->toBe(['external_id' => 9000, 'source' => 'acme-ats']);
});

test('two links for the same candidate carrying the same reference still yield one row with it', function (): void {
    $org = Organization::factory()->create();
    $project = extRefExchangeProject($org);
    $claims = ['external_id' => 4471, 'source' => 'acme-ats'];

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-twice', $claims))->assertOk();
    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-twice', $claims))->assertOk();

    expect(Participant::where('project_id', $project->id)->where('candidate_ref', 'ref-twice')->count())->toBe(1);
    expect(extRefStored($project, 'ref-twice'))->toBe(['external_id' => 4471, 'source' => 'acme-ats']);
});

test('a scheduled row reached by the sweep keeps its stored reference when the minted link carries none', function (): void {
    $org = Organization::factory()->create();
    $project = extRefExchangeProject($org);

    // The scheduled path writes the row (and its reference) eagerly; the sweep
    // later mints a link with NO reference (it calls mint() positionally), so
    // the exchange must not erase what the eager row holds.
    Participant::factory()->forProject($project)->withExternalReference(4471, 'acme-ats')->create([
        'candidate_ref' => 'ref-scheduled',
        'scheduled_at' => now()->subHour(),
        'scheduling_status' => ParticipantSchedulingStatus::Started,
    ]);

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-scheduled'))->assertOk();

    expect(extRefStored($project, 'ref-scheduled'))->toBe(['external_id' => 4471, 'source' => 'acme-ats']);
});

// ---------------------------------------------------------------------------
// Rows past in_attesa are untouched
// ---------------------------------------------------------------------------

test('a participant past in_attesa is refused with 403 and its stored reference is untouched', function (string $status): void {
    $org = Organization::factory()->create();
    $project = extRefExchangeProject($org);

    Participant::factory()->forProject($project)->withExternalReference(4471, 'acme-ats')->create([
        'candidate_ref' => 'ref-past',
    ]);
    DB::table('participants')->where('candidate_ref', 'ref-past')->update(['status' => $status]);

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-past', [
        'external_id' => 9000,
        'source' => 'other-ats',
    ]))->assertForbidden();

    expect(extRefStored($project, 'ref-past'))->toBe(['external_id' => 4471, 'source' => 'acme-ats']);
})->with(['in_corso', 'in_valutazione', 'completato', 'errore']);

// ---------------------------------------------------------------------------
// Malformed claims: narrowed to null, the exchange never fails
// ---------------------------------------------------------------------------

/**
 * One malformed claim each, the other absent. `expected` is what a FRESH row
 * stores; on an existing row the stored value is preserved instead.
 *
 * @return array<string, array{0: array<string, mixed>}>
 */
function extRefMalformedClaims(): array
{
    return [
        'zero external_id' => [['external_id' => 0]],
        'negative external_id' => [['external_id' => -5]],
        'external_id above the cap' => [['external_id' => 9007199254740992]],
        'non-numeric string external_id' => [['external_id' => 'abc']],
        'numeric string external_id' => [['external_id' => '4471']],
        'float external_id' => [['external_id' => 12.5]],
        'boolean external_id' => [['external_id' => true]],
        'array external_id' => [['external_id' => [4471]]],
        'source over the length cap' => [['source' => str_repeat('a', 181)]],
        'whitespace-only source' => [['source' => '   ']],
        'empty source' => [['source' => '']],
        'integer source' => [['source' => 123]],
        'array source' => [['source' => ['acme-ats']]],
    ];
}

test('a malformed claim is narrowed to null on insert and the exchange still succeeds', function (array $claims): void {
    $org = Organization::factory()->create();
    $project = extRefExchangeProject($org);

    // Never the 401 the spec once asked for: the candidate is not turned away.
    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-malformed', $claims))
        ->assertOk()
        ->assertJsonStructure(['access_token']);

    expect(extRefStored($project, 'ref-malformed'))->toBe(['external_id' => null, 'source' => null]);
})->with(fn () => extRefMalformedClaims());

test('a malformed claim keeps the stored value on an existing row', function (array $claims): void {
    $org = Organization::factory()->create();
    $project = extRefExchangeProject($org);

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-malformed-keep', [
        'external_id' => 4471,
        'source' => 'acme-ats',
    ]))->assertOk();

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-malformed-keep', $claims))->assertOk();

    expect(extRefStored($project, 'ref-malformed-keep'))->toBe(['external_id' => 4471, 'source' => 'acme-ats']);
})->with(fn () => extRefMalformedClaims());

// Silent narrowing is the design (AD-5), but a systematically broken
// integration must still be detectable server-side: a dropped claim is logged
// by NAME only (never the value: `source` and `external_id` are the caller's
// data), with enough context to find the project.

test('a dropped claim is logged by name, without its value, and the exchange still succeeds', function (): void {
    Log::spy();
    $org = Organization::factory()->create();
    $project = extRefExchangeProject($org);

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-logged', [
        'external_id' => 'leaked-value-abc',
        'source' => 123,
    ]))->assertOk();

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) use ($project): bool {
            return $message === 'sso.exchange.external_reference_dropped'
                && $context['claims'] === ['external_id', 'source']
                && $context['project_id'] === $project->id
                && $context['organization_id'] === $project->organization_id
                && ! str_contains(json_encode($context), 'leaked-value-abc');
        })
        ->once();
});

test('a well-formed, absent or empty claim logs nothing', function (array $claims): void {
    Log::spy();
    $org = Organization::factory()->create();
    $project = extRefExchangeProject($org);

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-quiet', $claims))->assertOk();

    Log::shouldNotHaveReceived('warning', fn (string $message): bool => $message === 'sso.exchange.external_reference_dropped');
})->with([
    'no claims' => [[]],
    'well-formed pair' => [['external_id' => 4471, 'source' => 'acme-ats']],
    'empty source' => [['source' => '']],
    'whitespace-only source' => [['source' => '   ']],
]);

test('the 2^53-1 cap and a 180-character source are accepted verbatim', function (): void {
    $org = Organization::factory()->create();
    $project = extRefExchangeProject($org);
    $source = str_repeat('é', 180);

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-edge', [
        'external_id' => 9007199254740991,
        'source' => $source,
    ]))->assertOk();

    expect(extRefStored($project, 'ref-edge'))->toBe(['external_id' => 9007199254740991, 'source' => $source]);
});

// ---------------------------------------------------------------------------
// Surfaces that never carry the reference
// ---------------------------------------------------------------------------

test('the candidate JWT claims are unchanged: no external_id and no source', function (): void {
    $org = Organization::factory()->create();
    $project = extRefExchangeProject($org);

    $response = $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-candidate-jwt', [
        'external_id' => 4471,
        'source' => 'acme-ats',
    ]));

    $response->assertOk();
    // Read the payload segment directly: decoding through JWTAuth would feed
    // tymon's singleton factory and could mask what the token itself carries.
    $segment = explode('.', $response->json('access_token'))[1];
    $claims = json_decode((string) base64_decode(strtr($segment, '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);

    expect($claims)->not->toHaveKey('external_id')->and($claims)->not->toHaveKey('source');
    expect($claims['typ'])->toBe('candidate');
    expect($claims['candidate_ref'])->toBe('ref-candidate-jwt');
});

test('the progress event created by the first exchange carries neither key', function (): void {
    $org = Organization::factory()->create();
    $project = extRefExchangeProject($org, [
        'webhook_url' => 'https://receiver.example.test/hook',
        'webhook_secret' => 'whsec_external_reference_test',
        'webhook_events' => ['progress', 'evaluation'],
    ]);

    $this->getJson('/api/sso/exchange?token='.extRefForgeSsoLink($project, 'ref-progress', [
        'external_id' => 4471,
        'source' => 'acme-ats',
    ]))->assertOk();

    $participant = Participant::where('project_id', $project->id)->where('candidate_ref', 'ref-progress')->firstOrFail();
    $delivery = WebhookDelivery::where('participant_id', $participant->id)->firstOrFail();

    // A row was recorded (so the assertion below is about a real payload), it
    // still echoes candidate_ref, and neither key exists at any depth.
    expect($delivery->payload['candidate_ref'])->toBe('ref-progress');
    expect(extRefAllKeys($delivery->payload))->not->toContain('external_id')->and(extRefAllKeys($delivery->payload))->not->toContain('source');
});
