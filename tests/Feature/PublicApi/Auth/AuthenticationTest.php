<?php

declare(strict_types=1);

/**
 * BEAI Public API (`/v1`) auth middleware + scopes + tenancy guard
 * (public-api step 2) — SPEC.md §3.1 "Authentication", §3.2 "Errors", §8 Q1.
 *
 * T-AUTH-001..009 plus a tenancy-isolation case and a legacy-row case.
 *
 * Test-only probe routes, registered here rather than reusing a real
 * endpoint — none exists yet (step 4 adds `GET /v1/organization`, which will
 * replace `/api/v1/_probe` as the auth-path exemplar). Every probe carries
 * the EXACT `/v1` middleware stack `routes/api.php` registers for the real
 * group: RejectApiKeyInQuery → AuthenticatePublicApi → PublicApiTenantContext
 * → SubstituteBindings, mirroring the pattern
 * `tests/Feature/C5/GuardResolutionTest.php` and
 * `tests/Feature/C5/TenantContextM2mTest.php` already use for the internal
 * M2M guard/middleware.
 *
 * Bodies from `App\Support\PublicApi\Problem` are asserted against the
 * contract's `Problem` schema via `assertProblemMatchesContract()`
 * (schema-level only — these probe paths are not real contract operations).
 */

use App\Http\Middleware\PublicApi\AuthenticatePublicApi;
use App\Http\Middleware\PublicApi\PublicApiTenantContext;
use App\Http\Middleware\PublicApi\RejectApiKeyInQuery;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\ApiMode;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    Route::middleware([
        RejectApiKeyInQuery::class,
        AuthenticatePublicApi::class,
        PublicApiTenantContext::class,
        SubstituteBindings::class,
    ])->prefix('api/v1')->group(function (): void {
        Route::get('/_probe', function () {
            /** @var ApiClient $client */
            $client = auth('api-m2m')->user();

            return response()->json([
                'ok' => true,
                'client_id' => $client->id,
                'org_id' => app(TenantResolver::class)->getOrgId(),
                'mode' => app(ApiMode::class)->get(),
            ]);
        });

        Route::get('/_probe/scoped', function () {
            return response()->json(['ok' => true]);
        })->middleware('scope:interviews:read');

        Route::get('/_probe/projects', function () {
            return response()->json(['count' => Project::count()]);
        });

        Route::get('/_probe/projects/{project}', function (Project $project) {
            return response()->json(['id' => $project->id]);
        });

        // Review follow-up (finding 6): a route-model-bound {project} behind
        // a scope requirement — proves RequireScope runs BEFORE
        // SubstituteBindings even when the bound id does not exist.
        Route::get('/_probe/projects/{project}/scoped', function (Project $project) {
            return response()->json(['id' => $project->id]);
        })->middleware('scope:projects:read');
    });
});

// ─── T-AUTH-001: valid key ────────────────────────────────────────────────────

test('T-AUTH-001: valid live key → 200 on the probe, tenant stamped', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate('live');

    $client = ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/_probe')
        ->assertOk()
        ->assertJsonPath('client_id', $client->id)
        ->assertJsonPath('org_id', $org->id)
        ->assertJsonPath('mode', 'live');
});

// ─── T-AUTH-002: revoked / expired ────────────────────────────────────────────

