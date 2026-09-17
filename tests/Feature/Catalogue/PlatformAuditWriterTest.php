<?php

declare(strict_types=1);

/**
 * RED — Task 30.1 (framework-catalogue-authoring PR8, design D13): every
 * catalogue write and `revision.published` produce exactly one `audit_logs`
 * row with NULL `organization_id`, the acting superadmin, and `before`/
 * `after` restricted to the changed attributes; a tenant-scoped read never
 * returns the row; the dashboard activity feed is unaffected.
 *
 * REQ: audit-log — "Catalogue Mutations Are Audited".
 */

use App\Models\AuditLog;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tymon\JWTAuth\Facades\JWTAuth;

function pawSuperadminUser(): User
{
    return User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
}

function pawSuperadminToken(User $user): string
{
    return auth('api')->login($user);
}

function pawOpenDraftCompetency(string $token, string $code): int
{
    test()->withToken($token)->postJson('/api/catalogue/competencies', [
        'code' => $code,
        'type' => 'standard',
        'name' => ['en' => 'x', 'it' => 'x'],
        'definition' => ['en' => 'x', 'it' => 'x'],
    ])->assertStatus(201);

    return (int) DB::table('framework_competencies')->where('code', $code)->value('id');
}

test('creating a catalogue competency produces exactly one audit_logs row, NULL-org, naming the actor', function (): void {
    $user = pawSuperadminUser();
    $token = pawSuperadminToken($user);

    $competencyId = pawOpenDraftCompetency($token, 'PAWCOMP1');

    $row = DB::table('audit_logs')
        ->where('subject_type', 'Competency')
        ->where('subject_id', $competencyId)
        ->get();

    expect($row)->toHaveCount(1);

    $entry = $row->first();
    expect($entry->organization_id)->toBeNull();
    expect($entry->actor_id)->toBe($user->id);
    expect($entry->action)->toBe('catalogue.competency.created');
    expect($entry->before)->toBeNull();

    $after = json_decode((string) $entry->after, true);
    expect($after['code'])->toBe('PAWCOMP1');
    expect($after)->toHaveKey('revision_id');
});

test('updating a catalogue competency produces one audit_logs row with before/after restricted to the changed attributes', function (): void {
    $user = pawSuperadminUser();
    $token = pawSuperadminToken($user);

    $competencyId = pawOpenDraftCompetency($token, 'PAWCOMP2');

    test()->withToken($token)->patchJson("/api/catalogue/competencies/{$competencyId}", [
        'name' => ['en' => 'Renamed', 'it' => 'Rinominato'],
    ])->assertStatus(200);

    $entry = DB::table('audit_logs')
        ->where('subject_type', 'Competency')
        ->where('subject_id', $competencyId)
        ->where('action', 'catalogue.competency.updated')
        ->first();

    expect($entry)->not->toBeNull();
    expect($entry->organization_id)->toBeNull();
    expect($entry->actor_id)->toBe($user->id);

    // Restricted to the changed attribute — `code`/`type`, never touched by
    // this PATCH, must not appear in the delta.
    $before = json_decode((string) $entry->before, true);
    $after = json_decode((string) $entry->after, true);

    expect($after)->toHaveKey('name');
    expect($before)->toHaveKey('name');
    expect($after)->not->toHaveKey('code');
    expect($before)->not->toHaveKey('code');

    // The VALUES, not merely the keys: `before` must be the row's state
    // PRIOR to this update, `after` the state it holds now. A writer that
    // captured `getOriginal()` AFTER `update()` already ran would show the
    // NEW value on both sides — this is the assertion that catches that.
    $beforeName = is_string($before['name']) ? json_decode($before['name'], true) : $before['name'];
    $afterName = is_string($after['name']) ? json_decode($after['name'], true) : $after['name'];

    expect($beforeName)->toBe(['en' => 'x', 'it' => 'x']);
    expect($afterName)->toBe(['en' => 'Renamed', 'it' => 'Rinominato']);
});

test('deleting a catalogue competency produces one audit_logs row with the deleted row in before, null after', function (): void {
    $user = pawSuperadminUser();
    $token = pawSuperadminToken($user);

    $competencyId = pawOpenDraftCompetency($token, 'PAWCOMP3');

    test()->withToken($token)->deleteJson("/api/catalogue/competencies/{$competencyId}")->assertStatus(204);

    $entry = DB::table('audit_logs')
        ->where('subject_type', 'Competency')
        ->where('subject_id', $competencyId)
        ->where('action', 'catalogue.competency.deleted')
        ->first();

    expect($entry)->not->toBeNull();
    expect($entry->organization_id)->toBeNull();
    expect($entry->after)->toBeNull();

    $before = json_decode((string) $entry->before, true);
    expect($before['code'])->toBe('PAWCOMP3');
});

