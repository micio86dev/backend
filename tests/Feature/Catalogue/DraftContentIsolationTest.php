<?php

declare(strict_types=1);

/**
 * RED/GREEN — H1 (framework-catalogue-authoring PR3b hardening slice): once
 * a draft is open, every role/competency code exists TWICE (the revision it
 * was cloned from, and the draft itself) — a draft is a full row-set clone
 * (D1), never a delta. Every tenant-facing catalogue reader that resolved a
 * role/competency BY CODE with no revision filter was at the mercy of
 * whichever row Postgres happened to return first, so a live interview,
 * project creation, or a superadmin's own composition checks could silently
 * accept or read DRAFT content.
 *
 * This file opens a draft whose content DIFFERS from the published revision
 * for the SAME codes, and proves every reader below still resolves the
 * published content — never the draft's.
 *
 * Readers NOT covered here because they are already safe by construction
 * (verified during the PR3b audit, not assumed): `ScoreEvaluationJob` and
 * the webhook payload assemblers resolve indicators/competencies through a
 * numeric id already bound to a specific revision at project-creation time
 * (`project_competencies.competency_id`), never by code — the composite FKs
 * on `framework_bars_indicators`/`framework_role_competency` guarantee a
 * row's `role_id`/`competency_id` and its own `revision_id` always agree,
 * so once a starting id is revision-correct, every relation traversed from
 * it is automatically safe.
 */

use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Insert a role + competency + pivot + 3 BARS indicators scoped to
 * $revisionId, with every translatable field carrying $label so a test can
 * tell which revision's content actually answered.
 *
 * @return array{roleId: int, competencyId: int}
 */
