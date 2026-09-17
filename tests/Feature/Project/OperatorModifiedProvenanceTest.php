<?php

declare(strict_types=1);

/**
 * RED — 17.3 (framework-catalogue-authoring PR5, D9): `operator_modified`
 * provenance. A FLAG, never a comparison against the catalogue default — see
 * `ApplyCompetencySelectionTest.php` for the auto-fill side of D9/D10.
 */

use App\Models\Competency;
use App\Models\FrameworkDefaultQuestion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\User;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/** @return array{user: User, token: string} */
function opAdmin(Organization $org): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return ['user' => $user, 'token' => auth('api')->login($user)];
}

function opProjectWithCompetency(Organization $org, Competency $competency): Project
{
    return TenantContextScope::runFor($org->id, function () use ($org, $competency): Project {
        $project = Project::factory()->create(['organization_id' => $org->id]);
        $project->competencies()->attach([$competency->id => ['position' => 0]]);

        return $project;
    });
}

function opCompetency(string $code = 'PRS'): Competency
{
    return Competency::firstOrCreate(
        ['code' => $code],
        ['name' => ['en' => 'x'], 'definition' => ['en' => 'x'], 'type' => 'standard'],
    );
}

test('store() sets operator_modified true', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = opAdmin($org);
    $competency = opCompetency();
    $project = opProjectWithCompetency($org, $competency);

    $response = $this->withToken($token)->postJson("/api/projects/{$project->id}/questions", [
        'competency_id' => $competency->id,
        'text' => ['en' => 'Tell me about a time.'],
    ]);

    $response->assertCreated();
    expect($response->json('data.id'))->not->toBeNull();

    $row = TenantContextScope::runFor($org->id, fn () => ProjectQuestion::findOrFail($response->json('data.id')));
    expect($row->operator_modified)->toBeTrue();
});

test('update() sets operator_modified true unconditionally, even without a text change', function (): void {
    // The interesting case: a row born FALSE (auto-filled, D10) still flips
    // to TRUE on a save that submits the exact same text back — no
    // `isDirty('text')` check. An operator who saves an unchanged row has
    // still claimed it.
    $org = Organization::factory()->create();
    ['token' => $token] = opAdmin($org);
    $competency = opCompetency();
    $project = opProjectWithCompetency($org, $competency);

    $autoFilled = TenantContextScope::runFor($org->id, fn () => ProjectQuestion::create([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'A catalogue default, copied verbatim.'],
        'position' => 0,
    ])->refresh());

    // `refresh()` reads back the DB's own `DEFAULT false` — Eloquent's
    // `create()` does not read a DB-computed default into the in-memory
    // model on its own (the same gotcha `ProjectController::store()`'s own
    // `$project->refresh()` call works around for `webhook_events`).
    expect($autoFilled->operator_modified)->toBeFalse();

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}/questions/{$autoFilled->id}", [
            'text' => ['en' => 'A catalogue default, copied verbatim.'],
        ])
        ->assertOk();

    $refreshed = TenantContextScope::runFor($org->id, fn () => $autoFilled->fresh());
    expect($refreshed->operator_modified)->toBeTrue();
});

test('destroy() soft-deletes only — the provenance flag is untouched', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = opAdmin($org);
    $competency = opCompetency();
    $project = opProjectWithCompetency($org, $competency);

    $autoFilled = TenantContextScope::runFor($org->id, fn () => ProjectQuestion::create([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'A catalogue default.'],
        'position' => 0,
    ])->refresh());

    expect($autoFilled->operator_modified)->toBeFalse();

    $this->withToken($token)
        ->deleteJson("/api/projects/{$project->id}/questions/{$autoFilled->id}")
        ->assertNoContent();

    $trashed = TenantContextScope::runFor($org->id, fn () => ProjectQuestion::withTrashed()->findOrFail($autoFilled->id));
    expect($trashed->deleted_at)->not->toBeNull()
        ->and($trashed->operator_modified)->toBeFalse();
});

test('editing a catalogue default performs ZERO writes to project_questions', function (): void {
    // D9's "no propagation mechanism, by design" — proven as a query-count
    // assertion, not merely "the text happens to still match": there is no
    // read, write, or comparison against project_questions as a RESULT of a
    // catalogue edit at all.
    $org = Organization::factory()->create();
    ['token' => $token] = opAdmin($org);
    $competency = opCompetency();
    $project = opProjectWithCompetency($org, $competency);

    TenantContextScope::runFor($org->id, fn () => ProjectQuestion::create([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'A catalogue default, copied verbatim.'],
        'position' => 0,
    ]));

    $default = FrameworkDefaultQuestion::factory()->create([
        'competency_id' => $competency->id,
        'text' => ['en' => 'original default', 'it' => 'originale'],
        'position' => 0,
    ]);

    DB::enableQueryLog();
    $default->update(['text' => ['en' => 'edited default', 'it' => 'modificato']]);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $touchedProjectQuestions = array_filter(
        $queries,
        static fn (array $entry): bool => str_contains($entry['query'], 'project_questions'),
    );

    expect($touchedProjectQuestions)->toBe([]);
});
