<?php

declare(strict_types=1);

/**
 * RED — 17.5 (framework-catalogue-authoring PR5, D10): `ApplyCompetencySelection`
 * — the auto-fill / soft-delete / restore lifecycle competency selection
 * drives. Exercised end-to-end through `POST /api/projects` and
 * `PATCH /api/projects/{id}`, against the seeded ICO catalogue — the same
 * shape `tests/Feature/C4/ProjectCrudTest.php` already uses, since
 * `StoreProjectRequest`/`UpdateProjectRequest` validate `competency_ids`
 * against the real role-competency subset (`ValidatesProjectComposition`).
 */

use App\Actions\Project\ApplyCompetencySelection;
use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkDefaultQuestion;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\User;
use App\Support\Settings\PlatformSettings;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/** @return array{user: User, token: string} */
function acsAdmin(Organization $org): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return ['user' => $user, 'token' => auth('api')->login($user)];
}

function acsCompetency(string $code): Competency
{
    return Competency::where('code', $code)->firstOrFail();
}

/** @param list<string> $texts */
function acsSeedDefaults(Competency $competency, array $texts): void
{
    $revisionId = FrameworkCatalogRevision::latestPublished()->id;

    foreach ($texts as $i => $text) {
        FrameworkDefaultQuestion::create([
            'revision_id' => $revisionId,
            'competency_id' => $competency->id,
            'text' => ['en' => $text, 'it' => $text],
            'position' => $i,
        ]);
    }
}

/**
 * @param  list<int>  $competencyIds
 * @return array<string, mixed>
 */
function acsPayload(int $frameworkVersionId, array $competencyIds): array
{
    return [
        'framework_version_id' => $frameworkVersionId,
        'slug' => 'acs-'.uniqid(),
        'name' => 'ACS Project',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'avatar_template_id' => templateIdForCurrentOrg(),
        'competency_ids' => $competencyIds,
    ];
}

/**
 * @return array{project: array<string, mixed>, fv: FrameworkVersion}
 */
function acsSetUp(): array
{
    $org = Organization::factory()->create();
    ['token' => $token] = acsAdmin($org);
    app(TenantResolver::class)->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    return ['org' => $org, 'token' => $token, 'fv' => $fv];
}

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

test('selecting a competency copies its defaults, in authored order', function (): void {
    ['org' => $org, 'token' => $token, 'fv' => $fv] = acsSetUp();
    $col = acsCompetency('COL');
    acsSeedDefaults($col, ['first', 'second']);

    // Cap raised so both defaults survive the copy — the truncation case is
    // its own test below.
    app(PlatformSettings::class)->setMaxQuestionsPerCompetency(['standard' => 2]);

    $response = $this->withToken($token)->postJson('/api/projects', acsPayload($fv->id, [$col->id]));
    $response->assertCreated();
    $projectId = $response->json('data.id');

    $rows = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::where('project_id', $projectId)->orderBy('position')->get(),
    );

    expect($rows->pluck('text.en')->all())->toBe(['first', 'second']);
    expect($rows->pluck('position')->all())->toBe([0, 1]);
    expect($rows->every(fn (ProjectQuestion $q) => $q->operator_modified === false))->toBeTrue();
});

test('the cap truncates the copy, keeping the first N in authored order', function (): void {
    ['org' => $org, 'token' => $token, 'fv' => $fv] = acsSetUp();
    $col = acsCompetency('COL');
    // Standard cap defaults to 1 — 3 catalogue defaults, only 1 survives.
    acsSeedDefaults($col, ['first', 'second', 'third']);

    $response = $this->withToken($token)->postJson('/api/projects', acsPayload($fv->id, [$col->id]));
    $response->assertCreated();
    $projectId = $response->json('data.id');

    $rows = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::where('project_id', $projectId)->get(),
    );

    expect($rows)->toHaveCount(1);
    expect($rows->first()->text['en'])->toBe('first');
});

test('a competency with zero catalogue defaults yields zero rows, not an error', function (): void {
    ['org' => $org, 'token' => $token, 'fv' => $fv] = acsSetUp();
    $stg = acsCompetency('STG');
    // Deliberately no FrameworkDefaultQuestion rows for STG.

    $response = $this->withToken($token)->postJson('/api/projects', acsPayload($fv->id, [$stg->id]));
    $response->assertCreated();
    $projectId = $response->json('data.id');

    $rows = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::where('project_id', $projectId)->get(),
    );

    expect($rows)->toHaveCount(0);
});

