<?php

declare(strict_types=1);

/**
 * POST /api/reusable-links/redeem (reusable-interview-links, B3a): the success
 * path of the public redemption endpoint.
 *
 * Every redemption creates ONE fresh visitor participant, carrying the name and
 * email the visitor typed, in the link's own organisation and project and returns the standard candidate JWT,
 * in the same `{access_token}` shape as `GET /api/sso/exchange`. The endpoint is
 * public: it has no user, no tenant context and no bearer credential, so
 * everything below proves the organisation and the project come from the LINK,
 * never from the request.
 *
 * The refusals (identical 404, generic 403) are in `RedeemRefusalTest`, the
 * credential that is minted in `RedeemedTokenScopeTest`.
 *
 * REQ: Public Redemption Endpoint Contract, Each Redemption Creates One Fresh
 *      Visitor Participant, Redemption Is Atomic, A Link Has No Expiry Of Its Own
 *      (sdd/reusable-interview-links/spec/reusable-interview-links-redemption)
 */

use App\Enums\ApiKeyMode;
use App\Events\ParticipantCreated;
use App\Http\Middleware\RejectStaleCredentials;
use App\Http\Middleware\TenantContext;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\ReusableInterviewLink;
use App\Support\PublicApi\PublicId;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Helpers\PublicApi\ThrowingJwtAuth;
use Tests\Helpers\ReusableLinkFixtures as Fx;
use Tymon\JWTAuth\JWTAuth;

beforeEach(function (): void {
    // These tests redeem several times from one client address; the throttle
    // has its own tests, so it must not be what these are about.
    config([
        'reusable_links.redeem.per_ip_per_minute' => 1000,
        'reusable_links.redeem.per_link_per_hour' => 1000,
    ]);
});

/**
 * The stored row, read past the tenant scope.
 */
function redeemCoreLinkRow(ReusableInterviewLink $link): ReusableInterviewLink
{
    return ReusableInterviewLink::withoutGlobalScopes()->findOrFail($link->id);
}

function redeemCoreParticipantCount(): int
{
    return Participant::query()->count();
}

// ─── The success path ────────────────────────────────────────────────────────

test('a redemption answers 200 with exactly one key, a candidate access token', function (): void {
    ['token' => $token] = Fx::redeemable();

    $response = $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk();

    expect(array_keys($response->json()))->toBe(['access_token'])
        ->and($response->json('access_token'))->toBeString()
        // A JWT: three base64url segments.
        ->and(explode('.', (string) $response->json('access_token')))->toHaveCount(3);
});

test('five redemptions create five distinct visitors in the link\'s own organisation and project', function (): void {
    ['org' => $org, 'project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable(
        linkAttributes: ['label' => 'Milan fair stand'],
    );

    $times = [];
    for ($n = 1; $n <= 5; $n++) {
        $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity("person-{$n}@example.test", "Person {$n}")))->assertOk();
        $times[] = redeemCoreLinkRow($link)->last_used_at;
        $this->travel(1)->minutes();
    }

    $visitors = Fx::visitorsOf($link);
    expect($visitors)->toHaveCount(5);

    $refs = [];
    foreach ($visitors as $index => $visitor) {
        $number = $index + 1;

        expect($visitor->candidate_ref)->toMatch('/^rlv_[0-9A-Za-z]{26}$/')
            ->and($visitor->email)->toBe("person-{$number}@example.test")
            ->and($visitor->display_name)->toBe("Person {$number}")
            ->and($visitor->mode)->toBe(ApiKeyMode::Live)
            ->and($visitor->status)->toBe('in_attesa')
            ->and($visitor->scheduling_status)->toBeNull()
            ->and($visitor->scheduled_at)->toBeNull()
            ->and($visitor->reusable_interview_link_id)->toBe($link->id)
            ->and($visitor->organization_id)->toBe($org->id)
            ->and($visitor->project_id)->toBe($project->id)
            ->and($visitor->language)->toBe($link->lang)
            ->and($visitor->role_code)->toBe($project->role_code);

        $refs[] = $visitor->candidate_ref;
    }

    expect(array_unique($refs))->toHaveCount(5);

    $row = redeemCoreLinkRow($link);
    expect($row->uses_count)->toBe(5)
        ->and($row->last_used_at)->not->toBeNull();

    // `last_used_at` advances with every redemption.
    for ($i = 1; $i < 5; $i++) {
        expect($times[$i]?->greaterThan($times[$i - 1]))->toBeTrue();
    }
});

test('the link label never becomes a visitor name: the typed name is stored with or without a label', function (?string $label): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable(linkAttributes: ['label' => $label]);

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('first@example.test', 'First Typed')))->assertOk();
    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('second@example.test', 'Second Typed')))->assertOk();

    expect(array_map(fn (Participant $p): string => $p->display_name, Fx::visitorsOf($link)))
        ->toBe(['First Typed', 'Second Typed']);
})->with(['a labelled link' => 'Milan fair stand', 'an unlabelled link' => null]);

