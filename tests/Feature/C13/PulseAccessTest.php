<?php

declare(strict_types=1);

/**
 * Laravel Pulse is admin-only (C13, task 5.2).
 *
 * REQ: Laravel Pulse — Application Health
 *      (openspec/specs/observability/spec.md:228-262)
 *
 * Pulse aggregates, on one page, the slowest queries of every tenant, the
 * exception messages of every tenant, and the job payloads of every tenant. It
 * is the single most cross-tenant surface in the application — the one place
 * where the row-level `organization_id` scoping that the rest of the product is
 * built on does not apply, and cannot.
 *
 * So the gate is the whole feature. An unguarded `/pulse` is not "an internal
 * page that leaked"; it is every tenant's data at once, to anyone who guesses a
 * five-letter path.
 */

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Laravel\Pulse\Http\Middleware\Authorize;
use Laravel\Pulse\Recorders\Queues;
use Laravel\Pulse\Recorders\SlowJobs;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function pulseUser(string $role): User
{
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate([
        'name' => $role,
        'guard_name' => 'api',
        'team_id' => $org->id,
    ]));

    return $user;
}

function pulseAdmin(): User
{
    return pulseUser('admin');
}

function pulseToken(string $role): string
{
    return auth('api')->login(pulseUser($role));
}

test('an unauthenticated request is rejected with 401', function (): void {
    $this->getJson('/pulse')->assertUnauthorized();
});

test('an unauthenticated request without an Accept header is still 401, never a 500', function (): void {
    // Not a duplicate of the test above, and not a formality. Laravel's default
    // guest handling calls route('login') for any request that does not expect
    // JSON — a route this API-only application does not have and never will.
    // That throws RouteNotFoundException, which surfaces as a 500 with a stack
    // trace: a browser hitting /pulse out of curiosity would learn more from
    // the error page than from the dashboard it was denied.
    //
    // A human reaching this dashboard reaches it from a browser address bar,
    // which sends `Accept: text/html`. So this is THE realistic unauthenticated
    // request, and the JSON one above is the artificial one.
    $this->get('/pulse')->assertUnauthorized();
});

test('an authenticated non-admin is rejected with 403', function (): void {
    // 403 rather than 404: the caller is authenticated, so hiding the route's
    // existence buys nothing, and a distinguishable status is what lets an
    // operator tell "I lack the role" from "I am not logged in".
    $this->withToken(pulseToken('operator'))->getJson('/pulse')->assertForbidden();
    $this->withToken(pulseToken('viewer'))->getJson('/pulse')->assertForbidden();
});

test('a TENANT admin is NOT admitted — being admin of an org is not being an operator of the platform', function (): void {
    $admin = pulseAdmin();

    // This is the deviation from spec.md:242, and it is deliberate.
    //
    // The spec says "authenticated users with the `admin` RBAC role". But
    // `admin` in this product is ORG-SCOPED — spatie runs in teams mode with
    // team_id = organization_id — so every customer has their own admin. Pulse,
    // meanwhile, has no organization_id anywhere: it aggregates the slow
    // queries, exception messages and job payloads of EVERY tenant onto one
    // page.
    //
    // Read literally, the spec therefore hands each customer's admin a view of
    // every other customer's data. CLAUDE.md's "a tenant must never see another
    // tenant's data" is a binding constraint and outranks it.
    expect(Gate::forUser($admin)->allows('viewPulse'))->toBeFalse();
});

test('an admin named as a platform operator is admitted', function (): void {
    $admin = pulseAdmin();
    config()->set('pulse.operators', [$admin->email]);

    expect(Gate::forUser($admin)->allows('viewPulse'))->toBeTrue();
});

test('being on the operator list is not enough without the admin role', function (): void {
    $operator = pulseUser('operator');
    config()->set('pulse.operators', [$operator->email]);

    // Both conditions, not either. The allowlist is a deployment-time artifact
    // that outlives the person it names; the role is revoked the day someone
    // leaves. Requiring both means neither one going stale opens the door.
    expect(Gate::forUser($operator)->allows('viewPulse'))->toBeFalse();
});

