<?php

declare(strict_types=1);

/**
 * A reserved placeholder address is refused on every enrolment path
 * (reusable-link-visitor-identity, api-4b).
 *
 * Both reserved domains (`@invalid.beai.local`, `@purged.beai.invalid`) belong to
 * addresses BEAI writes itself. If a person, an operator or a calling system could
 * type one, the row would claim "never had an address" or "was purged", the mail
 * guard and the purge would treat it as synthesised, and a typed address could
 * collide with the placeholder the purge derives for another participant. So the
 * five paths that accept an address from outside refuse them, in any case.
 *
 * Two paths can re-issue a link for an EXISTING `candidate_ref`: the operator
 * entry link and the M2M sso-link mint. The backoffice participant-detail
 * re-issue sends the stored email back, so a legacy anonymous visitor
 * (`<ref>@invalid.beai.local`) and a purged participant
 * (`<sha256>@purged.beai.invalid`) must stay re-issuable with their OWN
 * placeholder, and only theirs. The SSO exchange and the scheduled sweep take no
 * address from a caller and are unchanged.
 *
 * REQ: Reserved Placeholder Domains Are Refused On Every Enrolment Path
 *      (sdd/reusable-link-visitor-identity/spec/data-retention and
 *      /spec/participant-sso)
 */

use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ApiKeyGenerator;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Participant\PlaceholderEmail;
use App\Support\PublicApi\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Helpers\ReusableLinkFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Fx::configureOrigin();
    config([
        'reusable_links.redeem.per_ip_per_minute' => 1000,
        'reusable_links.redeem.per_link_per_hour' => 1000,
    ]);
});

/**
 * An organisation with an interviewable project and one of each credential the
 * five paths use.
 *
 * @return array{org: Organization, project: Project, admin: string, m2m: string, live: string, link: array<string, mixed>}
 */
function placeholderRefusalWorld(): array
{
    $world = Fx::redeemable();
    $org = $world['org'];
    $m2m = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($m2m)->create([
        'organization_id' => $org->id,
        'is_active' => true,
        'abilities' => ['participants:create', 'sso_link:generate', 'participants:read'],
    ]);
    $live = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($live)->create([
        'organization_id' => $org->id,
        'is_active' => true,
        'abilities' => ['interviews:write', 'interviews:read'],
    ]);

    return [
        'org' => $org,
        'project' => $world['project'],
        'admin' => authTokenForRole($org, 'admin'),
        'm2m' => $m2m,
        'live' => $live,
        'link' => $world,
    ];
}

/**
 * Submit `$email` on one of the five paths, as a NEW candidate `ref-1`, and return
 * the response.
 *
 * @param  array<string, mixed>  $world
 */
function placeholderRefusalSubmit(string $path, array $world, string $email): TestResponse
{
    $project = $world['project'];
    $candidate = ['candidate_ref' => 'ref-1', 'display_name' => 'Ada Lovelace', 'email' => $email];
    $test = test();
    $test->flushHeaders();
    resetAuthGuardState();

    return match ($path) {
        'the operator entry link' => $test->withToken($world['admin'])->postJson('/api/entry-links', ['project_id' => $project->id, ...$candidate]),
        'the M2M participant create' => $test->withHeaders(['Authorization' => 'Bearer '.$world['m2m']])->postJson('/api/m2m/participants', ['project_id' => $project->id, ...$candidate]),
        'the M2M sso-link mint' => $test->withHeaders(['Authorization' => 'Bearer '.$world['m2m']])->postJson('/api/m2m/sso-link', ['project_id' => $project->id, ...$candidate]),
        'the v1 enrolment' => $test->withHeaders(['Authorization' => 'Bearer '.$world['live']])->postJson('/api/v1/interviews', [
            'project_id' => PublicId::encode($project),
            'candidate' => $candidate,
        ]),
        'the reusable redemption' => $test->postJson(Fx::REDEEM_URL, ['link_token' => $world['link']['token'], 'display_name' => 'Ada Lovelace', 'email' => $email]),
    };
}

const PLACEHOLDER_REFUSAL_PATHS = [
    'the operator entry link',
    'the M2M participant create',
    'the M2M sso-link mint',
    'the v1 enrolment',
    'the reusable redemption',
];

// ─── Refused everywhere ──────────────────────────────────────────────────────

test('every path that takes an address refuses a reserved placeholder, in any case', function (string $path, string $email): void {
    $world = placeholderRefusalWorld();
    $participants = Participant::query()->count();

    $response = placeholderRefusalSubmit($path, $world, $email);

    $response->assertStatus(422);
    if ($path === 'the v1 enrolment') {
        // The v1 refusal is the published problem+json, not the framework body.
        $response->assertJsonPath('code', 'validation_failed');
    } else {
        expect(array_keys($response->json('errors')))->toBe(['email']);
    }
    expect(Participant::query()->count())->toBe($participants);
})->with(PLACEHOLDER_REFUSAL_PATHS)->with([
    'the legacy domain' => 'x@invalid.beai.local',
    'the purged domain in capitals' => 'X@PURGED.BEAI.INVALID',
    'another reference\'s legacy placeholder' => 'other-ref@invalid.beai.local',
    'another reference\'s purged placeholder' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@purged.beai.invalid',
]);

test('an ordinary address is still accepted on every path', function (string $path): void {
    $world = placeholderRefusalWorld();

    placeholderRefusalSubmit($path, $world, 'ada@example.test')->assertSuccessful();
})->with(PLACEHOLDER_REFUSAL_PATHS);

// ─── The two re-issue paths accept the request's OWN placeholder ─────────────

test('a re-issue path accepts the participant\'s own legacy or purged placeholder, and only its own', function (string $path, string $spelling): void {
    $world = placeholderRefusalWorld();
    $own = $spelling === 'legacy' ? PlaceholderEmail::for('ref-1') : PlaceholderEmail::forPurged('ref-1');
    $someoneElses = $spelling === 'legacy' ? PlaceholderEmail::for('ref-2') : PlaceholderEmail::forPurged('ref-2');

    placeholderRefusalSubmit($path, $world, $own)->assertSuccessful();
    placeholderRefusalSubmit($path, $world, $someoneElses)->assertStatus(422);
})->with(['the operator entry link', 'the M2M sso-link mint'])->with(['legacy', 'purged']);

test('the other three paths accept no placeholder at all, not even the request\'s own', function (string $path): void {
    $world = placeholderRefusalWorld();

    placeholderRefusalSubmit($path, $world, PlaceholderEmail::for('ref-1'))->assertStatus(422);
    placeholderRefusalSubmit($path, $world, PlaceholderEmail::forPurged('ref-1'))->assertStatus(422);
})->with(['the M2M participant create', 'the v1 enrolment', 'the reusable redemption']);

// ─── Paths that take no address from a caller are unchanged ──────────────────

test('the SSO exchange adds no validation: a signed claim is exchanged as it always was', function (): void {
    $world = placeholderRefusalWorld();
    $org = $world['org'];
    $project = $world['project'];

    $token = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => 'ref-1',
        'display_name' => 'Ada Lovelace',
        'email' => PlaceholderEmail::for('ref-1'),
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => $project->role_code,
        'lang' => 'en',
    ]);

    $this->getJson('/api/sso/exchange?token='.$token)->assertOk();

    expect(Participant::query()->where('project_id', $project->id)->where('candidate_ref', 'ref-1')->value('email'))
        ->toBe(PlaceholderEmail::for('ref-1'));
});