test('a potential project yields a visitor with no role', function (): void {
    // The project row still carries a role code (the column is not cleared when a
    // project is of the `potential` type): the visitor must not inherit it,
    // because the exchange refuses a role on a potential candidate.
    ['link' => $link, 'token' => $token] = Fx::redeemable(
        projectAttributes: ['assessment_type' => 'potential', 'role_code' => 'ICO'],
    );

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk();

    expect(Fx::visitorsOf($link)[0]->role_code)->toBeNull();
});

test('the link\'s stored language wins over a later change of the project language', function (): void {
    ['project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable(
        projectAttributes: ['language' => 'en'],
        linkAttributes: ['lang' => 'en'],
    );
    $project->forceFill(['language' => 'it'])->save();

    $response = $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk();

    expect(Fx::visitorsOf($link)[0]->language)->toBe('en');

    $claims = json_decode(
        (string) base64_decode(strtr(explode('.', (string) $response->json('access_token'))[1], '-_', '+/')),
        true,
    );
    expect($claims['lang'])->toBe('en');
});

test('a link has no expiry of its own: redeemable ten years later while the project is open', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    $this->travel(10)->years();

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk();

    expect(Fx::visitorsOf($link))->toHaveCount(1);
});

test('a redemption never creates anything in another project or organisation', function (): void {
    ['project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable();
    // Another project of the SAME organisation, and a whole other organisation,
    // each with a link of its own that must stay untouched.
    $siblingProject = Fx::project(Organization::query()->findOrFail($project->organization_id));
    $siblingLink = Fx::link($siblingProject);
    $other = Fx::redeemable();

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk();

    expect(Fx::visitorsOf($link))->toHaveCount(1)
        ->and(Fx::visitorsOf($siblingLink))->toBe([])
        ->and(Fx::visitorsOf($other['link']))->toBe([])
        ->and(Participant::query()->where('project_id', $siblingProject->id)->count())->toBe(0)
        ->and(Participant::query()->where('project_id', $other['project']->id)->count())->toBe(0)
        ->and(redeemCoreLinkRow($siblingLink)->uses_count)->toBe(0)
        ->and(redeemCoreLinkRow($other['link'])->uses_count)->toBe(0);
});

test('the request body decides only the identity: nothing else it carries is stored', function (): void {
    ['org' => $org, 'project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable(
        linkAttributes: ['label' => 'Stand'],
    );
    $other = Fx::redeemable();

    $this->postJson(Fx::REDEEM_URL, [
        ...Fx::redeemBody($token, Fx::identity('typed@example.test', 'Typed Name')),
        'project_id' => $other['project']->id,
        'organization_id' => $other['org']->id,
        'candidate_ref' => 'EXT-1',
        'role_code' => 'SRX',
        'lang' => 'it',
        'mode' => 'test',
        'status' => 'completato',
        'reusable_interview_link_id' => $other['link']->id,
        'external_id' => 99,
        'source' => 'attacker',
    ])->assertOk();

    $visitors = Fx::visitorsOf($link);
    expect($visitors)->toHaveCount(1);

    $visitor = $visitors[0];
    expect($visitor->project_id)->toBe($project->id)
        ->and($visitor->organization_id)->toBe($org->id)
        ->and($visitor->candidate_ref)->toStartWith('rlv_')
        ->and($visitor->display_name)->toBe('Typed Name')
        ->and($visitor->email)->toBe('typed@example.test')
        ->and($visitor->role_code)->toBe($project->role_code)
        ->and($visitor->language)->toBe($link->lang)
        ->and($visitor->mode)->toBe(ApiKeyMode::Live)
        ->and($visitor->status)->toBe('in_attesa')
        ->and($visitor->reusable_interview_link_id)->toBe($link->id)
        ->and($visitor->external_id)->toBeNull()
        ->and($visitor->source)->toBeNull();

    expect(Participant::query()->where('project_id', $other['project']->id)->count())->toBe(0);
});

// ─── Where the token is read from ────────────────────────────────────────────

test('the token is read only from the body field link_token', function (string $how): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    $response = match ($how) {
        'query link_token' => $this->postJson(Fx::REDEEM_URL.'?link_token='.$token, Fx::identity()),
        'body token' => $this->postJson(Fx::REDEEM_URL, ['token' => $token] + Fx::identity()),
        'X-Link-Token header' => $this->postJson(Fx::REDEEM_URL, Fx::identity(), ['X-Link-Token' => $token]),
        'Authorization bearer' => $this->postJson(Fx::REDEEM_URL, Fx::identity(), ['Authorization' => 'Bearer '.$token]),
    };

    $response->assertNotFound();
    expect($response->getContent())->toBe(Fx::NOT_FOUND_BODY)
        ->and(Fx::visitorsOf($link))->toBe([])
        ->and(redeemCoreLinkRow($link)->uses_count)->toBe(0);
})->with(['query link_token', 'body token', 'X-Link-Token header', 'Authorization bearer']);

