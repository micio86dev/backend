<?php

declare(strict_types=1);

/**
 * POST /api/reusable-links/redeem refusals (reusable-interview-links, B3a).
 *
 * Two refusals only, and the difference between them is the whole design:
 *
 *   - 404 `{"message":"Not found."}` for EVERY token that is not redeemable:
 *     missing, null, non-string, array, empty, oversized, wrongly formatted,
 *     unknown, disabled, a soft-deleted project, and every other credential
 *     kind. One response helper, so the caller cannot tell which one it hit.
 *   - 403 `{"message":"Access denied.","redirect_url":...}` ONLY for a valid,
 *     ENABLED token whose project is closed. Reaching it already proves the
 *     caller holds a working token, so it discloses nothing they lack.
 *
 * A refusal consumes nothing: no participant, no counter, no event, no
 * webhook, and a credential of another type submitted as `link_token` is not
 * spent.
 *
 * REQ: Unknown, Malformed And Disabled Tokens Are Indistinguishable,
 *      Project State Is Re-Checked At Every Redemption
 *      (sdd/reusable-interview-links/spec/reusable-interview-links-redemption)
 */

use App\Enums\ApiKeyMode;
use App\Events\ParticipantCreated;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Models\WebhookDelivery;
use App\Services\ApiKeyGenerator;
use App\Services\ReusableLinkTokenGenerator;
use App\Support\Jwt\CandidateTokenFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Helpers\ReusableLinkFixtures as Fx;

beforeEach(function (): void {
    config([
        'reusable_links.redeem.per_ip_per_minute' => 1000,
        'reusable_links.redeem.per_link_per_hour' => 1000,
    ]);
});

/**
 * Every response header that is not inherently varying, name and value. The
 * date and the rate-limit counters differ per request by design; everything
 * else a caller could read must be identical across failure classes.
 *
 * @return array<string, string>
 */
function redeemRefusalStableHeaders(TestResponse $response): array
{
    $headers = [];

    foreach ($response->headers->all() as $name => $values) {
        $name = strtolower((string) $name);

        if ($name === 'date' || str_starts_with($name, 'x-ratelimit-')) {
            continue;
        }

        $headers[$name] = implode(', ', (array) $values);
    }

    ksort($headers);

    return $headers;
}

/**
 * Build the request body of one named failure class against a world.
 *
 * @param  array{org: Organization, project: Project, link: ReusableInterviewLink, token: string}  $world
 * @return array<string, mixed>
 */
function redeemRefusalBody(string $case, array $world): array
{
    $unknownWellFormed = ReusableLinkTokenGenerator::generate();

    return match ($case) {
        'no body' => [],
        'null' => ['link_token' => null],
        'an integer' => ['link_token' => 123],
        'an array' => ['link_token' => ['x']],
        'an array holding a valid token' => ['link_token' => [$world['token']]],
        'an empty string' => ['link_token' => ''],
        'a 10 KB string' => ['link_token' => str_repeat('a', 10 * 1024)],
        'the marker and 42 characters' => ['link_token' => 'beai_rl_'.str_repeat('a', 42)],
        'the marker and 44 characters' => ['link_token' => 'beai_rl_'.str_repeat('a', 44)],
        'the visible prefix of a real token' => ['link_token' => $world['link']->token_prefix],
        'a real token truncated by one character' => ['link_token' => substr($world['token'], 0, -1)],
        'a real token extended by one character' => ['link_token' => $world['token'].'a'],
        'a real token with a trailing newline' => ['link_token' => $world['token']."\n"],
        'a real token with surrounding spaces' => ['link_token' => '  '.$world['token'].'  '],
        'a well-formed unknown token' => ['link_token' => $unknownWellFormed],
        'a live api key' => ['link_token' => ApiKeyGenerator::generate(ApiKeyMode::Live)],
        'a test api key' => ['link_token' => ApiKeyGenerator::generate(ApiKeyMode::Test)],
        default => throw new InvalidArgumentException($case),
    };
}