test('re-saving an unchanged competency set does not duplicate rows', function (): void {
    ['org' => $org, 'token' => $token, 'fv' => $fv] = acsSetUp();
    $col = acsCompetency('COL');
    acsSeedDefaults($col, ['first']);

    $create = $this->withToken($token)->postJson('/api/projects', acsPayload($fv->id, [$col->id]));
    $create->assertCreated();
    $projectId = $create->json('data.id');

    $this->withToken($token)
        ->patchJson("/api/projects/{$projectId}", ['competency_ids' => [$col->id]])
        ->assertOk();

    $rows = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::where('project_id', $projectId)->get(),
    );

    expect($rows)->toHaveCount(1);
});

test('deselecting a competency soft-deletes its live questions', function (): void {
    ['org' => $org, 'token' => $token, 'fv' => $fv] = acsSetUp();
    $col = acsCompetency('COL');
    $prs = acsCompetency('PRS');
    acsSeedDefaults($col, ['first']);

    $create = $this->withToken($token)->postJson('/api/projects', acsPayload($fv->id, [$col->id, $prs->id]));
    $create->assertCreated();
    $projectId = $create->json('data.id');

    $this->withToken($token)
        ->patchJson("/api/projects/{$projectId}", ['competency_ids' => [$prs->id]])
        ->assertOk();

    $live = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::where('project_id', $projectId)->where('competency_id', $col->id)->get(),
    );
    $trashed = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::onlyTrashed()->where('project_id', $projectId)->where('competency_id', $col->id)->get(),
    );

    expect($live)->toHaveCount(0);
    expect($trashed)->toHaveCount(1);
});

test('reselecting restores an untouched copy — not a fresh copy of the current defaults', function (): void {
    ['org' => $org, 'token' => $token, 'fv' => $fv] = acsSetUp();
    $col = acsCompetency('COL');
    $prs = acsCompetency('PRS');
    acsSeedDefaults($col, ['original default']);

    $create = $this->withToken($token)->postJson('/api/projects', acsPayload($fv->id, [$col->id, $prs->id]));
    $create->assertCreated();
    $projectId = $create->json('data.id');

    $originalRowId = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::where('project_id', $projectId)->where('competency_id', $col->id)->value('id'),
    );

    // Deselect, then the catalogue default changes (D9 — must be invisible),
    // then reselect.
    $this->withToken($token)->patchJson("/api/projects/{$projectId}", ['competency_ids' => [$prs->id]])->assertOk();
    FrameworkDefaultQuestion::where('competency_id', $col->id)->update(['text' => ['en' => 'changed after deselection', 'it' => 'x']]);
    $this->withToken($token)->patchJson("/api/projects/{$projectId}", ['competency_ids' => [$col->id, $prs->id]])->assertOk();

    $restored = TenantContextScope::runFor($org->id, fn () => ProjectQuestion::where('project_id', $projectId)->where('competency_id', $col->id)->get());

    expect($restored)->toHaveCount(1);
    expect($restored->first()->id)->toBe($originalRowId);
    expect($restored->first()->text['en'])->toBe('original default');
    expect($restored->first()->operator_modified)->toBeFalse();
});

test('reselecting restores an operator-rewritten copy, with the operator text intact', function (): void {
    ['org' => $org, 'token' => $token, 'fv' => $fv] = acsSetUp();
    $col = acsCompetency('COL');
    $prs = acsCompetency('PRS');
    acsSeedDefaults($col, ['original default']);

    $create = $this->withToken($token)->postJson('/api/projects', acsPayload($fv->id, [$col->id, $prs->id]));
    $create->assertCreated();
    $projectId = $create->json('data.id');

    $rowId = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::where('project_id', $projectId)->where('competency_id', $col->id)->value('id'),
    );

    $this->withToken($token)
        ->patchJson("/api/projects/{$projectId}/questions/{$rowId}", ['text' => ['en' => 'operator rewrote this']])
        ->assertOk();

    $this->withToken($token)->patchJson("/api/projects/{$projectId}", ['competency_ids' => [$prs->id]])->assertOk();
    $this->withToken($token)->patchJson("/api/projects/{$projectId}", ['competency_ids' => [$col->id, $prs->id]])->assertOk();

    $restored = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::where('project_id', $projectId)->where('competency_id', $col->id)->get(),
    );

    expect($restored)->toHaveCount(1);
    expect($restored->first()->id)->toBe($rowId);
    expect($restored->first()->text['en'])->toBe('operator rewrote this');
    expect($restored->first()->operator_modified)->toBeTrue();
});

