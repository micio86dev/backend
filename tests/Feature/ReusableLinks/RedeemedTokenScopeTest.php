<?php

declare(strict_types=1);

/**
 * The credential a reusable link redemption mints (reusable-interview-links, B3a).
 *
 * It is the ORDINARY candidate JWT, minted by the same factory as the sso-link
 * exchange: valid on the `api-candidate` guard only, scoped to its own
 * participant, project and organisation, 120 minutes long, and carrying no
 * trace of the link it came from. What this file proves is that coming from a
 * reusable link makes it neither broader nor longer-lived than any other
 * candidate token, and that disabling the link does not reach into interviews
 * already started.
 *
 * The JWT payload is decoded by hand (base64), never through
 * `JWTAuth::getPayload()`: tymon's factory is a container singleton whose claim
 * collection accumulates across calls, so a decode inside the test would feed
 * the very state the regression below is about.
 *
 * REQ: The Redeemed Token Reaches Only Its Own Interview,
 *      Disabling Stops New Redemptions But Not Sessions Already Started
 *      (sdd/reusable-interview-links/spec/reusable-interview-links-redemption)
 */

use App\Models\Participant;
use App\Models\ReusableInterviewLink;
use App\Models\User;
use App\Services\ReusableLinkTokenGenerator;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\PublicApi\PublicId;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\Helpers\ReusableLinkFixtures as Fx;
use Tests\TestCase;

beforeEach(function (): void {
    config([
        'reusable_links.redeem.per_ip_per_minute' => 1000,
        'reusable_links.redeem.per_link_per_hour' => 1000,
    ]);
});

/**
 * The claims of a JWT, read straight from its payload segment.
 *
 * @return array<string, mixed>
 */