const REDEEM_REFUSAL_CASES = [
    'no body',
    'null',
    'an integer',
    'an array',
    'an array holding a valid token',
    'an empty string',
    'a 10 KB string',
    'the marker and 42 characters',
    'the marker and 44 characters',
    'the visible prefix of a real token',
    'a real token truncated by one character',
    'a real token extended by one character',
    'a real token with a trailing newline',
    'a real token with surrounding spaces',
    'a well-formed unknown token',
    'a live api key',
    'a test api key',
];

// ─── 404: byte-identical for every failure class ─────────────────────────────

test('every token that is not redeemable gets the same 404, byte for byte', function (string $case): void {
    $world = Fx::redeemable();
    $baseline = $this->postJson(Fx::REDEEM_URL, ['link_token' => ReusableLinkTokenGenerator::generate()]);

    $body = redeemRefusalBody($case, $world);
    $response = $this->postJson(Fx::REDEEM_URL, $body);

    $response->assertNotFound();
    expect($response->getContent())->toBe(Fx::NOT_FOUND_BODY)
        ->and($response->headers->get('Content-Type'))->toBe($baseline->headers->get('Content-Type'))
        ->and(redeemRefusalStableHeaders($response))->toBe(redeemRefusalStableHeaders($baseline))
        // Nothing the caller sent is echoed back.
        ->and($response->getContent())->not->toContain($world['token'])
        ->and($response->getContent())->not->toContain($world['link']->token_prefix);

    // ...and nothing was created or counted.
    expect(Fx::visitorsOf($world['link']))->toBe([])
        ->and(ReusableInterviewLink::withoutGlobalScopes()->findOrFail($world['link']->id)->uses_count)->toBe(0);
})->with(REDEEM_REFUSAL_CASES);

test('a disabled link, a soft-deleted project and an unknown token cannot be told apart', function (): void {
    $disabled = Fx::redeemable(linkAttributes: ['disabled_at' => now()]);
    $deleted = Fx::redeemable();
    $deleted['project']->delete();
    $unknown = ReusableLinkTokenGenerator::generate();

    // A different client address per request, so no per-IP counter has moved
    // when the next one is made: what is left to compare is exactly what the
    // outcome could influence, rate-limit headers included.
    $responses = [
        'disabled' => $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
            ->postJson(Fx::REDEEM_URL, ['link_token' => $disabled['token']]),
        'project soft-deleted' => $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
            ->postJson(Fx::REDEEM_URL, ['link_token' => $deleted['token']]),
        'unknown' => $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.13'])
            ->postJson(Fx::REDEEM_URL, ['link_token' => $unknown]),
    ];

    foreach ($responses as $label => $response) {
        $response->assertNotFound();
        expect($response->getContent())->toBe(Fx::NOT_FOUND_BODY, $label);
    }

    // Whether a link exists is not observable anywhere in the response: the
    // rate-limit headers match too, because every well-formed token opens a
    // link bucket whether or not a link exists.
    $reference = $responses['unknown']->headers->all();
    unset($reference['date']);
    foreach ($responses as $label => $response) {
        $headers = $response->headers->all();
        unset($headers['date']);

        expect($headers)->toEqual($reference, $label);
    }

    expect(Fx::visitorsOf($disabled['link']))->toBe([])
        ->and(Fx::visitorsOf($deleted['link']))->toBe([]);
});

test('a disabled link on a closed project is still the generic 404, never the 403', function (): void {
    // The 403 is for holders of a WORKING token. A disabled token must not reach
    // the project gates at all, or the 403 (with its redirect) would tell a
    // caller that a token they hold was once real.
    $world = Fx::redeemable(
        projectAttributes: ['status' => 'inactive', 'error_redirect_url' => 'https://x.example/err'],
        linkAttributes: ['disabled_at' => now()],
    );

    $response = $this->postJson(Fx::REDEEM_URL, ['link_token' => $world['token']]);

    $response->assertNotFound();
    expect($response->getContent())->toBe(Fx::NOT_FOUND_BODY);
});

