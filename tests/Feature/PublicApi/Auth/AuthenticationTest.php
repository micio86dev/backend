<?php

declare(strict_types=1);

/**
 * BEAI Public API (`/v1`) auth middleware + scopes + tenancy guard
 * (public-api step 2, repointed to real operations in step 4 — G-23) —
 * SPEC.md §3.1 "Authentication", §3.2 "Errors", §8 Q1.
 *
 * T-AUTH-001..005/007 now exercise the real `GET /v1/organization` and
 * `GET /v1/projects(/{id})` operations added in step 4, validated with
 * `assertMatchesContract()`/`assertProblemMatchesContract()` — this file's
 * own original docblock already called this out: "step 4 adds `GET
 * /v1/organization`, which will replace `/api/v1/_probe` as the auth-path
 * exemplar".
 *
 * A probe route survives for exactly two cases a real `/v1` operation
 * cannot cover:
 *   - T-AUTH-009 needs a response body naming the resolved `client_id` to
 *     prove two DIFFERENT clients sharing a `key_prefix` (same organization)
 *     resolve to the correct one — `GET /v1/organization` returns the same
 *     organization body for both and cannot disambiguate them.
 *   - The legacy pre-migration-row case below also reads `client_id` for
 *     the same reason.
 * Every other probe route this file used to register
 * (`/_probe/scoped`, `/_probe/projects`, `/_probe/projects/{project}`,
 * `/_probe/projects/{project}/scoped`) is gone: `GET /v1/projects` and
 * `GET /v1/projects/{id}` now cover exactly what they existed to prove.
 */

use App\Enums\ApiKeyMode;
use App\Http\Middleware\PublicApi\AuthenticatePublicApi;
use App\Http\Middleware\PublicApi\PublicApiTenantContext;
use App\Http\Middleware\PublicApi\RejectApiKeyInQuery;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
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

            return response()->json(['ok' => true, 'client_id' => $client->id]);
        });
    });
});

// ─── T-AUTH-001: valid key ────────────────────────────────────────────────────

test('T-AUTH-001: valid live key → 200 on GET /v1/organization, tenant stamped', function (): void {
    $org = Organization::factory()->create(['name' => 'Acme']);
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id, 'abilities' => []]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/organization');

    $response->assertOk()
        ->assertJsonPath('id', PublicId::encode($org->fresh()))
        ->assertJsonPath('name', 'Acme')
        ->assertJsonPath('mode', 'live');
    $this->assertMatchesContract($response, 'GET', '/organization');
});

// ─── T-AUTH-002: revoked / expired ────────────────────────────────────────────

test('T-AUTH-002: a revoked key → 401 invalid_api_key, problem+json valid', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->withRawKey($rawKey)->inactive()->create([
        'organization_id' => $org->id,
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/organization');

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
        ->getJson('/api/v1/organization');

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
            ->getJson('/api/v1/organization');

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
// empty token after "Bearer " — had no direct coverage. Kept on the probe
// route: these prove a middleware-level behaviour independent of any
// specific operation, and the probe's minimal body keeps that independence
// explicit. ────────────────────────────────────────────────────────────────

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
        ->getJson('/api/v1/organization?api_key='.$rawKey);

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
        ->getJson('/api/v1/projects');

    $withoutScope->assertStatus(403)
        ->assertJsonPath('code', 'insufficient_scope')
        ->assertJsonPath('detail', 'Requires projects:read');
    $this->assertProblemMatchesContract($withoutScope, 403);

    $rawKeyWithScope = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($rawKeyWithScope)->create([
        'organization_id' => $org->id,
        'abilities' => ['projects:read'],
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyWithScope])
        ->getJson('/api/v1/projects')
        ->assertOk();
});

// ─── T-AUTH-006: tenancy isolation ─────────────────────────────────────────────

test('T-AUTH-006: GET /v1/projects returns each organization\'s own rows only, and a cross-org GET /v1/projects/{id} 404s', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $rawKeyA = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($rawKeyA)->create(['organization_id' => $orgA->id, 'abilities' => ['projects:read']]);

    $rawKeyB = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($rawKeyB)->create(['organization_id' => $orgB->id, 'abilities' => ['projects:read']]);

    TenantContextScope::runFor($orgA->id, function () use ($orgA): void {
        $avatarTemplate = AvatarTemplate::create(['name' => 'Ada', 'provider' => 'heygen', 'config' => []]);
        Project::factory()->count(2)->create(['organization_id' => $orgA->id, 'avatar_template_id' => $avatarTemplate->id]);
    });
    TenantContextScope::runFor($orgB->id, function () use ($orgB): void {
        $avatarTemplate = AvatarTemplate::create(['name' => 'Ada', 'provider' => 'heygen', 'config' => []]);
        Project::factory()->count(5)->create(['organization_id' => $orgB->id, 'avatar_template_id' => $avatarTemplate->id]);
    });

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyA])
        ->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyB])
        ->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(5, 'data');

    $projectOfB = TenantContextScope::runFor($orgB->id, fn () => Project::where('organization_id', $orgB->id)->first());

    // Key A must never resolve a Project belonging to org B — 404, not 403,
    // so cross-org existence is never revealed (SPEC.md §3.2 NotFound).
    $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyA])
        ->getJson('/api/v1/projects/'.PublicId::encode($projectOfB))
        ->assertNotFound();
});

