<?php

declare(strict_types=1);

/**
 * Deleting a project or a template is an ADMIN act, and it hides rather than
 * destroys.
 *
 * `ProjectPolicy::delete` allowed `admin` OR `operator`. A project carries
 * every participant, session, transcript and evaluation beneath it — that is
 * not the same class of act as editing its settings, which an operator may
 * still do, and the two had been sharing one permission.
 *
 * Both models soft-delete, which is what makes narrowing the permission safe to
 * apply to existing data rather than a reason to postpone it: nothing is
 * destroyed either way.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{user: User, token: string}
 */
function deletionUser(Organization $org, string $role): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatie = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatie);
    app(TenantResolver::class)->setOrgId($org->id);

    return ['user' => $user, 'token' => auth('api')->login($user)];
}

// ─── Projects ─────────────────────────────────────────────────────────────────

test('an operator can no longer delete a project', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = deletionUser($org, 'operator');
    $project = Project::factory()->create();

    $this->withToken($token)->deleteJson('/api/projects/'.$project->id)->assertForbidden();

    expect(Project::withTrashed()->find($project->id)?->trashed())->toBeFalse();
});

test('an observer can no longer delete a project', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = deletionUser($org, 'viewer');
    $project = Project::factory()->create();

    $this->withToken($token)->deleteJson('/api/projects/'.$project->id)->assertForbidden();
});

test('an admin can delete a project, and it is SOFT-deleted', function (): void {
    // Hidden, not destroyed. Every participant, session and evaluation beneath
    // it keeps its foreign key intact — a hard delete would either break those
    // rows or take the assessment history with them.
    $org = Organization::factory()->create();
    ['token' => $token] = deletionUser($org, 'admin');
    $project = Project::factory()->create();

    $this->withToken($token)->deleteJson('/api/projects/'.$project->id)->assertSuccessful();

    expect(Project::find($project->id))->toBeNull()
        ->and(Project::withTrashed()->find($project->id)?->trashed())->toBeTrue();
});

// ─── Templates ────────────────────────────────────────────────────────────────

test('an operator cannot delete a template', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = deletionUser($org, 'operator');
    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Deletable '.uniqid(),
        'provider' => 'heygen',
        'config' => [],
    ]));

    $this->withToken($token)->deleteJson('/api/avatar-templates/'.$template->id)->assertForbidden();

    expect(AvatarTemplate::withTrashed()->find($template->id)?->trashed())->toBeFalse();
});

test('an admin deleting a template SOFT-deletes it', function (): void {
    // `interview_sessions.avatar_template_id` and every historical cost row
    // reference this. A hard delete either breaks them or takes the history.
    $org = Organization::factory()->create();
    ['token' => $token] = deletionUser($org, 'admin');
    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Deletable '.uniqid(),
        'provider' => 'heygen',
        'config' => [],
    ]));

    $this->withToken($token)->deleteJson('/api/avatar-templates/'.$template->id)->assertSuccessful();

    expect(AvatarTemplate::find($template->id))->toBeNull()
        ->and(AvatarTemplate::withTrashed()->find($template->id)?->trashed())->toBeTrue();
});

test('a deleted template frees its name and its active slot', function (): void {
    // Both unique indexes had to learn about `deleted_at`, and a plain
    // `softDeletes()` call would have missed it. Without the partial predicates
    // a removed template keeps its name reserved forever — so an operator
    // cannot recreate one they deleted by mistake — and, if it had been active,
    // keeps occupying the one active slot for its provider so no replacement
    // can be activated.
    $org = Organization::factory()->create();

    TenantContextScope::runFor($org->id, function (): void {
        $first = AvatarTemplate::create([
            'name' => 'Reusable name',
            'provider' => 'heygen',
            'config' => [],
            'is_active' => true,
        ]);

        $first->delete();

        // The same name and the same active slot, both free again.
        $second = AvatarTemplate::create([
            'name' => 'Reusable name',
            'provider' => 'heygen',
            'config' => [],
            'is_active' => true,
        ]);

        expect($second->id)->not->toBe($first->id);
    });
});

test('both unique indexes are PARTIAL on deleted_at', function (): void {
    // Asserted against the catalogue rather than only through behaviour: the
    // predicate is the thing that makes the case above work, and reading it
    // back names exactly what a future migration must not drop.
    $indexes = collect(DB::select(
        "select indexdef from pg_indexes where tablename = 'avatar_templates' and indexdef like '%UNIQUE%'"
    ))->pluck('indexdef')->implode("\n");

    expect($indexes)->toContain('deleted_at IS NULL');
});