test('another credential kind submitted as a link token is the same 404', function (string $kind): void {
    $world = Fx::redeemable();
    $participant = Participant::factory()->forProject($world['project'])->create();

    $credential = match ($kind) {
        'an sso-link jwt' => CandidateTokenFactory::mintSsoLink([
            'candidate_ref' => 'ext-1',
            'display_name' => 'Ada',
            'email' => 'ada@example.test',
            'project_id' => $world['project']->id,
            'org_id' => $world['org']->id,
            'role_code' => $world['project']->role_code,
            'lang' => 'en',
        ]),
        'a candidate jwt' => CandidateTokenFactory::mintCandidateToken($participant),
        'an admin user jwt' => authTokenForRole($world['org'], 'admin'),
    };

    $response = $this->postJson(Fx::REDEEM_URL, ['link_token' => $credential]);

    $response->assertNotFound();
    expect($response->getContent())->toBe(Fx::NOT_FOUND_BODY);
})->with(['an sso-link jwt', 'a candidate jwt', 'an admin user jwt']);

test('an sso-link submitted as a link token is not spent and can still be exchanged', function (): void {
    $world = Fx::redeemable();
    $link = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => 'ext-1',
        'display_name' => 'Ada',
        'email' => 'ada@example.test',
        'project_id' => $world['project']->id,
        'org_id' => $world['org']->id,
        'role_code' => $world['project']->role_code,
        'lang' => 'en',
    ]);
    $jti = json_decode((string) base64_decode(strtr(explode('.', $link)[1], '-_', '+/')), true)['jti'];

    $this->postJson(Fx::REDEEM_URL, ['link_token' => $link])->assertNotFound();

    expect(Cache::has('sso_jti:'.$jti))->toBeFalse();

    resetAuthGuardState();
    $this->getJson('/api/sso/exchange?token='.$link)->assertOk()->assertJsonStructure(['access_token']);
});

test('a link whose project belongs to another organisation is not redeemable', function (): void {
    // No code path creates such a row (the table has two independent foreign
    // keys, and the creation action asserts they agree), so it is forced. The
    // public endpoint has no tenant context to fall back on: the project must be
    // pinned to the LINK's organisation, or a link could be used to create a
    // participant inside a tenant it does not belong to.
    $world = Fx::redeemable();
    $stranger = Fx::redeemable();

    DB::table('reusable_interview_links')
        ->where('id', $world['link']->id)
        ->update(['organization_id' => $stranger['org']->id]);

    $response = $this->postJson(Fx::REDEEM_URL, ['link_token' => $world['token']]);

    $response->assertNotFound();
    expect($response->getContent())->toBe(Fx::NOT_FOUND_BODY)
        ->and(Participant::query()->where('project_id', $world['project']->id)->count())->toBe(0)
        ->and(Participant::query()->where('organization_id', $stranger['org']->id)->count())->toBe(0);
});

// ─── 403: a valid token on a closed project ──────────────────────────────────

/**
 * The four ways a project can refuse a redemption.
 *
 * @return array<string, array{0: array<string, mixed>, 1: bool}> [project attributes, interviewable]
 */
function redeemRefusalClosedProjects(): array
{
    return [
        'not active' => [['status' => 'inactive'], true],
        'not yet live' => [['goes_live_at' => now()->addDay()], true],
        'past its deadline' => [['deadline_at' => now()->subDay()], true],
        'not interviewable' => [[], false],
    ];
}

test('a valid token on a closed project gets the generic 403 with the project\'s error redirect', function (string $case): void {
    [$attributes, $interviewable] = redeemRefusalClosedProjects()[$case];
    $world = Fx::redeemable(
        projectAttributes: array_merge($attributes, ['error_redirect_url' => 'https://x.example/err']),
        interviewable: $interviewable,
    );

    $response = $this->postJson(Fx::REDEEM_URL, ['link_token' => $world['token']]);

    $response->assertForbidden();
    expect($response->json())->toBe(['message' => 'Access denied.', 'redirect_url' => 'https://x.example/err']);
})->with(['not active', 'not yet live', 'past its deadline', 'not interviewable']);

