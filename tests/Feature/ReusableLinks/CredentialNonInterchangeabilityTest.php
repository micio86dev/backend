<?php

declare(strict_types=1);

/**
 * A link token is not a credential, and no credential is a link token
 * (reusable-interview-links, B3b.2; design AD-2, AD-10b).
 *
 * Five kinds of secret already open doors in this API: the backoffice user JWT
 * (`api`), the candidate JWT (`api-candidate`), the machine API key (`api-m2m`
 * and `/v1`), the single-use SSO link JWT (`/sso/exchange`) and the embed
 * session token (`/embed/exchange`). The reusable link token is a SIXTH, with its
 * own door (`/reusable-links/redeem`), and the doors must stay separate in both
 * directions:
 *
 *   - presented at any other door a link token is refused (401) and leaves no
 *     trace there: no participant, and no `sso_jti:` cache key touched;
 *   - presented at ITS door, any other credential is "not found" like any
 *     malformed value: the same 404 as an unknown token, byte for byte, as the
 *     `link_token` field and as an `Authorization: Bearer` with no body, and the
 *     credential is not spent by it.
 *
 * REQ: Unknown, Malformed And Disabled Tokens Are Indistinguishable (other
 *      credentials), The Raw Token And Its Hash Are Never Exposed
 *      (sdd/reusable-interview-links/spec/reusable-interview-links-redemption)
 */

use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Services\ApiKeyGenerator;
use App\Services\ReusableLinkTokenGenerator;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Helpers\ReusableLinkFixtures as Fx;

beforeEach(function (): void {
    config([
        'interview.candidate_app_url' => Fx::CANDIDATE_ORIGIN,
        'reusable_links.redeem.per_ip_per_minute' => 100000,
        'reusable_links.redeem.per_link_per_hour' => 100000,
    ]);
});

/**
 * Every credential kind that already exists, issued for ONE organisation, next
 * to a redeemable reusable link of that organisation.
 *
 * @return array{
 *     link: array{org: Organization, project: Project, link: ReusableInterviewLink, token: string},
 *     credentials: array<string, string>,
 * }
 */
function credentialIsolationWorld(): array
{
    $world = Fx::redeemable();
    $org = $world['org'];
    $project = $world['project'];

    $liveKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    $testKey = ApiKeyGenerator::generate(ApiKeyMode::Test);
    foreach ([$liveKey, $testKey] as $rawKey) {
        ApiClient::factory()->withRawKey($rawKey)->create([
            'organization_id' => $org->id,
            'is_active' => true,
            'abilities' => ['interviews:write', 'interviews:read', 'projects:read', 'participants:read', 'participants:write'],
        ]);
    }

    $candidateJwt = TenantContextScope::runFor($org->id, function () use ($project): string {
        $participant = Participant::factory()->forProject($project)->create();

        return CandidateTokenFactory::mintCandidateToken($participant->fresh());
    });

    $ssoLink = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => 'sso-'.uniqid(),
        'display_name' => 'Ada Lovelace',
        'email' => uniqid('ada-').'@example.test',
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => $project->role_code,
        'lang' => 'en',
    ]);

    // The embed session token comes from creating an interview over the public
    // API with the organisation's own live key, exactly as an integrator does.
    $embedProject = TenantContextScope::runFor($org->id, fn (): Project => Project::factory()->create([
        'organization_id' => $org->id,
        'avatar_template_id' => AvatarTemplate::create(['name' => 'Ada', 'provider' => 'heygen', 'config' => []])->id,
        'status' => 'active',
    ]));
    $sessionToken = test()->withHeaders(['Authorization' => 'Bearer '.$liveKey])
        ->postJson('/api/v1/interviews', [
            'project_id' => PublicId::encode($embedProject),
            'candidate' => [
                'candidate_ref' => 'ref-'.uniqid(),
                'email' => uniqid().'@example.com',
                'display_name' => 'Candidate',
            ],
        ])
        ->assertCreated()
        ->json('session_token');
    test()->flushHeaders();

    return [
        'link' => $world,
        'credentials' => [
            'a backoffice user JWT' => authTokenForRole($org, 'admin'),
            'a candidate JWT' => $candidateJwt,
            'an sso-link JWT' => $ssoLink,
            'a live API key' => $liveKey,
            'a test API key' => $testKey,
            'an embed session token' => (string) $sessionToken,
        ],
    ];
}

/**
 * The response headers a caller could use to tell two failures apart: every one
 * but those that vary by design (the date, the rate-limit counters).
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, string>
 */
function credentialIsolationStableHeaders(TestResponse $response): array
{
    $headers = [];

    foreach ($response->headers->all() as $name => $values) {
        $name = strtolower((string) $name);

        if ($name !== 'date' && ! str_starts_with($name, 'x-ratelimit-')) {
            $headers[$name] = implode(', ', (array) $values);
        }
    }

    ksort($headers);

    return $headers;
}

/**
 * Prove a credential still opens ITS OWN door after a link token's door has
 * seen it: nothing was consumed, spent or revoked.
 */
function credentialIsolationAssertStillWorks(string $label, string $credential): void
{
    resetAuthGuardState();
    $client = test()->flushHeaders();

    match ($label) {
        'a backoffice user JWT' => $client->withToken($credential)->getJson('/api/projects')->assertOk(),
        'a candidate JWT' => $client->withToken($credential)->getJson('/api/candidate/session')->assertOk(),
        'an sso-link JWT' => $client->getJson('/api/sso/exchange?token='.$credential)->assertOk(),
        'a live API key', 'a test API key' => $client->withToken($credential)->getJson('/api/v1/organization')->assertOk(),
        'an embed session token' => $client->getJson('/api/embed/exchange?token='.$credential)->assertOk(),
    };
}

