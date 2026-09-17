<?php

declare(strict_types=1);

/**
 * RED/GREEN — 32b.1-32b.4 (framework-catalogue-authoring PR8b,
 * `catalogue-authoring/spec.md`'s pivot CRUD requirement). PR3 shipped
 * role/competency/indicator CRUD with no way to change a role's competency
 * SET, so a newly created role could never be made usable — see this PR's
 * own top-of-section note in `tasks.md`.
 */

use App\Models\FrameworkCatalogRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function pivotSuperadminToken(): string
{
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    return auth('api')->login($user);
}

/**
 * A role and three standard competencies in the open draft (opened via a
 * real catalogue write, exactly like every other catalogue test).
 *
 * @return array{token: string, roleId: int, competencyIds: list<int>}
 */
function pivotFixture(string $suffix): array
{
    $token = pivotSuperadminToken();

    $roleId = test()->withToken($token)->postJson('/api/catalogue/roles', [
        'code' => "PVROLE{$suffix}",
        'name' => ['en' => 'Pivot Role'],
    ])->assertStatus(201)->json('data.id');

    $competencyIds = [];

    foreach (['A', 'B', 'C'] as $letter) {
        $competencyIds[] = test()->withToken($token)->postJson('/api/catalogue/competencies', [
            'code' => "PV{$suffix}{$letter}",
            'type' => 'standard',
            'name' => ['en' => "Pivot {$letter}"],
            'definition' => ['en' => "Pivot {$letter}"],
        ])->assertStatus(201)->json('data.id');
    }

    return ['token' => $token, 'roleId' => (int) $roleId, 'competencyIds' => $competencyIds];
}

function pivotIndicator(int $revisionId, int $roleId, int $competencyId, int $position): void
{
    DB::table('framework_bars_indicators')->insert([
        'revision_id' => $revisionId,
        'role_id' => $roleId,
        'competency_id' => $competencyId,
        'text' => json_encode(['en' => "text {$position}"]),
        'anchor_5' => json_encode(['en' => "anchor5 {$position}"]),
        'anchor_3' => json_encode(['en' => "anchor3 {$position}"]),
        'anchor_1' => json_encode(['en' => "anchor1 {$position}"]),
        'position' => $position,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('a PUT replaces the whole set — attach, detach and reorder in one call', function (): void {
    $fixture = pivotFixture('SET1');
    [$a, $b, $c] = $fixture['competencyIds'];

    $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => [$a, $b]])
        ->assertStatus(200)
        ->assertJsonPath('data.competency_ids', [$a, $b]);

    // Detach $a, keep $b, attach $c, reorder — one call, one write.
    $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => [$c, $b]])
        ->assertStatus(200)
        ->assertJsonPath('data.competency_ids', [$c, $b]);

    expect(
        DB::table('framework_role_competency')->where('role_id', $fixture['roleId'])->where('competency_id', $a)->exists()
    )->toBeFalse();
});

test('an empty set detaches every competency from the role', function (): void {
    $fixture = pivotFixture('SET2');
    [$a, $b] = $fixture['competencyIds'];

    $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => [$a, $b]])
        ->assertStatus(200);

    $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => []])
        ->assertStatus(200)
        ->assertJsonPath('data.competency_ids', []);
});

test('a potential competency in the payload is refused with 422', function (): void {
    $fixture = pivotFixture('POT1');
    $token = $fixture['token'];

    $potentialId = $this->withToken($token)->postJson('/api/catalogue/competencies', [
        'code' => 'MTGPV1',
        'type' => 'potential',
        'name' => ['en' => 'x'],
        'definition' => ['en' => 'x'],
    ])->assertStatus(201)->json('data.id');

    $this->withToken($token)
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => [$potentialId]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['competency_ids.0']);

    expect(DB::table('framework_role_competency')->where('competency_id', $potentialId)->exists())->toBeFalse();
});

test('a duplicate competency id in the payload is rejected with 422, not a raw DB error', function (): void {
    $fixture = pivotFixture('DUP1');
    $a = $fixture['competencyIds'][0];

    $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => [$a, $a]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['competency_ids.0']);
});

test('a competency id outside the open draft is rejected with 422', function (): void {
    $fixture = pivotFixture('OUT1');

    $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => [999_999_999]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['competency_ids.0']);
});

test('a non-array competency_ids payload is rejected with 422, never a 500', function (): void {
    // gga review finding: `array`/`list` are not `shouldStopValidating()`
    // rules, so a non-array value used to reach the detach-refusal closure
    // and crash with a TypeError under strict_types.
    $fixture = pivotFixture('BAD1');

    $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => 'not-an-array'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['competency_ids']);

    $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['competency_ids']);
});

test('detaching a competency whose pair still has indicators in the draft is refused, naming it', function (): void {
    $fixture = pivotFixture('ANCH1');
    [$a, $b] = $fixture['competencyIds'];

    $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => [$a, $b]])
        ->assertStatus(200);

    $draft = FrameworkCatalogRevision::openDraft();
    pivotIndicator($draft->id, $fixture['roleId'], $a, 0);

    $response = $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => [$b]])
        ->assertStatus(422);

    expect($response->json('errors.competency_ids.0'))->toContain('PVANCH1A');

    // The pair itself is untouched — still declared, indicator still there.
    expect(
        DB::table('framework_role_competency')->where('role_id', $fixture['roleId'])->where('competency_id', $a)->exists()
    )->toBeTrue();
});

