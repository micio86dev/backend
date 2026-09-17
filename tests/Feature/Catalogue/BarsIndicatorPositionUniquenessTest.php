<?php

declare(strict_types=1);

/**
 * RED/GREEN — K2 (framework-catalogue-authoring PR4b, R3-bars-store-
 * position-500, R3-bars-update-position-500): `POST`/`PATCH` on
 * bars-indicators accept a `position` already taken in the same (revision,
 * role, competency) group, hit the partial unique index
 * (`framework_bars_indicators_rev_role_comp_position_unique`) and 500
 * instead of 422. Same defect class `gga` already caught on default
 * questions in PR4 (`StoreDefaultQuestionRequest`/`UpdateDefaultQuestionRequest`)
 * — fixed here the same way: a draft-scoped closure on POST (`competency_id`
 * can fail its own rule independently, so the check must not assume it is
 * numeric) and a draft-scoped `Rule::unique()->ignore()` on PATCH.
 */

use App\Models\FrameworkCatalogRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function barsPositionSuperadminToken(): string
{
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    return auth('api')->login($user);
}

/**
 * @return array{token: string, roleId: int, competencyId: int}
 */
function barsPositionFixture(string $roleCode, string $competencyCode): array
{
    $token = barsPositionSuperadminToken();

    test()->withToken($token)->postJson('/api/catalogue/roles', [
        'code' => $roleCode,
        'name' => ['en' => "Role {$roleCode}"],
    ])->assertCreated();
    test()->withToken($token)->postJson('/api/catalogue/competencies', [
        'code' => $competencyCode, 'type' => 'standard', 'name' => ['en' => 'x'], 'definition' => ['en' => 'x'],
    ])->assertCreated();

    $draftId = FrameworkCatalogRevision::where('state', 'draft')->value('id');
    $roleId = DB::table('framework_roles')->where('revision_id', $draftId)->where('code', $roleCode)->value('id');
    $competencyId = DB::table('framework_competencies')->where('revision_id', $draftId)->where('code', $competencyCode)->value('id');

    return ['token' => $token, 'roleId' => $roleId, 'competencyId' => $competencyId];
}

/**
 * @return array<string, mixed>
 */
function barsPositionPayload(int $roleId, int $competencyId, int $position): array
{
    return [
        'role_id' => $roleId,
        'competency_id' => $competencyId,
        'position' => $position,
        'text' => ['en' => "text {$position}"],
        'anchor_5' => ['en' => "a5 {$position}"],
        'anchor_3' => ['en' => "a3 {$position}"],
        'anchor_1' => ['en' => "a1 {$position}"],
    ];
}

test('POST refuses a position already taken in the same (revision, role, competency) group with 422, never 500', function (): void {
    ['token' => $token, 'roleId' => $roleId, 'competencyId' => $competencyId] = barsPositionFixture('K2ROLE1', 'K2COMP1');

    $this->withToken($token)->postJson('/api/catalogue/bars-indicators', barsPositionPayload($roleId, $competencyId, 0))
        ->assertCreated();

    $this->withToken($token)->postJson('/api/catalogue/bars-indicators', barsPositionPayload($roleId, $competencyId, 0))
        ->assertStatus(422)
        ->assertJsonPath('errors.position.0', 'position_taken_for_pair');
});

test('POST allows the same position for a DIFFERENT role — the group is (revision, role, competency)', function (): void {
    $token = barsPositionSuperadminToken();

    $this->withToken($token)->postJson('/api/catalogue/roles', ['code' => 'K2ROLE2A', 'name' => ['en' => 'x']])->assertCreated();
    $this->withToken($token)->postJson('/api/catalogue/roles', ['code' => 'K2ROLE2B', 'name' => ['en' => 'x']])->assertCreated();
    $this->withToken($token)->postJson('/api/catalogue/competencies', [
        'code' => 'K2COMP2', 'type' => 'standard', 'name' => ['en' => 'x'], 'definition' => ['en' => 'x'],
    ])->assertCreated();

    $draftId = FrameworkCatalogRevision::where('state', 'draft')->value('id');
    $roleAId = DB::table('framework_roles')->where('revision_id', $draftId)->where('code', 'K2ROLE2A')->value('id');
    $roleBId = DB::table('framework_roles')->where('revision_id', $draftId)->where('code', 'K2ROLE2B')->value('id');
    $competencyId = DB::table('framework_competencies')->where('revision_id', $draftId)->where('code', 'K2COMP2')->value('id');

    $this->withToken($token)->postJson('/api/catalogue/bars-indicators', barsPositionPayload($roleAId, $competencyId, 0))
        ->assertCreated();
    $this->withToken($token)->postJson('/api/catalogue/bars-indicators', barsPositionPayload($roleBId, $competencyId, 0))
        ->assertCreated();
});

test('POST refuses a taken position among role-less (potential) indicators sharing the same competency', function (): void {
    $token = barsPositionSuperadminToken();

    $this->withToken($token)->postJson('/api/catalogue/competencies', [
        'code' => 'K2POT', 'type' => 'potential', 'name' => ['en' => 'x'], 'definition' => ['en' => 'x'],
    ])->assertCreated();

    $draftId = FrameworkCatalogRevision::where('state', 'draft')->value('id');
    $competencyId = DB::table('framework_competencies')->where('revision_id', $draftId)->where('code', 'K2POT')->value('id');

    $payload = fn (int $position) => [
        'role_id' => null,
        'competency_id' => $competencyId,
        'position' => $position,
        'text' => ['en' => "text {$position}"],
        'anchor_5' => ['en' => "a5 {$position}"],
        'anchor_3' => ['en' => "a3 {$position}"],
        'anchor_1' => ['en' => "a1 {$position}"],
    ];

    $this->withToken($token)->postJson('/api/catalogue/bars-indicators', $payload(0))->assertCreated();
    $this->withToken($token)->postJson('/api/catalogue/bars-indicators', $payload(0))->assertStatus(422);
});

test('PATCH refuses reassigning a position already taken by a sibling indicator, never 500', function (): void {
    ['token' => $token, 'roleId' => $roleId, 'competencyId' => $competencyId] = barsPositionFixture('K2ROLE3', 'K2COMP3');

    $first = $this->withToken($token)->postJson('/api/catalogue/bars-indicators', barsPositionPayload($roleId, $competencyId, 0))
        ->assertCreated()->json('data.id');
    $this->withToken($token)->postJson('/api/catalogue/bars-indicators', barsPositionPayload($roleId, $competencyId, 1))
        ->assertCreated();

    $this->withToken($token)->patchJson("/api/catalogue/bars-indicators/{$first}", ['position' => 1])
        ->assertStatus(422)
        ->assertJsonPath('errors.position.0', 'position_taken_for_pair');
});

test('PATCH allows re-saving an indicator at its OWN current position', function (): void {
    ['token' => $token, 'roleId' => $roleId, 'competencyId' => $competencyId] = barsPositionFixture('K2ROLE4', 'K2COMP4');

    $id = $this->withToken($token)->postJson('/api/catalogue/bars-indicators', barsPositionPayload($roleId, $competencyId, 0))
        ->assertCreated()->json('data.id');

    $this->withToken($token)->patchJson("/api/catalogue/bars-indicators/{$id}", ['position' => 0])
        ->assertOk();
});