test('a hostile token input cannot break the endpoint', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    // `?token[]=` once made the default guard's parser 500 before the
    // controller ran (the embed-exchange precedent). The outcome depends on
    // `link_token` alone.
    $this->postJson(Fx::REDEEM_URL.'?token[]=x', Fx::redeemBody($token) + ['token' => ['x', 'y']])->assertOk();

    expect(Fx::visitorsOf($link))->toHaveCount(1);
});

test('an Authorization header is ignored: it never authenticates and never changes the outcome', function (string $bearer): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token), ['Authorization' => $bearer])->assertOk();
    expect(Fx::visitorsOf($link))->toHaveCount(1);

    // The same header with an invalid token is the generic 404, never a 401.
    $bad = $this->postJson(Fx::REDEEM_URL, Fx::redeemBody('beai_rl_'.str_repeat('a', 43)), ['Authorization' => $bearer]);
    $bad->assertNotFound();
    expect($bad->getContent())->toBe(Fx::NOT_FOUND_BODY);
})->with([
    'garbage' => 'Bearer not-a-token',
    'a structurally valid but unrelated jwt' => 'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIiwiZXhwIjoxfQ.c2ln',
    'a different scheme' => 'Basic dXNlcjpwYXNz',
]);

// ─── The route ───────────────────────────────────────────────────────────────

test('the route is public, named-throttled and carries no tenant or stale-credential middleware', function (): void {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn (Route $route): bool => $route->uri() === 'api/reusable-links/redeem' && in_array('POST', $route->methods(), true));

    expect($route)->toBeInstanceOf(Route::class);

    $middleware = app('router')->gatherRouteMiddleware($route);

    // The NAMED limiter, never the numeric `throttle:N,M` form, whose bucket
    // key resolution calls `$request->user()` and 500s on `?token[]=`.
    expect($middleware)->toContain(ThrottleRequests::class.':reusable-link-redeem');

    foreach ($middleware as $name) {
        expect($name)->not->toMatch('/ThrottleRequests:\d/');
        expect($name)->not->toStartWith('auth');
        expect($name)->not->toContain('Authenticate');
    }

    // Same isolation set as `/sso/exchange`.
    expect($middleware)->not->toContain(TenantContext::class)
        ->and($middleware)->not->toContain(RejectStaleCredentials::class)
        ->and($route->excludedMiddleware())->toContain(TenantContext::class)
        ->and($route->excludedMiddleware())->toContain(RejectStaleCredentials::class);
});

// ─── Events and atomicity ────────────────────────────────────────────────────

test('ParticipantCreated fires exactly once per redemption, after the commit', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    // RefreshDatabase wraps the whole test in a transaction, so "after the
    // commit" is "back at the level the test started at".
    $baseline = DB::transactionLevel();
    $seen = [];
    Event::listen(ParticipantCreated::class, function (ParticipantCreated $event) use (&$seen): void {
        $seen[] = ['level' => DB::transactionLevel(), 'participant' => $event->participantId, 'project' => $event->projectId];
    });

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk();
    expect($seen)->toHaveCount(1)
        ->and($seen[0]['level'])->toBe($baseline);

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk();
    $visitors = Fx::visitorsOf($link);
    expect($seen)->toHaveCount(2)
        ->and([$seen[0]['participant'], $seen[1]['participant']])->toBe([$visitors[0]->id, $visitors[1]->id])
        ->and($seen[1]['project'])->toBe($link->project_id);
});

test('a failure to mint the credential rolls back the visitor and the counter and dispatches nothing', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();
    Event::fake([ParticipantCreated::class]);
    $before = redeemCoreParticipantCount();

    app()->instance(JWTAuth::class, new ThrowingJwtAuth);

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertStatus(500);

    $row = redeemCoreLinkRow($link);
    expect($row->uses_count)->toBe(0)
        ->and($row->last_used_at)->toBeNull()
        ->and(redeemCoreParticipantCount())->toBe($before)
        ->and(Fx::visitorsOf($link))->toBe([]);
    Event::assertNotDispatched(ParticipantCreated::class);
});

test('a link that was disabled over the admin API stops redeeming at once', function (): void {
    ['org' => $org, 'project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable();
    $jwt = authTokenForRole($org, 'admin');
    $id = PublicId::encode($link);

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk();

    resetAuthGuardState();
    $this->withToken($jwt)->deleteJson("/api/projects/{$project->id}/reusable-links/{$id}")->assertNoContent();

    resetAuthGuardState();
    $response = $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token));

    $response->assertNotFound();
    expect($response->getContent())->toBe(Fx::NOT_FOUND_BODY)
        ->and(Fx::visitorsOf($link))->toHaveCount(1)
        ->and(redeemCoreLinkRow($link)->uses_count)->toBe(1);
});