test('detaching a competency whose pair has zero indicators succeeds', function (): void {
    $fixture = pivotFixture('ANCH2');
    [$a, $b] = $fixture['competencyIds'];

    $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => [$a, $b]])
        ->assertStatus(200);

    $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => [$b]])
        ->assertStatus(200)
        ->assertJsonPath('data.competency_ids', [$b]);
});

test('an org admin gets 403', function (): void {
    $fixture = pivotFixture('AUTH1');
    $nonSuperadmin = User::factory()->create();
    $nonSuperadminToken = auth('api')->login($nonSuperadmin);

    $this->withToken($nonSuperadminToken)
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => []])
        ->assertStatus(403);
});

test('no open draft (or a role outside it) refuses with 404, never auto-opening a new one', function (): void {
    // No draft open at all yet in this test's own fresh transaction — the
    // fixture helper always opens one via a real write, so this constructs
    // the "role absent from the currently open draft" case directly: a role
    // that belongs to a DIFFERENT (published) revision.
    $token = pivotSuperadminToken();

    $otherRevision = FrameworkCatalogRevision::factory()->draft()->create();
    $otherRoleId = DB::table('framework_roles')->insertGetId([
        'revision_id' => $otherRevision->id, 'code' => 'OUTSIDE404',
        'name' => json_encode(['en' => 'x']), 'responsibilities' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('framework_catalog_revisions')->where('id', $otherRevision->id)->update(['state' => 'published', 'published_at' => now()]);

    // Opens the REAL open draft via a genuine write, so the 404 below is
    // produced by the draft-scoped `findOrFail`, not by "no draft open at
    // all".
    $this->withToken($token)->postJson('/api/catalogue/roles', [
        'code' => 'PVDRAFT404',
        'name' => ['en' => 'x'],
    ])->assertStatus(201);

    $this->withToken($token)
        ->putJson("/api/catalogue/roles/{$otherRoleId}/competencies", ['competency_ids' => []])
        ->assertStatus(404);

    expect(FrameworkCatalogRevision::where('id', '!=', $otherRevision->id)->where('state', 'draft')->count())->toBe(1);
});

test('PlatformAuditWriter records one row with before/after competency_ids', function (): void {
    $fixture = pivotFixture('AUDIT1');
    [$a, $b] = $fixture['competencyIds'];

    $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => [$a]])
        ->assertStatus(200);

    $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => [$b]])
        ->assertStatus(200);

    $row = DB::table('audit_logs')
        ->where('action', 'catalogue.role.competencies.updated')
        ->where('subject_id', $fixture['roleId'])
        ->orderByDesc('id')
        ->first();

    expect($row)->not->toBeNull();
    $before = json_decode((string) $row->before, true);
    $after = json_decode((string) $row->after, true);
    expect($before['competency_ids'])->toBe([$a]);
    expect($after['competency_ids'])->toBe([$b]);
});

test('a pivot-only write bumps the draft content_version', function (): void {
    // Companion to `DefaultQuestionCrudTest`'s identical case:
    // `DiscardUnusedDraftRevision` treats `content_version === 0` as
    // "genuinely untouched" — a sync()-only write through the pivot fires
    // no Role `saved`/`deleted` event, so without an explicit bump this
    // write would look invisible to that guard.
    $fixture = pivotFixture('BUMP1');
    $draft = FrameworkCatalogRevision::openDraft();
    $versionBefore = $draft->fresh()->content_version;

    $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => $fixture['competencyIds']])
        ->assertStatus(200);

    expect($draft->fresh()->content_version)->toBeGreaterThan($versionBefore);
});

test('re-submitting the exact same set does not bump content_version', function (): void {
    // gga review finding: bumping unconditionally, even when the submitted
    // set matched the current one exactly, let a no-op PUT stop
    // `DiscardUnusedDraftRevision` from ever discarding a genuinely
    // untouched draft — the same guard the audit write already applies.
    $fixture = pivotFixture('BUMP2');

    $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => $fixture['competencyIds']])
        ->assertStatus(200);

    $draft = FrameworkCatalogRevision::openDraft();
    $versionAfterFirstWrite = $draft->fresh()->content_version;

    $this->withToken($fixture['token'])
        ->putJson("/api/catalogue/roles/{$fixture['roleId']}/competencies", ['competency_ids' => $fixture['competencyIds']])
        ->assertStatus(200);

    expect($draft->fresh()->content_version)->toBe($versionAfterFirstWrite);
});

test('a published revision refuses this write the same way it refuses every other catalogue write', function (): void {
    $token = pivotSuperadminToken();

    $published = FrameworkCatalogRevision::factory()->draft()->create();
    $roleId = DB::table('framework_roles')->insertGetId([
        'revision_id' => $published->id, 'code' => 'PUBPIVOT',
        'name' => json_encode(['en' => 'x']), 'responsibilities' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('framework_catalog_revisions')->where('id', $published->id)->update(['state' => 'published', 'published_at' => now()]);

    // Opens the real open draft so the 404 below is genuinely produced by
    // the draft-scoped `findOrFail`, not by "no draft open at all".
    $this->withToken($token)->postJson('/api/catalogue/roles', [
        'code' => 'PVDRAFTPUB',
        'name' => ['en' => 'x'],
    ])->assertStatus(201);

    $this->withToken($token)
        ->putJson("/api/catalogue/roles/{$roleId}/competencies", ['competency_ids' => []])
        ->assertStatus(404);
});