function redeemScopeClaims(string $jwt): array
{
    return json_decode((string) base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Redeem a token and return the minted JWT.
 */
function redeemScopeRedeem(string $token): string
{
    $response = test()->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk();

    return (string) $response->json('access_token');
}

/**
 * A request as the holder of `$jwt`, with no guard state left over from an
 * earlier request in the same test.
 */
function redeemScopeAs(string $jwt): TestCase
{
    resetAuthGuardState();

    return test()->withToken($jwt);
}

// ─── The claims ──────────────────────────────────────────────────────────────

test('the minted token carries exactly the ordinary candidate claims and none naming a link', function (): void {
    ['org' => $org, 'project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable(
        linkAttributes: ['label' => 'Milan fair stand'],
    );

    $claims = redeemScopeClaims(redeemScopeRedeem($token));
    $visitor = Fx::visitorsOf($link)[0];

    // The custom claims `mintCandidateToken()` builds, plus tymon's registered
    // ones and the `prv` subject lock. Nothing else: no name, no email, no
    // organisation id under its sso-link spelling, no external reference.
    $keys = array_keys($claims);
    sort($keys);
    expect($keys)->toBe([
        'candidate_ref', 'exp', 'iat', 'iss', 'jti', 'lang', 'nbf',
        'organization_id', 'project_id', 'prv', 'role_code', 'sub', 'typ',
    ]);

    expect($claims['typ'])->toBe('candidate')
        ->and($claims['candidate_ref'])->toBe($visitor->candidate_ref)
        ->and($claims['candidate_ref'])->toStartWith('rlv_')
        ->and($claims['project_id'])->toBe($project->id)
        ->and($claims['organization_id'])->toBe($org->id)
        ->and($claims['role_code'])->toBe($project->role_code)
        ->and($claims['lang'])->toBe($link->lang);

    // Nothing in the token names the link, its label or the raw token.
    $encoded = json_encode($claims, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain(PublicId::encode($link))
        ->and($encoded)->not->toContain('rlk_')
        ->and($encoded)->not->toContain('Milan fair stand')
        ->and($encoded)->not->toContain($token)
        ->and($encoded)->not->toContain($link->token_hash);
    foreach (array_keys($claims) as $name) {
        expect($name)->not->toContain('link');
    }
});

test('the token lives 120 minutes, like every candidate token', function (): void {
    ['token' => $token] = Fx::redeemable();

    $jwt = redeemScopeRedeem($token);
    $claims = redeemScopeClaims($jwt);

    expect($claims['exp'] - $claims['iat'])->toBe(7200);

    $this->travel(119)->minutes();
    redeemScopeAs($jwt)->getJson('/api/candidate/session')->assertOk();

    $this->travel(2)->minutes();
    redeemScopeAs($jwt)->getJson('/api/candidate/session')->assertUnauthorized();
});

test('an sso-link exchange earlier in the same process leaves no claims on a later redemption', function (): void {
    // Regression for the claim-collection singleton: decoding the sso-link
    // feeds its claims (display_name, email, org_id, the external reference)
    // into the shared factory, and a candidate token minted afterwards must not
    // inherit them.
    ['org' => $org, 'project' => $project, 'token' => $token] = Fx::redeemable();

    $ssoLink = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => 'ext-1',
        'display_name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => $project->role_code,
        'lang' => 'en',
        'external_id' => 88410013,
        'source' => 'acme-ats',
    ]);

    resetAuthGuardState();
    $this->getJson('/api/sso/exchange?token='.$ssoLink)->assertOk();

    $claims = redeemScopeClaims(redeemScopeRedeem($token));

    foreach (['display_name', 'email', 'org_id', 'external_id', 'source'] as $leaked) {
        expect($claims)->not->toHaveKey($leaked);
    }
    expect($claims['typ'])->toBe('candidate');
});

// ─── What the token can reach ────────────────────────────────────────────────

test('the candidate session answers for the visitor itself, in the ordinary shape and without a link field', function (): void {
    ['project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable();
    $jwt = redeemScopeRedeem($token);
    $visitor = Fx::visitorsOf($link)[0];

    $response = redeemScopeAs($jwt)->getJson('/api/candidate/session')->assertOk();

    // The same key set as an ordinary participant's session response.
    $ordinary = Participant::factory()->forProject($project)->create();
    $ordinaryResponse = redeemScopeAs(CandidateTokenFactory::mintCandidateToken($ordinary))
        ->getJson('/api/candidate/session')
        ->assertOk();

    $visitorKeys = Fx::keysAtAnyDepth($response->json());
    $ordinaryKeys = Fx::keysAtAnyDepth($ordinaryResponse->json());
    sort($visitorKeys);
    sort($ordinaryKeys);
    expect($visitorKeys)->toBe($ordinaryKeys);

    foreach ($visitorKeys as $key) {
        expect($key)->not->toContain('link')->and($key)->not->toContain('reusable');
    }

    expect($response->json('data.candidate_ref') ?? $response->json('candidate_ref'))->toBe($visitor->candidate_ref);
});

test('two visitors of one link each see only themselves', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();
    $first = redeemScopeRedeem($token);
    $second = redeemScopeRedeem($token);
    [$v1, $v2] = Fx::visitorsOf($link);

    $one = redeemScopeAs($first)->getJson('/api/candidate/session')->assertOk()->getContent();
    $two = redeemScopeAs($second)->getJson('/api/candidate/session')->assertOk()->getContent();

    expect($one)->toContain($v1->candidate_ref)->and($one)->not->toContain($v2->candidate_ref)
        ->and($two)->toContain($v2->candidate_ref)->and($two)->not->toContain($v1->candidate_ref);
});

test('the minted token is refused by the admin and machine surfaces', function (string $path): void {
    ['org' => $org, 'project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable();
    $jwt = redeemScopeRedeem($token);
    $visitor = Fx::visitorsOf($link)[0];

    // A backoffice user whose id equals the visitor's. The candidate token's
    // `sub` is the participant id, and the admin guard resolves `sub` against
    // `users.id`: without the `prv` subject lock, this token would authenticate
    // as THIS user. The lock is what keeps the two id spaces apart.
    $admin = User::factory()->create(['id' => $visitor->id, 'organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $admin->assignRole(SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]));

    $path = str_replace(['{project}', '{participant}'], [(string) $project->id, (string) $visitor->id], $path);

    redeemScopeAs($jwt)->getJson($path)->assertUnauthorized();
})->with([
    'admin participant list' => '/api/participants',
    'admin participant detail' => '/api/participants/{participant}',
    'admin projects' => '/api/projects',
    'admin reusable links' => '/api/projects/{project}/reusable-links',
    'machine whoami' => '/api/m2m/whoami',
    'machine participants' => '/api/m2m/participants',
]);

test('the minted token cannot create or disable a reusable link', function (): void {
    ['project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable();
    $jwt = redeemScopeRedeem($token);
    $id = PublicId::encode($link);

    redeemScopeAs($jwt)->postJson("/api/projects/{$project->id}/reusable-links", [])->assertUnauthorized();
    redeemScopeAs($jwt)->deleteJson("/api/projects/{$project->id}/reusable-links/{$id}")->assertUnauthorized();

    expect(Fx::rowsOf($project))->toHaveCount(1);
});

// ─── In-flight visitors survive a disable ────────────────────────────────────

test('a visitor already in the interview keeps working after the link is disabled', function (): void {
    Queue::fake();
    Http::fake(heygenOkFake());

    // A project that can actually compose a prompt, so /start reaches the
    // provider (the plain fixture project stops at indicator composition).
    $org = casOrg();
    [$project] = casProject($org);
    $token = ReusableLinkTokenGenerator::generate();
    $link = Fx::link($project, [
        'token_hash' => ReusableLinkTokenGenerator::hash($token),
        'token_prefix' => ReusableLinkTokenGenerator::prefixOf($token),
    ]);

    $jwt = redeemScopeRedeem($token);

    // The admin disables the link while the visitor is mid-session.
    ReusableInterviewLink::withoutGlobalScopes()->whereKey($link->id)->update(['disabled_at' => now()]);
    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertNotFound();

    redeemScopeAs($jwt)->getJson('/api/candidate/session')->assertOk();
    redeemScopeAs($jwt)->postJson('/api/candidate/interview/start')->assertStatus(201);

    // The disable touched no participant.
    expect(Fx::visitorsOf($link))->toHaveCount(1);
});

// ─── Single-use entry links are unchanged ────────────────────────────────────

test('a single-use entry link behaves exactly as before after a redemption in the same process', function (): void {
    // The two mechanisms share the candidate token factory and its singleton
    // claim collection. Redeeming first must not change what an sso-link
    // exchange stores, returns or consumes.
    ['org' => $org, 'project' => $project, 'token' => $token] = Fx::redeemable();
    redeemScopeRedeem($token);

    $ssoLink = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => 'ext-after-redeem',
        'display_name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => $project->role_code,
        'lang' => 'en',
    ]);

    resetAuthGuardState();
    $response = $this->getJson('/api/sso/exchange?token='.$ssoLink)->assertOk();

    expect(array_keys($response->json()))->toBe(['access_token']);

    $participant = Participant::query()->where('candidate_ref', 'ext-after-redeem')->firstOrFail();
    expect($participant->display_name)->toBe('Ada Lovelace')
        ->and($participant->email)->toBe('ada@example.test')
        // An ordinary candidate: no link marker, and the default mode.
        ->and($participant->reusable_interview_link_id)->toBeNull();

    // Still single-use.
    resetAuthGuardState();
    $this->getJson('/api/sso/exchange?token='.$ssoLink)->assertUnauthorized();
});