test('every catalogue-write resource is audited: role, indicator, default question', function (): void {
    $user = pawSuperadminUser();
    $token = pawSuperadminToken($user);

    $roleId = test()->withToken($token)->postJson('/api/catalogue/roles', [
        'code' => 'PAWROLE1',
        'name' => ['en' => 'x', 'it' => 'x'],
        'responsibilities' => ['en' => 'x', 'it' => 'x'],
    ])->assertStatus(201)->json('data.id');

    $competencyId = pawOpenDraftCompetency($token, 'PAWCOMP4');

    $indicatorId = test()->withToken($token)->postJson('/api/catalogue/bars-indicators', [
        'role_id' => $roleId,
        'competency_id' => $competencyId,
        'position' => 0,
        'text' => ['en' => 'x', 'it' => 'x'],
        'anchor_5' => ['en' => 'x', 'it' => 'x'],
        'anchor_3' => ['en' => 'x', 'it' => 'x'],
        'anchor_1' => ['en' => 'x', 'it' => 'x'],
    ])->assertStatus(201)->json('data.id');

    $defaultQuestionId = test()->withToken($token)->postJson('/api/catalogue/default-questions', [
        'competency_id' => $competencyId,
        'text' => ['en' => 'x', 'it' => 'x'],
        'position' => 0,
    ])->assertStatus(201)->json('data.id');

    expect(
        DB::table('audit_logs')
            ->where('subject_type', 'Role')->where('subject_id', $roleId)
            ->where('action', 'catalogue.role.created')->exists()
    )->toBeTrue();

    expect(
        DB::table('audit_logs')
            ->where('subject_type', 'BarsIndicator')->where('subject_id', $indicatorId)
            ->where('action', 'catalogue.indicator.created')->exists()
    )->toBeTrue();

    expect(
        DB::table('audit_logs')
            ->where('subject_type', 'FrameworkDefaultQuestion')->where('subject_id', $defaultQuestionId)
            ->where('action', 'catalogue.default_question.created')->exists()
    )->toBeTrue();

    // Every row from this test is platform-scoped.
    expect(
        DB::table('audit_logs')
            ->whereIn('action', [
                'catalogue.role.created',
                'catalogue.indicator.created',
                'catalogue.default_question.created',
            ])
            ->whereNotNull('organization_id')
            ->exists()
    )->toBeFalse();
});

/**
 * Z17 (R3-publish-audit-vacuous, REQUIRED BEFORE ARCHIVE): the original
 * test wrapped its only assertions in `if ($response->status() === 200)`,
 * so a publish sweep refusal (or a later change that makes one more
 * likely) would make this test pass VACUOUSLY — green in CI, having
 * proven nothing. `PAWPUB1` is a harmless UNASSIGNED standard competency —
 * no pivot row, no indicators — which none of `PublishRevision::
 * violations()`'s checks (indicator counts, empty pairs, potential-in-
 * pivot, role-scoped/role-less indicators, cross-role duplicates) ever
 * examine, so the cloned baseline plus this one addition is ALWAYS
 * publishable; `assertStatus(200)` makes that a hard requirement of the
 * test itself, not an assumption its own assertions silently depended on.
 */
test('publishing a revision produces exactly one audit_logs row naming the actor and the revision', function (): void {
    $user = pawSuperadminUser();
    $token = pawSuperadminToken($user);

    pawOpenDraftCompetency($token, 'PAWPUB1');
    $draft = FrameworkCatalogRevision::openDraft();
    expect($draft)->not->toBeNull();

    $response = test()->withToken($token)->postJson('/api/catalogue/revisions/publish');
    $response->assertStatus(200);

    $row = DB::table('audit_logs')
        ->where('subject_type', 'FrameworkCatalogRevision')
        ->where('subject_id', $draft->id)
        ->where('action', 'revision.published')
        ->get();

    expect($row)->toHaveCount(1);

    $entry = $row->first();
    expect($entry->organization_id)->toBeNull();
    expect($entry->actor_id)->toBe($user->id);
    expect($entry->before)->toBeNull();

    // The exact payload PublishRevision::publish() records (design D13) —
    // never merely "an audit row exists", the fields that make it useful.
    $after = json_decode((string) $entry->after, true, 512, JSON_THROW_ON_ERROR);
    expect($after['revision_id'])->toBe($draft->id);
    expect($after)->toHaveKey('label');
    expect($after)->toHaveKey('published_at');

    // The revision itself genuinely transitioned — the audit row is not
    // merely a side effect of a request that changed nothing.
    expect($draft->fresh()->state)->toBe('published');
});