test('T-AUTH-002: a revoked key → 401 invalid_api_key, problem+json valid', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->withRawKey($rawKey)->inactive()->create([
        'organization_id' => $org->id,
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/_probe');

    $response->assertStatus(401)->assertJsonPath('code', 'invalid_api_key');
    $this->assertProblemMatchesContract($response, 401);
});

test('T-AUTH-002: an expired key → 401 invalid_api_key, problem+json valid', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->withRawKey($rawKey)->expired()->create([
        'organization_id' => $org->id,
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/_probe');

    $response->assertStatus(401)->assertJsonPath('code', 'invalid_api_key');
    $this->assertProblemMatchesContract($response, 401);
});

// ─── T-AUTH-003: wrong prefix / malformed / unknown ───────────────────────────

test('T-AUTH-003: wrong prefix, malformed, and unknown keys all → 401 invalid_api_key with an identical, non-enumerating body shape', function (): void {
    $org = Organization::factory()->create();
    $realRawKey = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($realRawKey)->create(['organization_id' => $org->id]);

    // "Wrong prefix": a real key's random suffix, under the WRONG marker.
    $wrongPrefix = 'beai_test_'.substr($realRawKey, strlen('beai_live_'));
    // Malformed: does not carry a recognised beai_live_/beai_test_ marker.
    $malformed = 'not-a-beai-key-at-all';
    // Unknown: correctly shaped, but no row anywhere has this hash.
    $unknown = ApiKeyGenerator::generate();

    $bodies = [];

    foreach (['wrong-prefix' => $wrongPrefix, 'malformed' => $malformed, 'unknown' => $unknown] as $label => $candidate) {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$candidate])
            ->getJson('/api/v1/_probe');

        $response->assertStatus(401);
        $this->assertProblemMatchesContract($response, 401);

        $body = $response->json();
        unset($body['request_id']); // the only field that legitimately varies per request

        $bodies[$label] = $body;
    }

    expect($bodies['wrong-prefix'])->toBe($bodies['malformed']);
    expect($bodies['malformed'])->toBe($bodies['unknown']);
    expect($bodies['wrong-prefix']['code'])->toBe('invalid_api_key');
});

// ─── Review follow-up (finding 5): the early-return branches of
// AuthenticatePublicApi::handle() — no header, a non-Bearer scheme, and an
// empty token after "Bearer " — had no direct coverage. Every one of them
// must 401 with the SAME invalid_api_key problem+json body carrying
// WWW-Authenticate: Bearer. ────────────────────────────────────────────────

test('no Authorization header at all → 401 invalid_api_key with WWW-Authenticate: Bearer', function (): void {
    $response = $this->getJson('/api/v1/_probe');

    $response->assertStatus(401)
        ->assertJsonPath('code', 'invalid_api_key')
        ->assertHeader('WWW-Authenticate', 'Bearer');
    $this->assertProblemMatchesContract($response, 401);
});

test('a non-Bearer scheme (Basic) → 401 invalid_api_key with WWW-Authenticate: Bearer', function (): void {
    $response = $this->withHeaders(['Authorization' => 'Basic '.base64_encode('user:pass')])
        ->getJson('/api/v1/_probe');

    $response->assertStatus(401)
        ->assertJsonPath('code', 'invalid_api_key')
        ->assertHeader('WWW-Authenticate', 'Bearer');
    $this->assertProblemMatchesContract($response, 401);
});

test('"Bearer " with an empty token → 401 invalid_api_key with WWW-Authenticate: Bearer', function (): void {
    $response = $this->withHeaders(['Authorization' => 'Bearer '])
        ->getJson('/api/v1/_probe');

    $response->assertStatus(401)
        ->assertJsonPath('code', 'invalid_api_key')
        ->assertHeader('WWW-Authenticate', 'Bearer');
    $this->assertProblemMatchesContract($response, 401);
});

// ─── T-AUTH-004: key in query ─────────────────────────────────────────────────

test('T-AUTH-004: api_key in the query string → 400 api_key_in_query, even with a valid bearer header', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/_probe?api_key='.$rawKey);

    $response->assertStatus(400)->assertJsonPath('code', 'api_key_in_query');
    $this->assertProblemMatchesContract($response, 400);
});

// ─── T-AUTH-005: scopes ───────────────────────────────────────────────────────

test('T-AUTH-005: missing scope → 403 insufficient_scope naming the scope; present scope → 200', function (): void {
    $org = Organization::factory()->create();

    $rawKeyWithoutScope = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($rawKeyWithoutScope)->create([
        'organization_id' => $org->id,
        'abilities' => ['participants:read'],
    ]);

    $withoutScope = $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyWithoutScope])
        ->getJson('/api/v1/_probe/scoped');

    $withoutScope->assertStatus(403)
        ->assertJsonPath('code', 'insufficient_scope')
        ->assertJsonPath('detail', 'Requires interviews:read');
    $this->assertProblemMatchesContract($withoutScope, 403);

    $rawKeyWithScope = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($rawKeyWithScope)->create([
        'organization_id' => $org->id,
        'abilities' => ['interviews:read'],
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyWithScope])
        ->getJson('/api/v1/_probe/scoped')
        ->assertOk();
});