test('restore never re-copies over an operator-modified row', function (): void {
    ['org' => $org, 'token' => $token, 'fv' => $fv] = acsSetUp();
    $col = acsCompetency('COL');
    $prs = acsCompetency('PRS');
    acsSeedDefaults($col, ['original default']);

    $create = $this->withToken($token)->postJson('/api/projects', acsPayload($fv->id, [$col->id, $prs->id]));
    $create->assertCreated();
    $projectId = $create->json('data.id');

    $rowId = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::where('project_id', $projectId)->where('competency_id', $col->id)->value('id'),
    );

    $this->withToken($token)
        ->patchJson("/api/projects/{$projectId}/questions/{$rowId}", ['text' => ['en' => 'operator rewrote this']])
        ->assertOk();

    $this->withToken($token)->patchJson("/api/projects/{$projectId}", ['competency_ids' => [$prs->id]])->assertOk();
    $this->withToken($token)->patchJson("/api/projects/{$projectId}", ['competency_ids' => [$col->id, $prs->id]])->assertOk();

    $restored = TenantContextScope::runFor($org->id, fn () => ProjectQuestion::findOrFail($rowId));

    // The SAME reselection event above ran the restore; nothing replaced
    // the operator's text with the (still-original, unchanged) catalogue
    // default's current text.
    expect($restored->text['en'])->toBe('operator rewrote this');
});

/**
 * Z8 (R3-restore-resurrects-individually-deleted-question, REQUIRED BEFORE
 * ARCHIVE): deleting is operator work exactly like writing. A question the
 * operator deleted INDIVIDUALLY (via `DELETE /questions/{id}`, competency
 * left selected) must stay deleted through a later deselect + reselect
 * cycle — only the row the DESELECTION itself trashed comes back.
 */
test('an individually deleted question stays deleted on reselection — only the deselection-trashed row restores', function (): void {
    ['org' => $org, 'token' => $token, 'fv' => $fv] = acsSetUp();
    $col = acsCompetency('COL');
    $prs = acsCompetency('PRS');
    app(PlatformSettings::class)->setMaxQuestionsPerCompetency(['standard' => 2]);
    acsSeedDefaults($col, ['keep this one', 'delete this one individually']);

    $create = $this->withToken($token)->postJson('/api/projects', acsPayload($fv->id, [$col->id, $prs->id]));
    $create->assertCreated();
    $projectId = $create->json('data.id');

    $rows = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::where('project_id', $projectId)->where('competency_id', $col->id)->orderBy('position')->get(),
    );
    expect($rows)->toHaveCount(2);
    $keptRowId = $rows->firstWhere('text.en', 'keep this one')->id;
    $individuallyDeletedRowId = $rows->firstWhere('text.en', 'delete this one individually')->id;

    // The operator deletes ONE question directly — the competency stays
    // selected, this is not a deselection.
    $this->withToken($token)
        ->deleteJson("/api/projects/{$projectId}/questions/{$individuallyDeletedRowId}")
        ->assertNoContent();

    // Now deselect the whole competency (soft-deletes the ONE remaining
    // live row, `deleted_by_deselection = true`) and reselect it.
    $this->withToken($token)->patchJson("/api/projects/{$projectId}", ['competency_ids' => [$prs->id]])->assertOk();
    $this->withToken($token)->patchJson("/api/projects/{$projectId}", ['competency_ids' => [$col->id, $prs->id]])->assertOk();

    $live = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::where('project_id', $projectId)->where('competency_id', $col->id)->get(),
    );

    // Only the deselection-trashed row came back.
    expect($live)->toHaveCount(1);
    expect($live->first()->id)->toBe($keptRowId);
    expect($live->first()->text['en'])->toBe('keep this one');

    // The individually deleted row is STILL trashed — never restored.
    $individuallyDeleted = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::onlyTrashed()->find($individuallyDeletedRowId),
    );
    expect($individuallyDeleted)->not->toBeNull();
    expect($individuallyDeleted->deleted_by_deselection)->toBeFalse();
});