function diSeedRevisionContent(int $revisionId, string $roleCode, string $competencyCode, string $label): array
{
    $roleId = DB::table('framework_roles')->insertGetId([
        'revision_id' => $revisionId,
        'code' => $roleCode,
        'name' => json_encode(['en' => "{$label} role name"]),
        'responsibilities' => json_encode(['en' => "{$label} responsibilities"]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $competencyId = DB::table('framework_competencies')->insertGetId([
        'revision_id' => $revisionId,
        'code' => $competencyCode,
        'type' => 'standard',
        'name' => json_encode(['en' => "{$label} competency name"]),
        'definition' => json_encode(['en' => "{$label} competency definition"]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('framework_role_competency')->insert([
        'revision_id' => $revisionId,
        'role_id' => $roleId,
        'competency_id' => $competencyId,
        'position' => 0,
    ]);

    for ($i = 0; $i < 3; $i++) {
        DB::table('framework_bars_indicators')->insert([
            'revision_id' => $revisionId,
            'role_id' => $roleId,
            'competency_id' => $competencyId,
            'text' => json_encode(['en' => "{$label} indicator text {$i}"]),
            'anchor_5' => json_encode(['en' => "{$label} anchor 5 {$i}"]),
            'anchor_3' => json_encode(['en' => "{$label} anchor 3 {$i}"]),
            'anchor_1' => json_encode(['en' => "{$label} anchor 1 {$i}"]),
            'position' => $i,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return compact('roleId', 'competencyId');
}

function diBaselineRevisionId(): int
{
    return (int) DB::table('framework_catalog_revisions')->where('is_baseline', true)->value('id');
}

// ─── FrameworkController: catalogue browse before a project exists ──────────
//
// Each scenario below seeds a code that exists ONLY IN A DRAFT — never
// published — which is the deterministic proof: the unscoped, pre-fix query
// had exactly ONE matching row (the draft's), so it resolved every time,
// independent of physical row order. The fix must resolve NOTHING for it.

test('FrameworkController never surfaces a role that exists only in a draft', function (): void {
    $draft = FrameworkCatalogRevision::factory()->draft()->create();
    diSeedRevisionContent($draft->id, 'DIROLE', 'DICOMP', 'DRAFTMUTATED');

    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    // (a) GET /api/framework/roles — the draft-only role must be absent.
    $roles = $this->withToken($token)->getJson('/api/framework/roles')->assertOk()->json('data');
    expect(collect($roles)->where('code', 'DIROLE'))->toBeEmpty();

    // (b) GET /api/framework/roles/{roleCode}/competencies — 404, never the
    // draft's competencies (`firstOrFail()` on the scoped role query).
    $this->withToken($token)
        ->getJson('/api/framework/roles/DIROLE/competencies')
        ->assertNotFound();

    // (c) GET /api/framework/roles/{roleCode}/competencies/{competencyCode}/indicators — 404.
    $this->withToken($token)
        ->getJson('/api/framework/roles/DIROLE/competencies/DICOMP/indicators')
        ->assertNotFound();
});

test('FrameworkController potential-competencies never surfaces a competency that exists only in a draft', function (): void {
    $draft = FrameworkCatalogRevision::factory()->draft()->create();
    DB::table('framework_competencies')->insert([
        'revision_id' => $draft->id,
        'code' => 'DIPOT',
        'type' => 'potential',
        'name' => json_encode(['en' => 'DRAFTMUTATED potential name']),
        'definition' => json_encode(['en' => 'DRAFTMUTATED potential definition']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $competencies = $this->withToken($token)
        ->getJson('/api/framework/potential-competencies')
        ->assertOk()
        ->json('data');

    expect(collect($competencies)->where('code', 'DIPOT'))->toBeEmpty();
});

// ─── Live interview: SystemPromptComposer via /start ─────────────────────────

test('a live interview never composes against a role/competency that exists only in a draft', function (): void {
    // Deterministic proof: DIIROLE/DIICOMP exist ONLY in a draft, never in
    // any published revision. The project is pinned to the (empty, for this
    // code) published baseline. The pre-fix, unscoped `where('code', ...)`
    // query has exactly ONE matching row — the draft's — and resolves it
    // every time, composing a live candidate's prompt from UNPUBLISHED
    // content instead of correctly refusing composition. The fix must
    // refuse (422 composition_error), never silently succeed with draft
    // indicators.
    Queue::fake();
    Http::fake();

    $draft = FrameworkCatalogRevision::factory()->draft()->create();
    diSeedRevisionContent($draft->id, 'DIIROLE', 'DIICOMP', 'DRAFTMUTATED');

    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    // Project::factory() pins a fresh FrameworkVersion, which auto-resolves
    // to the latest PUBLISHED revision (the baseline) — never the draft,
    // per FrameworkVersion::assignLatestPublishedRevisionIfUnset(). The
    // baseline carries NO role/competency for these codes.
    $project = Project::factory()->create([
        'organization_id' => $org->id,
        'status' => 'active',
        'role_code' => 'DIIROLE',
        'language' => 'en',
        'assessment_type' => 'standard',
    ]);

    // project_competencies still needs a real, revision-scoped competency
    // row to resolve `competency_code` in resolveNextCompetency() — the
    // DRAFT's row is what a superadmin's clone would have produced, and is
    // exactly what must never reach composition.
    DB::table('project_competencies')->insert([
        'project_id' => $project->id,
        'competency_id' => DB::table('framework_competencies')
            ->where('revision_id', $draft->id)->where('code', 'DIICOMP')->value('id'),
        'position' => 1,
    ]);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'di-'.uniqid(),
        'display_name' => 'Draft Isolation Candidate',
        'email' => uniqid('di-cand-').'@example.test',
        'status' => 'in_attesa',
    ]);
    $participant->save();

    $bearer = CandidateTokenFactory::mintCandidateToken($participant->fresh());

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(422);
    $response->assertJsonPath('error', 'composition_error');
    Http::assertNothingSent();
});

// ─── Project creation: a draft-only competency id must never validate ───────

test('project creation refuses a competency id that belongs to a draft, not the target revision', function (): void {
    // ICO — one of the five closed role codes `validateStandard()` accepts
    // (`ValidatesProjectComposition`); a custom code would fail role_code
    // validation before ever reaching the composition check this test
    // targets. The BASELINE row is created here fresh (RefreshDatabase gives
    // an empty, unseeded catalogue) — no collision with any other test.
    $baselineId = diBaselineRevisionId();
    ['roleId' => $roleId, 'competencyId' => $publishedCompetencyId] =
        diSeedRevisionContent($baselineId, 'ICO', 'DIPCOMP', 'PUBLISHED');

    $draft = FrameworkCatalogRevision::factory()->draft()->create();
    ['competencyId' => $draftCompetencyId] =
        diSeedRevisionContent($draft->id, 'ICO', 'DIPCOMP', 'DRAFTMUTATED');

    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    // Assign ICO/DIPCOMP to the pivot in the PUBLISHED revision so the
    // published competency id is a legal selection for a standard project.
    DB::table('framework_role_competency')->updateOrInsert(
        ['revision_id' => $baselineId, 'role_id' => $roleId, 'competency_id' => $publishedCompetencyId],
        ['position' => 0],
    );

    $payload = [
        'framework_version_id' => $fv->id,
        'slug' => 'draft-isolation-'.uniqid(),
        'name' => 'Draft Isolation Project',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'competency_ids' => [$draftCompetencyId],
        'avatar_template_id' => templateIdForCurrentOrg(),
    ];

    $response = $this->withToken($token)->postJson('/api/projects', $payload);

    // The draft's competency id must never validate against a project
    // pinned to the published revision.
    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['competency_ids.0']);

    // The PUBLISHED competency id, by contrast, is a legal selection.
    $payload['competency_ids'] = [$publishedCompetencyId];
    $this->withToken($token)->postJson('/api/projects', $payload)->assertCreated();
});

// ─── Graceful degradation: zero published revisions never 500s ─────────────

test('an unseeded platform with no published revision answers 422/200-empty, never 500', function (): void {
    // Forced the same way `SeederLockGuardTest`'s own forced-draft scenario
    // does: the baseline is unconditionally published from migration (PR1),
    // so "zero published revisions" only exists via a raw update bypassing
    // the Eloquent immutability guard. `CatalogueRevisionResolver::
    // latestPublished()` throws for this — `tryLatestPublished()` (used by
    // every caller that must degrade gracefully) must never let that surface
    // as a 500 on an ordinary read or an ordinary validation failure.
    DB::table('framework_catalog_revisions')
        ->where('is_baseline', true)
        ->update(['state' => 'draft', 'published_at' => null]);

    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    // FrameworkController: 200 with an empty catalogue, never 500 — the
    // class's own documented contract for "nothing to show yet".
    $this->withToken($token)->getJson('/api/framework/roles')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($token)->getJson('/api/framework/potential-competencies')->assertOk()->assertJsonCount(0, 'data');

    // Project creation: 422, never 500 — `FrameworkVersion::factory()` also
    // resolves `revision_id` to null here (no published revision to assign).
    $adminToken = authTokenForRole($org, 'admin');
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $response = $this->withToken($adminToken)->postJson('/api/projects', [
        'framework_version_id' => $fv->id,
        'slug' => 'unseeded-'.uniqid(),
        'name' => 'Unseeded Platform Project',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'competency_ids' => [999999],
        'avatar_template_id' => templateIdForCurrentOrg(),
    ]);

    $response->assertUnprocessable();
});