test('a tenant-scoped AuditLog read never returns a platform (NULL-org) row', function (): void {
    $user = pawSuperadminUser();
    $token = pawSuperadminToken($user);

    $competencyId = pawOpenDraftCompetency($token, 'PAWTENANT1');

    expect(
        DB::table('audit_logs')
            ->where('subject_type', 'Competency')->where('subject_id', $competencyId)
            ->whereNull('organization_id')->exists()
    )->toBeTrue();

    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    app(TenantResolver::class)->setBypass(false);

    // TenantScoped's global scope filters `organization_id = X`, which SQL
    // never matches against NULL — no code change needed for this to hold,
    // this test is the proof.
    $tenantScoped = AuditLog::where('subject_type', 'Competency')->where('subject_id', $competencyId)->get();

    expect($tenantScoped)->toBeEmpty();
});

/**
 * Z17 (R3-dashboard-feed-weak, REQUIRED BEFORE ARCHIVE): a bare
 * `assertStatus(200)` also passes if the feed silently returned the wrong
 * content, or nothing at all — it proves the endpoint did not 500, not
 * that it is genuinely unaffected. Asserts the feed's actual CONTENT: the
 * tenant's own participant is present, byte-for-byte identifiable, and the
 * platform catalogue write (a DIFFERENT table, `audit_logs`, that
 * `DashboardActivityResource`'s own `Participant`-only feed never reads)
 * contributes no row and no leak — exactly one item, this tenant's own.
 */
test('the dashboard activity feed is unaffected by a platform catalogue audit write', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $adminUser = User::factory()->create(['organization_id' => $org->id]);
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $adminUser->assignRole($adminRole);
    // `JWTAuth::fromUser()`, NOT `auth('api')->login()`: this test
    // authenticates TWO different identities (the admin and the
    // superadmin) — `login()` sets the STATEFUL cached user on the shared
    // 'api' guard instance, which a later `login()` call for the OTHER
    // identity then overrides for every subsequent request in this SAME
    // test, regardless of which Bearer token that request's own header
    // carries. `fromUser()` mints an equivalent signed token WITHOUT
    // touching guard state, so each HTTP call resolves its OWN token
    // fresh, exactly as a real client would.
    $adminToken = JWTAuth::fromUser($adminUser);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['organization_id' => $org->id, 'framework_version_id' => $fv->id]);
    $participant = Participant::factory()->create(['project_id' => $project->id, 'organization_id' => $org->id]);

    $superadmin = pawSuperadminUser();
    $superadminToken = JWTAuth::fromUser($superadmin);
    pawOpenDraftCompetency($superadminToken, 'PAWDASH1');

    // BOTH calls are required (verified empirically, not assumed — neither
    // one alone fixes it): `Tymon\JWTAuth\JWT` (`tymon.jwt`) is a SINGLETON
    // that caches its OWN parsed `$this->token` the FIRST time any request
    // resolves a user, and never re-parses it on a later request's
    // `setRequest()` call — `unsetToken()` clears that cache.
    // `JWTGuard::user()` SEPARATELY caches `$this->user` on the guard
    // instance itself once resolved; `forgetGuards()` drops that cached
    // guard so the next `auth('api')` resolution constructs a fresh one.
    // Without BOTH, a test that authenticates a SECOND identity (here: the
    // superadmin, for the catalogue write) keeps resolving the FIRST one
    // (the admin) for every later request, regardless of which Bearer
    // token that request's own header carries — this test genuinely
    // authenticates two different actors, so it needs both resets between
    // them.
    app('tymon.jwt')->unsetToken();
    app('auth')->forgetGuards();

    $response = test()->withToken($adminToken)->getJson('/api/dashboard/activity');
    $response->assertStatus(200);

    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe($participant->id);
});
