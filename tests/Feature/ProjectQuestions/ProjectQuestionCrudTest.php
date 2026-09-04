<?php

declare(strict_types=1);

/**
 * Predefined questions per project
 * (potential-competencies-and-authored-questions).
 *
 * They were never stored anywhere: the prompt composer derived everything from
 * BARS indicators at runtime, so an operator could not see, edit or reorder a
 * single question — while the binding domain doc has always specified that the
 * first question per competency MAY be predefined for `standard`, and that
 * `potential` asks FOUR predefined ones.
 */

use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\User;
use App\Support\Settings\PlatformSettings;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/** @return array{user: User, token: string} */
function pqAdmin(Organization $org): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return ['user' => $user, 'token' => auth('api')->login($user)];
}

function pqProject(Organization $org): Project
{
    // Both inside the scope: FrameworkVersion is tenant-scoped too, and
    // TenantScoped fails closed rather than guessing an organization.
    return TenantContextScope::runFor($org->id, function () use ($org): Project {
        $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

        return Project::factory()->create([
            'organization_id' => $org->id,
            'framework_version_id' => $fv->id,
        ]);
    });
}

function pqCompetency(string $type = 'standard'): Competency
{
    $code = $type === 'standard' ? 'PRS' : 'MTG';

    return Competency::firstOrCreate(
        ['code' => $code],
        ['name' => ['en' => 'Problem Solving'], 'definition' => ['en' => 'x'], 'type' => $type],
    );
}

test('an admin can author a question for a project competency', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = pqAdmin($org);
    $project = pqProject($org);
    $competency = pqCompetency();

    $response = $this->withToken($token)->postJson("/api/projects/{$project->id}/questions", [
        'competency_id' => $competency->id,
        'text' => ['en' => 'Tell me about a problem you untangled.', 'it' => 'Raccontami un problema che hai districato.'],
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.text.it', 'Raccontami un problema che hai districato.');
    $response->assertJsonPath('data.position', 0);
});

test('questions come back ordered by position', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = pqAdmin($org);
    $project = pqProject($org);
    $competency = pqCompetency();

    foreach (['third', 'first', 'second'] as $i => $label) {
        TenantContextScope::runFor($org->id, fn () => ProjectQuestion::create([
            'project_id' => $project->id,
            'competency_id' => $competency->id,
            'text' => ['en' => $label],
            'position' => [2, 0, 1][$i],
        ]));
    }

    $response = $this->withToken($token)->getJson("/api/projects/{$project->id}/questions");

    $response->assertOk();
    expect(array_column($response->json('data'), 'position'))->toBe([0, 1, 2]);
    expect($response->json('data.0.text.en'))->toBe('first');
});

test('reordering rewrites every position in one call', function (): void {
    // Drag-and-drop sends the whole ordered list. Rewriting the lot in one
    // transaction is what keeps the partial unique index satisfiable — moving
    // one row at a time would collide with the position it is moving into.
    $org = Organization::factory()->create();
    ['token' => $token] = pqAdmin($org);
    $project = pqProject($org);
    $competency = pqCompetency();

    $ids = [];
    foreach ([0, 1, 2] as $p) {
        $ids[] = TenantContextScope::runFor($org->id, fn (): int => ProjectQuestion::create([
            'project_id' => $project->id,
            'competency_id' => $competency->id,
            'text' => ['en' => "q{$p}"],
            'position' => $p,
        ])->id);
    }

    $reversed = array_reverse($ids);

    $this->withToken($token)
        ->putJson("/api/projects/{$project->id}/questions/order", ['ids' => $reversed])
        ->assertOk();

    $response = $this->withToken($token)->getJson("/api/projects/{$project->id}/questions");

    expect(array_column($response->json('data'), 'id'))->toBe($reversed);
});

test('deleting is a SOFT delete, so a conducted interview stays explainable', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = pqAdmin($org);
    $project = pqProject($org);
    $competency = pqCompetency();

    $id = TenantContextScope::runFor($org->id, fn (): int => ProjectQuestion::create([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'gone'],
        'position' => 0,
    ])->id);

    $this->withToken($token)
        ->deleteJson("/api/projects/{$project->id}/questions/{$id}")
        ->assertNoContent();

    // NOT `withoutGlobalScopes()` — that strips SoftDeletingScope along with
    // the tenant one, so a trashed row comes back and the assertion passes on
    // a HARD delete too, which is the opposite of what this test is for.
    $stillThere = TenantContextScope::runFor($org->id, fn () => [
        'live' => ProjectQuestion::find($id),
        'trashed' => ProjectQuestion::withTrashed()->find($id),
    ]);

    expect($stillThere['live'])->toBeNull()
        ->and($stillThere['trashed'])->not->toBeNull()
        ->and($stillThere['trashed']->deleted_at)->not->toBeNull();
});

test("another organization's project is invisible, not forbidden", function (): void {
    // 404 rather than 403: a 403 would confirm the project exists, which is an
    // existence oracle across tenants. Same doctrine the rest of the API uses.
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    ['token' => $token] = pqAdmin($mine);
    $foreign = pqProject($theirs);

    $this->withToken($token)
        ->getJson("/api/projects/{$foreign->id}/questions")
        ->assertNotFound();
});

// ─── Validation messages follow the operator's language ──────────────────────

/**
 * These are OPERATOR-FACING sentences, and they were English string literals.
 *
 * An operator working in the Italian backoffice hit the per-competency cap and
 * was told, mid-Italian-screen: "A standard project allows at most 1
 * question(s) per competency." The i18n mandate covers exactly this — the
 * MACHINE-facing half of a response (field names, enum values, HTTP status)
 * stays literal in every locale, but a sentence a human reads does not.
 *
 * `SetLocaleFromRequest` is prepended to the whole `api` middleware group, so
 * the locale is already resolved by the time a FormRequest validates.
 */