test('all four closed-project refusals are byte-identical, so none discloses which gate fired', function (): void {
    $bodies = [];
    $headers = [];

    foreach (redeemRefusalClosedProjects() as $case => [$attributes, $interviewable]) {
        $world = Fx::redeemable(
            projectAttributes: array_merge($attributes, ['error_redirect_url' => 'https://x.example/err']),
            interviewable: $interviewable,
        );

        $response = $this->postJson(Fx::REDEEM_URL, ['link_token' => $world['token']])->assertForbidden();
        $bodies[$case] = $response->getContent();
        $headers[$case] = redeemRefusalStableHeaders($response);
    }

    expect(array_unique($bodies))->toHaveCount(1)
        ->and(array_unique($headers, SORT_REGULAR))->toHaveCount(1);
});

test('the redirect is null when the project has none', function (): void {
    $world = Fx::redeemable(projectAttributes: ['status' => 'inactive', 'error_redirect_url' => null]);

    $response = $this->postJson(Fx::REDEEM_URL, ['link_token' => $world['token']])->assertForbidden();

    expect($response->getContent())->toBe('{"message":"Access denied.","redirect_url":null}');
});

// ─── A refusal consumes nothing ──────────────────────────────────────────────

test('a refusal leaves the counter, the participants, the events and the webhooks untouched', function (string $kind): void {
    Event::fake([ParticipantCreated::class]);

    $world = match ($kind) {
        'closed project' => Fx::redeemable(['status' => 'inactive']),
        'disabled link' => Fx::redeemable(linkAttributes: ['disabled_at' => now()]),
        'unknown token' => Fx::redeemable(),
    };
    $token = $kind === 'unknown token' ? ReusableLinkTokenGenerator::generate() : $world['token'];
    $participants = Participant::query()->count();

    $this->postJson(Fx::REDEEM_URL, ['link_token' => $token]);

    $row = ReusableInterviewLink::withoutGlobalScopes()->findOrFail($world['link']->id);
    expect($row->uses_count)->toBe(0)
        ->and($row->last_used_at)->toBeNull()
        ->and(Participant::query()->count())->toBe($participants)
        ->and(WebhookDelivery::withoutGlobalScopes()->count())->toBe(0);
    Event::assertNotDispatched(ParticipantCreated::class);
})->with(['closed project', 'disabled link', 'unknown token']);

test('project state is re-checked on every redemption: a closure applies at once and a reopening too', function (): void {
    ['project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable();

    $this->postJson(Fx::REDEEM_URL, ['link_token' => $token])->assertOk();

    // A direct update: the model's status guard forbids this transition, which
    // is about operators, not about what the endpoint must do when it happens.
    DB::table('projects')->where('id', $project->id)->update(['status' => 'inactive']);
    $this->postJson(Fx::REDEEM_URL, ['link_token' => $token])->assertForbidden();

    DB::table('projects')->where('id', $project->id)->update(['status' => 'active']);
    $this->postJson(Fx::REDEEM_URL, ['link_token' => $token])->assertOk();

    expect(Fx::visitorsOf($link))->toHaveCount(2)
        ->and(ReusableInterviewLink::withoutGlobalScopes()->findOrFail($link->id)->uses_count)->toBe(2);
});

// ─── The disable race ────────────────────────────────────────────────────────

test('a disable committed after the lookup but before the locked write wins: 404, nothing written', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();
    Event::fake([ParticipantCreated::class]);

    // The redemption reads the link, then the project; between those reads and
    // its locked transaction an admin disables the link. The project read is the
    // observable seam: it is the first query after the lookup.
    $fired = false;
    DB::listen(function ($query) use (&$fired, $link): void {
        if ($fired || ! str_contains($query->sql, 'from "projects"')) {
            return;
        }

        $fired = true;
        DB::table('reusable_interview_links')->where('id', $link->id)->update(['disabled_at' => now()]);
    });

    $response = $this->postJson(Fx::REDEEM_URL, ['link_token' => $token]);

    expect($fired)->toBeTrue();
    $response->assertNotFound();
    expect($response->getContent())->toBe(Fx::NOT_FOUND_BODY)
        ->and(Fx::visitorsOf($link))->toBe([])
        ->and(ReusableInterviewLink::withoutGlobalScopes()->findOrFail($link->id)->uses_count)->toBe(0);
    Event::assertNotDispatched(ParticipantCreated::class);
});