// ─── T-AUTH-010: G-35 middleware-priority ordering, on a real scoped route ──────
//
// Part A follow-up 3: the missing proof G-35's fix (bootstrap/app.php's
// `prependToPriorityList()` chain) actually holds on the FULL real `/v1`
// stack — `AssignRequestId → RejectApiKeyInQuery → AuthenticatePublicApi →
// PublicApiTenantContext → RateLimitPublicApi → RequireScope →
// SubstituteBindings` — not just on the isolated probe route T-AUTH-005/009
// used before step 4 added a real scope-protected, model-bound route.
// `GET /v1/projects` carries BOTH `scope:projects:read` AND
// `SubstituteBindings` (via `{project}` on the detail route below), so it
// is the one real operation that can actually exercise the ordering bug
// G-35 fixed: were `RequireScope` still pulled ahead of
// `AuthenticatePublicApi`, a `beai_test_` key would either fail the wrong
// check or silently authenticate as whatever client an EARLIER request
// resolved.
test('T-AUTH-010: a beai_test_ key succeeds on scope-protected GET /v1/projects, and two sequential different-key requests never cross-authenticate', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $rawTestKey = ApiKeyGenerator::generate(ApiKeyMode::Test);
    ApiClient::factory()->withRawKey($rawTestKey)->create([
        'organization_id' => $orgA->id,
        'mode' => ApiKeyMode::Test,
        'abilities' => ['projects:read'],
    ]);

    // A test-mode key reaches a scope-protected, SubstituteBindings-bound
    // `/v1` route at all — proves `AuthenticatePublicApi` (which resolves
    // `mode`) and `RequireScope` both ran, in the RIGHT order, ahead of
    // route-model binding.
    $this->withHeaders(['Authorization' => 'Bearer '.$rawTestKey])
        ->getJson('/api/v1/projects')
        ->assertOk();

    // Two DIFFERENT organizations' keys, hit in sequence against the SAME
    // real route+guard, must each resolve their OWN client — never the
    // other's, the exact symptom G-35 describes (`RequestGuard` caching a
    // stale resolved user across requests when `RequireScope` ran before
    // `AuthenticatePublicApi`).
    $rawKeyA = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($rawKeyA)->create(['organization_id' => $orgA->id, 'abilities' => ['projects:read']]);

    $rawKeyB = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($rawKeyB)->create(['organization_id' => $orgB->id, 'abilities' => ['projects:read']]);

    TenantContextScope::runFor($orgA->id, function () use ($orgA): void {
        $avatarTemplate = AvatarTemplate::create(['name' => 'Ada', 'provider' => 'heygen', 'config' => []]);
        Project::factory()->count(1)->create(['organization_id' => $orgA->id, 'avatar_template_id' => $avatarTemplate->id]);
    });
    TenantContextScope::runFor($orgB->id, function () use ($orgB): void {
        $avatarTemplate = AvatarTemplate::create(['name' => 'Ada', 'provider' => 'heygen', 'config' => []]);
        Project::factory()->count(3)->create(['organization_id' => $orgB->id, 'avatar_template_id' => $avatarTemplate->id]);
    });

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyA])
        ->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyB])
        ->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(3, 'data');

    // Repeat A after B, in the SAME PHP process/guard instance, to catch a
    // guard-level cache pointing at the wrong (LAST resolved) client.
    $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyA])
        ->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ─── T-AUTH-007: mode + browser-origin defense ────────────────────────────────

test('T-AUTH-007: a beai_test_ key stamps ApiMode test, a live key stamps live', function (): void {
    $org = Organization::factory()->create();

    $liveKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($liveKey)->create(['organization_id' => $org->id, 'mode' => 'live', 'abilities' => []]);

    $testKey = ApiKeyGenerator::generate(ApiKeyMode::Test);
    ApiClient::factory()->withRawKey($testKey)->create(['organization_id' => $org->id, 'mode' => 'test', 'abilities' => []]);

    $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])
        ->getJson('/api/v1/organization')
        ->assertOk()
        ->assertJsonPath('mode', 'live');

    $this->withHeaders(['Authorization' => 'Bearer '.$testKey])
        ->getJson('/api/v1/organization')
        ->assertOk()
        ->assertJsonPath('mode', 'test');
});

test('T-AUTH-007: a live key with an Origin header → 401 browser_origin_forbidden', function (): void {
    $org = Organization::factory()->create();
    $liveKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($liveKey)->create(['organization_id' => $org->id, 'mode' => 'live']);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$liveKey,
        'Origin' => 'https://evil.example',
    ])->getJson('/api/v1/organization');

    $response->assertStatus(401)->assertJsonPath('code', 'browser_origin_forbidden');
    $this->assertProblemMatchesContract($response, 401);
});

test('T-AUTH-007: a test key with an Origin header is allowed', function (): void {
    $org = Organization::factory()->create();
    $testKey = ApiKeyGenerator::generate(ApiKeyMode::Test);
    ApiClient::factory()->withRawKey($testKey)->create(['organization_id' => $org->id, 'mode' => 'test', 'abilities' => []]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$testKey,
        'Origin' => 'https://developer.example',
    ])->getJson('/api/v1/organization')
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