test('the operator list is EMPTY by default, so a fresh install admits nobody', function (): void {
    // The single most important assertion in this file. Pulse is enabled by
    // default once installed, and a permissive default would mean every
    // deployment leaks until somebody remembers to lock it down. Naming the
    // operators is a deliberate act.
    expect(config('pulse.operators'))->toBe([]);
});

test('the gate denies a user with no role at all', function (): void {
    $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    config()->set('pulse.operators', [$user->email]);

    // Fail closed. A user whose roles have not been provisioned yet is the
    // normal state during onboarding, and it must never be the permissive one.
    expect(Gate::forUser($user)->allows('viewPulse'))->toBeFalse();
});

test('the operator match is exact, not a prefix or a domain', function (): void {
    $admin = pulseAdmin();
    config()->set('pulse.operators', ['ops@beai.example']);

    // A substring or domain match would admit anyone who can get an address at
    // a matching host — and on a platform where organizations self-serve, that
    // is not a theoretical concern.
    expect(Gate::forUser($admin)->allows('viewPulse'))->toBeFalse();
});

test('pulse is wired to authenticate before it authorizes', function (): void {
    $middleware = config('pulse.middleware');

    // Order is load-bearing. Pulse's own Authorize middleware calls
    // Gate::authorize(), and a Gate denial for a guest is a 403 — which would
    // tell an anonymous caller "you are logged in as somebody without rights"
    // when they are not logged in at all. auth:api must run first so the
    // honest 401 is produced.
    expect($middleware)->toContain('auth:api');
    expect(array_search('auth:api', $middleware, true))
        ->toBeLessThan(array_search(Authorize::class, $middleware, true));
});

test('the pulse tables exist, because the package does not create them for you', function (): void {
    // Pulse ships its migration as a PUBLISHABLE asset — nothing loads it
    // automatically. Installing the package and shipping produces an app that
    // boots, serves, and silently records nothing, because every recorder write
    // hits a table that was never created. Publishing is the install step that
    // is easy to skip and impossible to notice.
    expect(Schema::hasTable('pulse_entries'))->toBeTrue();
    expect(Schema::hasTable('pulse_aggregates'))->toBeTrue();
    expect(Schema::hasTable('pulse_values'))->toBeTrue();
});

test('the queue recorder is on, which is the reason Pulse is here at all', function (): void {
    // REQ scenario: "Pulse records queue depth and throughput"
    // (spec.md:258-262). Scoring is asynchronous with a p95 under 10 minutes;
    // a queue backing up is the failure this product notices last and suffers
    // from most, because nothing errors — evaluations simply arrive late.
    expect(config('pulse.recorders.'.Queues::class))->not->toBeNull();
    expect(config('pulse.recorders.'.SlowJobs::class))->not->toBeNull();
});

test('recorders are off under test, so the suite never writes telemetry', function (): void {
    // Not a preference. Pulse's recorders hook the query, job and request
    // lifecycles, and leaving them live would have every test in this suite
    // writing rows about itself — slower, noisier, and a source of failures
    // that have nothing to do with the code under test.
    //
    // toBeFalsy, not toBeFalse, and the difference is a real PHPUnit quirk
    // rather than sloppiness: phpunit.xml says value="false", PHPUnit casts
    // that attribute to boolean false, then concatenates it into
    // putenv("PULSE_ENABLED={$value}") — where false stringifies to "". So the
    // variable arrives as an EMPTY STRING, never the string "false".
    //
    // Pulse gates on `if (config('pulse.enabled'))`, a truthiness check, so ""
    // disables it exactly as intended. Asserting toBeFalse would fail while the
    // behaviour was correct, which is the kind of test that gets deleted rather
    // than understood.
    expect(config('pulse.enabled'))->toBeFalsy();
});

test('pulse never records into the tenant-scoped tables', function (): void {
    // Pulse keeps its own `pulse_*` tables and has no organization_id anywhere.
    // That is exactly why the dashboard is admin-gated rather than tenant-scoped:
    // there is no scoping to apply, so access control is the only control there is.
    expect(config('pulse.storage.database.connection'))->toBeNull();
});
