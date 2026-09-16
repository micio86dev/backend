<?php

declare(strict_types=1);

/**
 * RED — 17.4 (framework-catalogue-authoring PR5, D10): `StoreProjectQuestionRequest`
 * refuses a question authored for a competency not CURRENTLY selected on the
 * project. Closes the hole `ApplyCompetencySelection`'s restore path would
 * otherwise walk into: without this rule, an operator can deselect a
 * competency, author a fresh question for it at position 0 anyway, then
 * reselect it — the restore collides with that fresh row on
 * `(project, competency, 0)`, a `UniqueConstraintViolationException` where a
 * 422 belongs.
 */

use App\Models\Competency;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/** @return array{user: User, token: string} */
function scqAdmin(Organization $org): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return ['user' => $user, 'token' => auth('api')->login($user)];
}

function scqProject(Organization $org): Project
{
    return TenantContextScope::runFor($org->id, fn (): Project => Project::factory()->create(['organization_id' => $org->id]));
}

function scqCompetency(string $code): Competency
{
    return Competency::firstOrCreate(
        ['code' => $code],
        ['name' => ['en' => 'x'], 'definition' => ['en' => 'x'], 'type' => 'standard'],
    );
}

test('authoring a question for a competency not currently selected is refused with 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = scqAdmin($org);
    $project = scqProject($org);
    // Never attached to the project at all.
    $unselected = scqCompetency('SCQUNSEL');

    $response = $this->withToken($token)->postJson("/api/projects/{$project->id}/questions", [
        'competency_id' => $unselected->id,
        'text' => ['en' => 'A question for a competency nobody selected.'],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['competency_id']);
});

test('authoring a question for a DESELECTED competency is refused with 422', function (): void {
    // The exact collision path this rule exists to close: the competency
    // WAS selected (had a pivot row), then was removed.
    $org = Organization::factory()->create();
    ['token' => $token] = scqAdmin($org);
    $project = scqProject($org);
    $competency = scqCompetency('SCQDESEL');

    TenantContextScope::runFor($org->id, fn () => $project->competencies()->attach([$competency->id => ['position' => 0]]));
    TenantContextScope::runFor($org->id, fn () => $project->competencies()->detach($competency->id));

    $response = $this->withToken($token)->postJson("/api/projects/{$project->id}/questions", [
        'competency_id' => $competency->id,
        'text' => ['en' => 'A question authored while deselected.'],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['competency_id']);
});

test('authoring a question for a currently SELECTED competency still succeeds', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = scqAdmin($org);
    $project = scqProject($org);
    $competency = scqCompetency('SCQSEL');

    TenantContextScope::runFor($org->id, fn () => $project->competencies()->attach([$competency->id => ['position' => 0]]));

    $this->withToken($token)->postJson("/api/projects/{$project->id}/questions", [
        'competency_id' => $competency->id,
        'text' => ['en' => 'A question for a properly selected competency.'],
    ])->assertCreated();
});