// ─── A link token at every other door ────────────────────────────────────────

test('a link token as a Bearer is refused on the admin, candidate, machine and public API guards', function (string $door, string $url): void {
    ['link' => ['token' => $token]] = credentialIsolationWorld();

    resetAuthGuardState();
    $this->flushHeaders()->withToken($token)->getJson($url)->assertUnauthorized();
})->with([
    'api (backoffice user)' => ['api', '/api/projects'],
    'api-candidate' => ['api-candidate', '/api/candidate/session'],
    'api-m2m' => ['api-m2m', '/api/m2m/whoami'],
    '/v1' => ['v1', '/api/v1/organization'],
]);

/**
 * Run `$action` and return every `sso_jti` cache key it read, wrote or forgot.
 *
 * @param  Closure(): mixed  $action
 * @return list<string>
 */
function credentialIsolationSsoJtiKeys(Closure $action): array
{
    $keys = [];
    Event::listen([CacheHit::class, CacheMissed::class, KeyWritten::class, KeyForgotten::class], function (object $event) use (&$keys): void {
        $keys[] = $event->key;
    });

    $action();

    return array_values(array_filter($keys, fn (string $key): bool => str_contains($key, 'sso_jti')));
}

test('the cache listener does see the sso_jti key of a real sso-link exchange', function (): void {
    // The control for the next test: without it "no sso_jti key" could be a
    // listener that never fires.
    ['credentials' => $credentials] = credentialIsolationWorld();

    $keys = credentialIsolationSsoJtiKeys(
        fn () => $this->getJson('/api/sso/exchange?token='.$credentials['an sso-link JWT'])->assertOk(),
    );

    expect($keys)->not->toBe([]);
});

test('a link token as the sso-link token is refused with 401, touches no sso_jti key and creates no participant', function (): void {
    ['link' => ['token' => $token]] = credentialIsolationWorld();
    $participantsBefore = Participant::query()->count();

    $keys = credentialIsolationSsoJtiKeys(
        fn () => $this->getJson('/api/sso/exchange?token='.$token)->assertUnauthorized(),
    );

    expect($keys)->toBe([])
        ->and(Participant::query()->count())->toBe($participantsBefore);
});

// ─── Every other credential at the link door ─────────────────────────────────

test('every other credential, sent as the link_token, is the same 404 as an unknown token and is not spent', function (string $label): void {
    ['link' => $link, 'credentials' => $credentials] = credentialIsolationWorld();
    $baseline = $this->postJson(Fx::REDEEM_URL, Fx::redeemBody(ReusableLinkTokenGenerator::generate()));

    $response = $this->postJson(Fx::REDEEM_URL, Fx::redeemBody([]));

    $response->assertNotFound();
    expect($response->getContent())->toBe(Fx::NOT_FOUND_BODY)
        ->and(credentialIsolationStableHeaders($response))->toBe(credentialIsolationStableHeaders($baseline))
        ->and(Fx::visitorsOf($link['link']))->toBe([]);

    credentialIsolationAssertStillWorks($label, $credentials[$label]);
})->with([
    'a backoffice user JWT',
    'a candidate JWT',
    'an sso-link JWT',
    'a live API key',
    'a test API key',
    'an embed session token',
]);

test('every other credential, sent as a Bearer with no body, is the same 404: the header is ignored and not spent', function (string $label): void {
    ['link' => $link, 'credentials' => $credentials] = credentialIsolationWorld();
    $baseline = $this->postJson(Fx::REDEEM_URL, Fx::identity());

    resetAuthGuardState();
    $response = $this->flushHeaders()->withToken($credentials[$label])->postJson(Fx::REDEEM_URL, Fx::identity());

    $response->assertNotFound();
    expect($response->getContent())->toBe(Fx::NOT_FOUND_BODY)
        ->and(credentialIsolationStableHeaders($response))->toBe(credentialIsolationStableHeaders($baseline))
        ->and(Fx::visitorsOf($link['link']))->toBe([]);

    credentialIsolationAssertStillWorks($label, $credentials[$label]);
})->with([
    'a backoffice user JWT',
    'a candidate JWT',
    'an sso-link JWT',
    'a live API key',
    'a test API key',
    'an embed session token',
]);

test('a valid link token beside any other credential in the Authorization header is judged on the body alone', function (string $label): void {
    ['link' => $link, 'credentials' => $credentials] = credentialIsolationWorld();

    resetAuthGuardState();
    $this->flushHeaders()->withToken($credentials[$label])
        ->postJson(Fx::REDEEM_URL, Fx::redeemBody($link['token']))
        ->assertOk();

    expect(Fx::visitorsOf($link['link']))->toHaveCount(1);
})->with([
    'a backoffice user JWT',
    'a candidate JWT',
    'an sso-link JWT',
    'a live API key',
    'a test API key',
    'an embed session token',
]);

test('a valid link token is redeemed only from the body: as a Bearer, a query value or a header it is the generic 404', function (): void {
    ['link' => ['link' => $link, 'token' => $token]] = credentialIsolationWorld();

    resetAuthGuardState();
    $bearer = $this->flushHeaders()->withToken($token)->postJson(Fx::REDEEM_URL, Fx::identity());
    $header = $this->flushHeaders()->withHeaders(['X-Link-Token' => $token])->postJson(Fx::REDEEM_URL, Fx::identity());

    foreach ([$bearer, $header] as $response) {
        $response->assertNotFound();
        expect($response->getContent())->toBe(Fx::NOT_FOUND_BODY);
    }

    expect(Fx::visitorsOf($link))->toBe([])
        ->and(ReusableInterviewLink::withoutGlobalScopes()->findOrFail($link->id)->uses_count)->toBe(0);
});
