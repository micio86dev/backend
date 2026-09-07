<?php

declare(strict_types=1);

/**
 * GET /api/admin/clients — the superadmin's platform-wide client console
 * aggregate (superadmin-clients-console, PR 1).
 *
 * Reuses `saSuperadmin()` / `saOrgAdmin()` / `saProject()` from
 * `tests/Helpers/SuperadminFixtures.php`, registered in `composer.json`'s
 * `autoload-dev.files`. They were declared inside `ActingOrganizationTest.php`
 * when this file was written, and the comment here claimed "Pest loads every
 * test file into one process" — which is the assumption the helper rule exists
 * because it is FALSE: ParaTest distributes test files across workers. Running
 * this file on its own proved it, dying on "Call to undefined function
 * saProject()" while the full suite stayed green.
 *
 * Only genuinely new fixtures get a `co`-prefixed helper.
 *
 * REQ: openspec/changes/superadmin-clients-console/specs/superadmin-clients-console/spec.md
 *      openspec/changes/superadmin-clients-console/specs/tenancy/spec.md
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function coOrg(string $name): Organization
{
    return Organization::factory()->create(['name' => $name]);
}

function coParticipant(Project $project, string $status): Participant
{
    return TenantContextScope::runFor($project->organization_id, function () use ($project, $status): Participant {
        return Participant::factory()->forProject($project)->withStatus($status)->create();
    });
}

// ─── Phase 2: fan-out and the org that owns nothing ───────────────────────────

test('counts are exact with unequal projects-per-org and participants-per-project', function (): void {
    $orgA = coOrg('Org A');
    $orgB = coOrg('Org B');

    $projectA1 = saProject($orgA, 'org-a-one');
    $projectA2 = saProject($orgA, 'org-a-two');
    coParticipant($projectA1, 'completato');
    coParticipant($projectA1, 'completato');
    coParticipant($projectA2, 'completato');
    coParticipant($projectA2, 'errore');
    coParticipant($projectA2, 'in_corso');

    saProject($orgB, 'org-b-one');

    ['token' => $token] = saSuperadmin();

    $response = $this->withToken($token)->getJson('/api/admin/clients');

    $response->assertOk();

    $rows = collect($response->json('data'))->keyBy('id');

    expect($rows[$orgA->id])
        ->projects->toBe(2)
        ->candidates->toBe(5)
        ->completed->toBe(3)
        ->errored->toBe(1);

    expect($rows[$orgB->id])
        ->projects->toBe(1)
        ->candidates->toBe(0)
        ->completed->toBe(0)
        ->errored->toBe(0)
        ->last_activity_at->toBeNull();
});

test('a zero-activity organization is never dropped', function (): void {
    $empty = coOrg('Nobody Home');

    ['token' => $token] = saSuperadmin();

    $response = $this->withToken($token)->getJson('/api/admin/clients');

    $response->assertOk();

    $rows = collect($response->json('data'))->keyBy('id');

    expect($rows)->toHaveKey($empty->id);
    expect($rows[$empty->id])
        ->projects->toBe(0)
        ->candidates->toBe(0)
        ->completed->toBe(0)
        ->errored->toBe(0)
        ->last_activity_at->toBeNull();
});

// ─── Phase 4: soft-delete, acting-client narrowing, 403/equivalence, the pin ──

test('a soft-deleted project is excluded from the projects count', function (): void {
    $org = coOrg('Soft Delete Co');

    $keep1 = saProject($org, 'kept-one');
    $keep2 = saProject($org, 'kept-two');
    $deleted = saProject($org, 'deleted-one');
    $deleted->delete();

    ['token' => $token] = saSuperadmin();

    $response = $this->withToken($token)->getJson('/api/admin/clients');

    $row = collect($response->json('data'))->firstWhere('id', $org->id);

    expect($row['projects'])->toBe(2);
});

test('an acting organization does not narrow the client estate', function (): void {
    $acme = coOrg('Acme');
    $globex = coOrg('Globex');

    $acmeProject = saProject($acme, 'acme-acting-one');
    $globexProject = saProject($globex, 'globex-acting-one');
    coParticipant($acmeProject, 'completato');
    coParticipant($globexProject, 'completato');
    coParticipant($globexProject, 'completato');

    ['token' => $token] = saSuperadmin();

    $this->withToken($token)
        ->putJson('/api/admin/acting-organization', ['organization_id' => $acme->id])
        ->assertOk();

    $response = $this->withToken($token)->getJson('/api/admin/clients');

    $response->assertOk();

    $rows = collect($response->json('data'))->keyBy('id');

    // The failure mode this pins (D2 Trap 1): a superadmin's ambient bypass
    // is OFF the moment they act as somebody, so a reader that relies on
    // ambient scoping alone would see only Acme's own data reflected here —
    // Globex's row would silently read back as zero rather than 2.
    expect($rows)->toHaveKey($acme->id)
        ->toHaveKey($globex->id);
    expect($rows[$acme->id])->projects->toBe(1)->candidates->toBe(1);
    expect($rows[$globex->id])->projects->toBe(1)->candidates->toBe(2);
});

test('the query count for the client aggregate is constant across 2 and 5 seeded organizations', function (): void {
    ['token' => $token] = saSuperadmin();

    coOrg('Small A');
    coOrg('Small B');

    // Warm-up call before either measured count: primes any lazy, per-process
    // state (mirrors EvaluationsNPlusOneTest's warm-up discipline) so the
    // first measured batch is not charged for one-time setup.
    $this->withToken($token)->getJson('/api/admin/clients')->assertOk();

    $countQueriesFor = function () use ($token): int {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->withToken($token)->getJson('/api/admin/clients')->assertOk();

        return $count;
    };

    $smallCount = $countQueriesFor();

    coOrg('More C');
    coOrg('More D');
    coOrg('More E');

    $largeCount = $countQueriesFor();

    expect($largeCount)->toBe($smallCount);
});

test('only a superadmin may call the client aggregate, and the gate agrees exactly', function (string $role): void {
    $org = Organization::factory()->create();

    if ($role === 'superadmin') {
        ['user' => $user, 'token' => $token] = saSuperadmin();
    } else {
        ['user' => $user, 'token' => $token] = saOrgAdmin($org);

        // authTokenForRole() covers operator/viewer via authUserAndTokenForRole();
        // saOrgAdmin() only ever assigns 'admin'. Reassign the Spatie role in
        // place so all four roles run through the exact same request shape.
        if ($role !== 'admin') {
            $user->syncRoles([]);
            app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
            $spatieRole = Role::firstOrCreate([
                'name' => $role,
                'guard_name' => 'api',
                'team_id' => $org->id,
            ]);
            $user->assignRole($spatieRole);
        }
    }

    $response = $this->withToken($token)->getJson('/api/admin/clients');
    $allowed = Gate::forUser($user)->allows('viewAnyClients');

    if ($role === 'superadmin') {
        $response->assertOk();
        expect($allowed)->toBeTrue();
    } else {
        $response->assertForbidden();
        expect($allowed)->toBeFalse();
    }
})->with(['superadmin', 'admin', 'operator', 'viewer']);

test("GET /api/admin/organizations's row keys are exactly id and name after this change ships", function (): void {
    Organization::factory()->count(2)->create();
    ['token' => $token] = saSuperadmin();

    $response = $this->withToken($token)->getJson('/api/admin/organizations');

    $response->assertOk();

    $row = $response->json('data.0');

    expect(array_keys($row))->toBe(['id', 'name']);
});

test('the client aggregate wire types are correct', function (): void {
    $org = coOrg('Wire Types Co');
    $project = saProject($org, 'wire-types-one');
    coParticipant($project, 'completato');

    ['token' => $token] = saSuperadmin();

    $response = $this->withToken($token)->getJson('/api/admin/clients');

    $response->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $org->id);

    expect($row['projects'])->toBeInt();
    expect($row['candidates'])->toBeInt();
    expect($row['completed'])->toBeInt();
    expect($row['errored'])->toBeInt();
    // Not merely `toBeString()`, which is what was here and is what let the
    // defect through: PDO's raw "2026-09-07 12:44:43" is a string too. Both
    // timestamps must carry the SAME ISO-8601 UTC shape, because the console
    // renders them side by side and `new Date()` reads a value with no
    // timezone marker as LOCAL time — an hour out in CET, two in summer, and
    // shifting at DST so it reads as intermittent rather than wrong.
    $iso = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/';
    expect($row['created_at'])->toBeString()->toMatch($iso);
    expect($row['last_activity_at'])->toBeString()->toMatch($iso);

    $empty = coOrg('Wire Types Co Empty');
    $emptyRow = collect($this->withToken($token)->getJson('/api/admin/clients')->json('data'))
        ->firstWhere('id', $empty->id);

    expect($emptyRow['last_activity_at'])->toBeNull();
});