/**
 * Z8, second-cycle correctness (gga review finding, blocking): `restore()`
 * must CLEAR `deleted_by_deselection` on the row it brings back — leaving
 * it `true` would let a LATER individual delete of that same, now-live row
 * be misread as deselection-caused on the NEXT deselect/reselect cycle,
 * reopening the exact bug this column exists to close. This test fails on
 * a version of the fix that never clears the flag: the row restored by
 * cycle 1 would still read `deleted_by_deselection = true` after the
 * operator deletes it individually, and cycle 2 would resurrect it.
 */
test('a row restored once, then individually deleted, stays deleted on a second reselection', function (): void {
    ['org' => $org, 'token' => $token, 'fv' => $fv] = acsSetUp();
    $col = acsCompetency('COL');
    $prs = acsCompetency('PRS');
    acsSeedDefaults($col, ['the only question']);

    $create = $this->withToken($token)->postJson('/api/projects', acsPayload($fv->id, [$col->id, $prs->id]));
    $create->assertCreated();
    $projectId = $create->json('data.id');

    $rowId = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::where('project_id', $projectId)->where('competency_id', $col->id)->value('id'),
    );

    // Cycle 1: deselect (soft-deletes with deleted_by_deselection = true),
    // reselect (restores the SAME row — the flag must clear here).
    $this->withToken($token)->patchJson("/api/projects/{$projectId}", ['competency_ids' => [$prs->id]])->assertOk();
    $this->withToken($token)->patchJson("/api/projects/{$projectId}", ['competency_ids' => [$col->id, $prs->id]])->assertOk();

    $afterCycle1 = TenantContextScope::runFor($org->id, fn () => ProjectQuestion::findOrFail($rowId));
    expect($afterCycle1->id)->toBe($rowId);
    expect($afterCycle1->trashed())->toBeFalse();
    expect($afterCycle1->deleted_by_deselection)->toBeFalse();

    // The operator now deletes the SAME row individually — not a
    // deselection.
    $this->withToken($token)->deleteJson("/api/projects/{$projectId}/questions/{$rowId}")->assertNoContent();

    // Cycle 2: deselect the (now empty) competency, then reselect it.
    $this->withToken($token)->patchJson("/api/projects/{$projectId}", ['competency_ids' => [$prs->id]])->assertOk();
    $this->withToken($token)->patchJson("/api/projects/{$projectId}", ['competency_ids' => [$col->id, $prs->id]])->assertOk();

    // The individually deleted row must still be trashed — a fresh
    // catalogue-default copy is acceptable (branch 3), the ORIGINAL row is
    // not resurrected.
    $stillTrashed = TenantContextScope::runFor($org->id, fn () => ProjectQuestion::onlyTrashed()->find($rowId));
    expect($stillTrashed)->not->toBeNull();

    $live = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::where('project_id', $projectId)->where('competency_id', $col->id)->pluck('id'),
    );
    expect($live)->not->toContain($rowId);
});

test('two trashed rows sharing the SAME stored position both restore cleanly, capped, no 500', function (): void {
    // Regression for a gga-caught bug: two trashed rows can legitimately
    // share a stored position (the partial unique index only ever governed
    // the LIVE ones), and restoring both used to collide with itself
    // because the "next free slot" counter was computed once and never
    // recomputed after a row kept its own (now-occupied) position.
    //
    // Reproduction, standard cap = 1:
    //   1. Select COL — the auto-fill default lands at position 0.
    //   2. Delete it (soft), then author a fresh COL question — `max()`
    //      over LIVE rows returns null (the default is trashed), so the
    //      new row ALSO lands at position 0.
    //   3. Deselect COL — the live operator row is now trashed too, at
    //      position 0, alongside the already-trashed default.
    //   4. Reselect COL — restore() must not throw, and must not resurrect
    //      more than the cap allows.
    ['org' => $org, 'token' => $token, 'fv' => $fv] = acsSetUp();
    $col = acsCompetency('COL');
    $prs = acsCompetency('PRS');
    acsSeedDefaults($col, ['the default']);

    $create = $this->withToken($token)->postJson('/api/projects', acsPayload($fv->id, [$col->id, $prs->id]));
    $create->assertCreated();
    $projectId = $create->json('data.id');

    $defaultRowId = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::where('project_id', $projectId)->where('competency_id', $col->id)->value('id'),
    );

    $this->withToken($token)->deleteJson("/api/projects/{$projectId}/questions/{$defaultRowId}")->assertNoContent();

    $this->withToken($token)->postJson("/api/projects/{$projectId}/questions", [
        'competency_id' => $col->id,
        'text' => ['en' => 'operator authored'],
    ])->assertCreated();

    $this->withToken($token)
        ->patchJson("/api/projects/{$projectId}", ['competency_ids' => [$prs->id]])
        ->assertOk();

    $reselect = $this->withToken($token)
        ->patchJson("/api/projects/{$projectId}", ['competency_ids' => [$col->id, $prs->id]]);

    $reselect->assertOk();

    $live = TenantContextScope::runFor(
        $org->id,
        fn () => ProjectQuestion::where('project_id', $projectId)->where('competency_id', $col->id)->get(),
    );

    expect($live)->toHaveCount(1);
    expect($live->pluck('position')->unique())->toHaveCount(1);
});