// ─── T-AUTH-006: tenancy isolation ─────────────────────────────────────────────

test('T-AUTH-006: a Project-counting probe returns each organization\'s own count only, and a cross-org {project} 404s', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $rawKeyA = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($rawKeyA)->create(['organization_id' => $orgA->id]);

    $rawKeyB = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($rawKeyB)->create(['organization_id' => $orgB->id]);

    // Project::factory() nests a FrameworkVersion factory, itself
    // TenantScoped — outside an HTTP request (this is a plain PHP setup
    // step, not a request through PublicApiTenantContext) TenantResolver has
    // no ambient org, so tenant context is established explicitly, exactly
    // as tests/Feature/C4/ProjectWebhookSecretPresenceTest.php does.
    TenantContextScope::runFor($orgA->id, fn () => Project::factory()->count(2)->create(['organization_id' => $orgA->id]));
    TenantContextScope::runFor($orgB->id, fn () => Project::factory()->count(5)->create(['organization_id' => $orgB->id]));

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyA])
        ->getJson('/api/v1/_probe/projects')
        ->assertOk()
        ->assertJsonPath('count', 2);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyB])
        ->getJson('/api/v1/_probe/projects')
        ->assertOk()
        ->assertJsonPath('count', 5);

    $projectOfB = Project::where('organization_id', $orgB->id)->first();

    // Key A must never resolve a Project belonging to org B — 404, not 403,
    // so cross-org existence is never revealed (SPEC.md §3.2 NotFound).
    $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyA])
        ->getJson('/api/v1/_probe/projects/'.$projectOfB->id)
        ->assertNotFound();
});

// ─── Review follow-up (finding 6): scope check runs BEFORE route-model
// binding ───────────────────────────────────────────────────────────────────

test('a key lacking the scope, on a NON-EXISTENT bound {project}, still gets 403 — proving RequireScope runs before SubstituteBindings', function (): void {
    $org = Organization::factory()->create();

    $rawKeyWithoutScope = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($rawKeyWithoutScope)->create([
        'organization_id' => $org->id,
        'abilities' => ['interviews:read'], // deliberately missing projects:read
    ]);

    // 999999999 resolves to no row at all — if SubstituteBindings ran FIRST,
    // this would 404 before RequireScope ever got a chance to run, revealing
    // (via the 404-vs-403 split) that the middleware ordering was wrong.
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyWithoutScope])
        ->getJson('/api/v1/_probe/projects/999999999/scoped');

    $response->assertStatus(403)
        ->assertJsonPath('code', 'insufficient_scope')
        ->assertJsonPath('detail', 'Requires projects:read');
    $this->assertProblemMatchesContract($response, 403);
});

// ─── T-AUTH-007: mode + browser-origin defense ────────────────────────────────

test('T-AUTH-007: a beai_test_ key stamps ApiMode test, a live key stamps live', function (): void {
    $org = Organization::factory()->create();

    $liveKey = ApiKeyGenerator::generate('live');
    ApiClient::factory()->withRawKey($liveKey)->create(['organization_id' => $org->id, 'mode' => 'live']);

    $testKey = ApiKeyGenerator::generate('test');
    ApiClient::factory()->withRawKey($testKey)->create(['organization_id' => $org->id, 'mode' => 'test']);

    $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])
        ->getJson('/api/v1/_probe')
        ->assertOk()
        ->assertJsonPath('mode', 'live');

    $this->withHeaders(['Authorization' => 'Bearer '.$testKey])
        ->getJson('/api/v1/_probe')
        ->assertOk()
        ->assertJsonPath('mode', 'test');
});

test('T-AUTH-007: a live key with an Origin header → 401 browser_origin_forbidden', function (): void {
    $org = Organization::factory()->create();
    $liveKey = ApiKeyGenerator::generate('live');
    ApiClient::factory()->withRawKey($liveKey)->create(['organization_id' => $org->id, 'mode' => 'live']);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$liveKey,
        'Origin' => 'https://evil.example',
    ])->getJson('/api/v1/_probe');

    $response->assertStatus(401)->assertJsonPath('code', 'browser_origin_forbidden');
    $this->assertProblemMatchesContract($response, 401);
});

