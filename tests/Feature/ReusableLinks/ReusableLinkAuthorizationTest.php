<?php

declare(strict_types=1);

/**
 * Who may reach the reusable link admin operations (reusable-interview-links, B2).
 *
 * Every operation requires an authenticated backoffice user holding
 * `ParticipantPolicy::create` (admin and operator) inside the tenant context.
 * The matrix of the spec, asserted as real requests:
 *
 *   admin / operator of the org      -> allowed
 *   viewer of the org                -> 403, before the project is resolved
 *   another organization's project   -> 404 (never 403: no existence oracle)
 *   M2M key, candidate JWT, nothing  -> 401
 *   a raw `beai_rl_` link token      -> 401 (it is not a credential)
 *   a bare superadmin (no org)       -> 409 on the writes
 *
 * A denied request must also leave the table untouched, so each test asserts
 * the row count as well as the status.
 *
 * REQ: Authorization Matrix For The Admin Operations
 *      (sdd/reusable-interview-links/spec/reusable-interview-links)
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Models\User;
use App\Services\ApiKeyGenerator;
use App\Services\ReusableLinkTokenGenerator;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Helpers\ReusableLinkFixtures as Fx;

/**
 * Send one of the admin operations with the given bearer (or none).
 *
 * @return TestResponse<Response>
 */
function reusableLinkAuthCall(string $operation, ?string $bearer, Project $project, ?ReusableInterviewLink $link = null)
{
    // One test sends several requests with different credentials; the guard
    // caches the first token it parsed unless it is reset in between.
    resetAuthGuardState();
    $client = test()->flushHeaders();
    $client = $bearer === null ? $client : $client->withToken($bearer);
    $base = "/api/projects/{$project->id}/reusable-links";

    return match ($operation) {
        'create' => $client->postJson($base, ['label' => 'Stand']),
    };
}

beforeEach(function (): void {
    Fx::configureOrigin();
});

// ─── Allowed roles ───────────────────────────────────────────────────────────

test('an admin and an operator are allowed to create', function (string $operation, int $expectedStatus): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);

    foreach (['admin', 'operator'] as $role) {
        reusableLinkAuthCall($operation, authTokenForRole($org, $role), $project)->assertStatus($expectedStatus);
    }
})->with([
    'create' => ['create', 201],
]);

// ─── Denied callers ──────────────────────────────────────────────────────────

test('a viewer is refused with 403 and nothing is written', function (string $operation): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);

    reusableLinkAuthCall($operation, authTokenForRole($org, 'viewer'), $project)->assertForbidden();

    expect(Fx::totalRows())->toBe(0);
})->with(['create']);

test('the role check runs before the project is resolved, so a viewer is 403 even for a foreign project', function (string $operation): void {
    $org = Organization::factory()->create();
    $foreignProject = Fx::project(Organization::factory()->create());

    reusableLinkAuthCall($operation, authTokenForRole($org, 'viewer'), $foreignProject)->assertForbidden();
})->with(['create']);

test('an admin of another organization reaches a 404, never a 403', function (string $operation): void {
    $org = Organization::factory()->create();
    $foreignProject = Fx::project(Organization::factory()->create());

    $response = reusableLinkAuthCall($operation, authTokenForRole($org, 'admin'), $foreignProject);

    $response->assertNotFound();
    expect(Fx::totalRows())->toBe(0);
    expect((string) $response->getContent())->not->toContain((string) $foreignProject->name);
})->with(['create']);

test('a caller with no credential is 401', function (string $operation): void {
    $project = Fx::project(Organization::factory()->create());

    reusableLinkAuthCall($operation, null, $project)->assertUnauthorized();

    expect(Fx::totalRows())->toBe(0);
})->with(['create']);

test('an M2M API key is refused with 401', function (string $operation): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    $rawKey = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'is_active' => true,
        'abilities' => ['participants:read', 'participants:write', 'projects:read'],
    ]);

    reusableLinkAuthCall($operation, $rawKey, $project)->assertUnauthorized();

    expect(Fx::totalRows())->toBe(0);
})->with(['create']);

test('a candidate JWT, including one minted for a reusable link visitor, is refused with 401', function (string $operation): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    $link = Fx::link($project);

    [$ordinary, $visitor] = TenantContextScope::runFor($org->id, function () use ($project, $link): array {
        $ordinary = Participant::factory()->forProject($project)->create();
        $visitor = Participant::factory()->fromReusableLink($link)->create([
            'candidate_ref' => 'rlv_'.Str::ulid(),
        ]);

        return [
            CandidateTokenFactory::mintCandidateToken($ordinary->fresh()),
            CandidateTokenFactory::mintCandidateToken($visitor->fresh()),
        ];
    });

    foreach ([$ordinary, $visitor] as $candidateJwt) {
        reusableLinkAuthCall($operation, $candidateJwt, $project)->assertUnauthorized();
    }

    expect(Fx::rowsOf($project))->toHaveCount(1);
})->with(['create']);

test('a raw link token is not a credential: presented as a Bearer it is 401', function (string $operation): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    $rawToken = ReusableLinkTokenGenerator::generate();
    Fx::link($project, [
        'token_hash' => ReusableLinkTokenGenerator::hash($rawToken),
        'token_prefix' => ReusableLinkTokenGenerator::prefixOf($rawToken),
    ]);

    reusableLinkAuthCall($operation, $rawToken, $project)->assertUnauthorized();

    expect(Fx::rowsOf($project))->toHaveCount(1);
})->with(['create']);

test('a superadmin with no acting organization is refused the writes with 409', function (string $operation): void {
    $project = Fx::project(Organization::factory()->create());
    $superadmin = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    reusableLinkAuthCall($operation, auth('api')->login($superadmin), $project)->assertStatus(409);

    expect(Fx::totalRows())->toBe(0);
})->with(['create']);