test(
    'a restored row whose original slot is occupied by a live row is renumbered defensively, without a constraint violation',
    function (): void {
        // This branch should be UNREACHABLE through the ordinary HTTP flow —
        // `StoreProjectQuestionRequest` (18.2) already closes the one known
        // collision path — so it is exercised by calling the action
        // directly against a deliberately corrupted fixture: two trashed
        // rows whose ORIGINAL positions collide with a live row already
        // occupying position 0 (D10's defensive renumber).
        //
        // Cap raised so the cap-on-restore logic (a SEPARATE concern, its
        // own regression test above) does not interact with this one —
        // this test is about the renumber, not the ceiling.
        ['org' => $org, 'fv' => $fv] = acsSetUp();
        app(PlatformSettings::class)->setMaxQuestionsPerCompetency(['standard' => 10]);
        $col = acsCompetency('COL');

        $project = TenantContextScope::runFor(
            $org->id,
            fn () => Project::factory()->create(['organization_id' => $org->id, 'framework_version_id' => $fv->id]),
        );

        TenantContextScope::runFor($org->id, function () use ($project, $col): void {
            ProjectQuestion::create([
                'project_id' => $project->id,
                'competency_id' => $col->id,
                'text' => ['en' => 'live at position 0'],
                'position' => 0,
            ]);

            // Created at a temporary, non-colliding position — the live
            // unique index only covers LIVE rows, so inserting directly at
            // position 0 here would fail before soft-delete ever ran. Once
            // soft-deleted, the row is invisible to that index, so its
            // position can be rewritten back to 0 directly: a trashed row
            // whose ORIGINAL position collides with a live row, exactly the
            // fixture this test needs.
            // `deleted_by_deselection` stamped explicitly (Z8) — this
            // fixture simulates rows a DESELECTION trashed, which is
            // `restore()`'s own precondition for touching them at all;
            // `->delete()` alone would leave the column at its `false`
            // default (the "individually deleted" shape) and this test's
            // own restore assertions would fail closed for the wrong reason.
            $trashedA = ProjectQuestion::create([
                'project_id' => $project->id,
                'competency_id' => $col->id,
                'text' => ['en' => 'trashed A'],
                'position' => 5,
            ]);
            $trashedA->delete();
            DB::table('project_questions')->where('id', $trashedA->id)->update(['position' => 0, 'deleted_by_deselection' => true]);

            $trashedB = ProjectQuestion::create([
                'project_id' => $project->id,
                'competency_id' => $col->id,
                'text' => ['en' => 'trashed B'],
                'position' => 1,
            ]);
            $trashedB->delete();
            DB::table('project_questions')->where('id', $trashedB->id)->update(['deleted_by_deselection' => true]);
        });

        TenantContextScope::runFor(
            $org->id,
            fn () => app(ApplyCompetencySelection::class)->apply($project, [$col->id], []),
        );

        $live = TenantContextScope::runFor(
            $org->id,
            fn () => ProjectQuestion::where('project_id', $project->id)->where('competency_id', $col->id)->get(),
        );

        expect($live)->toHaveCount(3);
        expect($live->pluck('position')->unique())->toHaveCount(3);
        expect($live->pluck('text.en')->sort()->values()->all())
            ->toBe(['live at position 0', 'trashed A', 'trashed B']);
    }
);