test('the per-competency cap message is returned in the requested language', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = pqAdmin($org);
    $project = pqProject($org);
    $competency = pqCompetency();

    // First one fills the `standard` cap of 1.
    $this->withToken($token)->postJson("/api/projects/{$project->id}/questions", [
        'competency_id' => $competency->id,
        'text' => ['en' => 'The one allowed question.'],
    ])->assertCreated();

    $italian = $this->withToken($token)
        ->withHeaders(['Accept-Language' => 'it'])
        ->postJson("/api/projects/{$project->id}/questions", [
            'competency_id' => $competency->id,
            'text' => ['en' => 'One too many.'],
        ]);

    $italian->assertStatus(422);

    $message = $italian->json('errors.competency_id.0');

    expect($message)->toContain('al massimo');
    expect($message)->not->toContain('allows at most');
});

test('the same cap message is English for an English operator', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = pqAdmin($org);
    $project = pqProject($org);
    $competency = pqCompetency();

    $this->withToken($token)->postJson("/api/projects/{$project->id}/questions", [
        'competency_id' => $competency->id,
        'text' => ['en' => 'The one allowed question.'],
    ])->assertCreated();

    $english = $this->withToken($token)
        ->withHeaders(['Accept-Language' => 'en'])
        ->postJson("/api/projects/{$project->id}/questions", [
            'competency_id' => $competency->id,
            'text' => ['en' => 'One too many.'],
        ]);

    $english->assertStatus(422);
    expect($english->json('errors.competency_id.0'))->toContain('at most');
});

test('raising the platform cap lets an operator author a second question', function (): void {
    // The proof the setting is WIRED, not merely stored. A knob that persists
    // and changes nothing is worse than no knob: the superadmin moves it,
    // watches it save, and the product keeps refusing.
    $org = Organization::factory()->create();
    ['token' => $token] = pqAdmin($org);
    $project = pqProject($org);
    $competency = pqCompetency();

    $this->withToken($token)->postJson("/api/projects/{$project->id}/questions", [
        'competency_id' => $competency->id,
        'text' => ['en' => 'First question.'],
    ])->assertCreated();

    // At the default cap of 1, the second is refused.
    $this->withToken($token)->postJson("/api/projects/{$project->id}/questions", [
        'competency_id' => $competency->id,
        'text' => ['en' => 'Second question.'],
    ])->assertStatus(422);

    app(PlatformSettings::class)
        ->setMaxQuestionsPerCompetency(['standard' => 2]);

    $this->withToken($token)->postJson("/api/projects/{$project->id}/questions", [
        'competency_id' => $competency->id,
        'text' => ['en' => 'Second question.'],
    ])->assertCreated();
});

/**
 * The plural branch of the cap message, which the cap test stops one request
 * short of ever rendering.
 *
 * `trans_choice` was chosen over interpolation precisely because "1 questions"
 * is wrong in English and has no "(s)" escape hatch in Italian — so the [2,*]
 * form is the whole reason the key has two branches, and nothing rendered it.
 */
test('the cap message uses the plural form once the cap is above one', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = pqAdmin($org);
    $project = pqProject($org);
    $competency = pqCompetency();

    app(PlatformSettings::class)->setMaxQuestionsPerCompetency(['standard' => 2]);

    foreach (['First question.', 'Second question.'] as $text) {
        $this->withToken($token)->postJson("/api/projects/{$project->id}/questions", [
            'competency_id' => $competency->id,
            'text' => ['en' => $text],
        ])->assertCreated();
    }

    $english = $this->withToken($token)
        ->withHeaders(['Accept-Language' => 'en'])
        ->postJson("/api/projects/{$project->id}/questions", [
            'competency_id' => $competency->id,
            'text' => ['en' => 'Third question.'],
        ])->assertStatus(422);

    expect($english->json('message'))->toContain('at most 2 questions');

    $italian = $this->withToken($token)
        ->withHeaders(['Accept-Language' => 'it'])
        ->postJson("/api/projects/{$project->id}/questions", [
            'competency_id' => $competency->id,
            'text' => ['en' => 'Third question.'],
        ])->assertStatus(422);

    // Italian too: the plural branch and the translated assessment type both
    // have to render, and neither may leave a raw token in the sentence.
    expect($italian->json('message'))->toContain('2 domande');
    expect($italian->json('message'))->not->toContain('standard;');
    expect($italian->json('message'))->not->toContain(':count');
});

/**
 * The type-mismatch message, which had no coverage at all.
 *
 * This is the one refusal that names TWO assessment types, and both used to
 * arrive as raw enum values mid-sentence. Delete either nested translation and
 * this goes red.
 */
test('the competency type mismatch message translates both assessment types', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = pqAdmin($org);
    $project = pqProject($org);
    $mismatched = pqCompetency('potential');

    $response = $this->withToken($token)
        ->withHeaders(['Accept-Language' => 'it'])
        ->postJson("/api/projects/{$project->id}/questions", [
            'competency_id' => $mismatched->id,
            'text' => ['en' => 'A question for the wrong competency type.'],
        ])->assertStatus(422);

    $message = $response->json('message');

    // Both types render translated, and the sentence stays grammatical with
    // either. The Italian 'potential' reads 'di potenziale', which the previous
    // template turned into "è di tipo di potenziale".
    expect($message)->toContain('di potenziale');
    expect($message)->toContain('standard');
    expect($message)->not->toContain('di tipo di');
    expect($message)->not->toContain('messages.project_questions');
});