test('T-AUTH-007: a test key with an Origin header is allowed', function (): void {
    $org = Organization::factory()->create();
    $testKey = ApiKeyGenerator::generate('test');
    ApiClient::factory()->withRawKey($testKey)->create(['organization_id' => $org->id, 'mode' => 'test']);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$testKey,
        'Origin' => 'https://developer.example',
    ])->getJson('/api/v1/_probe')
        ->assertOk()
        ->assertJsonPath('mode', 'test');
});

// ─── T-AUTH-008: hash-only storage ─────────────────────────────────────────────

test('T-AUTH-008: the DB never stores the raw key or its random part; key_prefix is the marker plus 8 chars', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = createAuthenticationTestAdmin($org);

    $response = $this->withToken($token)->postJson('/api/m2m/clients', [
        'name' => 'Hash-Only Storage Client',
        'abilities' => ['interviews:read'],
    ]);

    $response->assertCreated();
    $rawKey = $response->json('api_key');
    $randomPart = substr($rawKey, strlen('beai_live_'));

    $row = DB::table('api_clients')->where('id', $response->json('data.id'))->first();

    expect($row->key_hash)->not->toBe($rawKey);
    expect($row->key_hash)->not->toContain($randomPart);
    expect((string) $row->key_prefix)->not->toContain($randomPart);
    expect($row->key_prefix)->toBe('beai_live_'.substr($randomPart, 0, 8));
});

// ─── T-AUTH-009: digest comparison, not raw comparison ─────────────────────────

test('T-AUTH-009: two clients sharing a key_prefix are disambiguated by hash_equals, and the raw key never reaches a SQL binding', function (): void {
    $org = Organization::factory()->create();

    // Both share the marker AND the first 8 chars of the random part
    // ('AAAAAAAA') — a deliberate key_prefix collision — but diverge after
    // that, so their hashes differ.
    $rawKey1 = 'beai_live_AAAAAAAA'.str_repeat('1', 80);
    $rawKey2 = 'beai_live_AAAAAAAA'.str_repeat('2', 80);

    $client1 = ApiClient::factory()->withRawKey($rawKey1)->create(['organization_id' => $org->id]);
    $client2 = ApiClient::factory()->withRawKey($rawKey2)->create(['organization_id' => $org->id]);

    expect($client1->key_prefix)->toBe($client2->key_prefix);
    expect($client1->key_hash)->not->toBe($client2->key_hash);

    DB::enableQueryLog();

    $response1 = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey1])->getJson('/api/v1/_probe');
    $response2 = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey2])->getJson('/api/v1/_probe');

    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    $response1->assertOk()->assertJsonPath('client_id', $client1->id);
    $response2->assertOk()->assertJsonPath('client_id', $client2->id);

    foreach ($queryLog as $entry) {
        foreach ($entry['bindings'] as $binding) {
            if (! is_string($binding)) {
                continue;
            }

            expect($binding)->not->toContain($rawKey1);
            expect($binding)->not->toContain($rawKey2);
            expect($binding)->not->toContain(str_repeat('1', 80));
            expect($binding)->not->toContain(str_repeat('2', 80));
        }
    }
});

// ─── Legacy: pre-migration row (key_prefix = null) ────────────────────────────

test('a pre-migration row (key_prefix null) still authenticates through the legacy hash fallback', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    $client = ApiClient::factory()->withRawKey($rawKey)->preMigrationRow()->create([
        'organization_id' => $org->id,
    ]);

    expect($client->key_prefix)->toBeNull();

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/_probe')
        ->assertOk()
        ->assertJsonPath('client_id', $client->id);

    // Same fallback, same resolver, on the EXISTING M2M whoami endpoint.
    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/m2m/whoami')
        ->assertOk();
});

/**
 * Local helper mirroring `tests/Feature/C5/ApiClientStoreTest.php`'s own
 * `storeAdminUser()` — duplicated rather than shared because Pest test files
 * are independent global-function scopes with no import mechanism between
 * them.
 *
 * @return array{user: User, token: string}
 */
function createAuthenticationTestAdmin(Organization $org): array
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);
    $token = auth('api')->login($user);

    return ['user' => $user, 'token' => $token];
}
