<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\ConversationPromptOverride;
use App\Models\ConversationPromptSet;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkDefaultQuestion;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Str;
use Tests\Helpers\Conversation\PromptTables;

/*
 * Two groups live here: model:prune for refresh tokens, and the relationship
 * accessors of the catalogue, audit, prompt-override and notification models.
 */

// ─── RefreshToken ────────────────────────────────────────────────────────────

function refreshTokenRow(User $user, array $overrides = []): RefreshToken
{
    return RefreshToken::create(array_merge([
        'user_id' => $user->id,
        'family_id' => (string) Str::uuid(),
        'token_hash' => hash('sha256', Str::random(32)),
        'generation' => 0,
        'absolute_expires_at' => now()->addDays(14),
    ], $overrides));
}

test('model:prune removes ceiling-expired and revoked refresh tokens and keeps live ones', function (): void {
    $user = User::factory()->create();
    $live = refreshTokenRow($user);
    $consumedButLive = refreshTokenRow($user, ['consumed_at' => now()->subMinute()]);
    $expired = refreshTokenRow($user, ['absolute_expires_at' => now()->subSecond()]);
    $revoked = refreshTokenRow($user, ['revoked_at' => now()->subMinute()]);

    $this->artisan('model:prune', ['--model' => RefreshToken::class])->assertSuccessful();

    expect(RefreshToken::query()->pluck('id')->all())
        ->toEqualCanonicalizing([$live->id, $consumedButLive->id])
        ->not->toContain($expired->id, $revoked->id);
});

test('a refresh token belongs to its user', function (): void {
    $user = User::factory()->create();

    expect(refreshTokenRow($user)->user->is($user))->toBeTrue();
});

// ─── Catalogue content wiring ────────────────────────────────────────────────

test('a revision exposes the catalogue content it owns and each row points back at it', function (): void {
    $revision = FrameworkCatalogRevision::factory()->draft()->create();
    $role = Role::factory()->create(['revision_id' => $revision->id]);
    $competency = Competency::factory()->create(['revision_id' => $revision->id]);
    $indicator = BarsIndicator::factory()->create([
        'revision_id' => $revision->id,
        'role_id' => $role->id,
        'competency_id' => $competency->id,
    ]);
    $question = FrameworkDefaultQuestion::factory()->create(['competency_id' => $competency->id]);

    expect($revision->roles->modelKeys())->toBe([$role->id])
        ->and($revision->competencies->modelKeys())->toBe([$competency->id])
        ->and($revision->barsIndicators->modelKeys())->toBe([$indicator->id])
        ->and($revision->defaultQuestions->modelKeys())->toBe([$question->id])
        ->and($indicator->revision->is($revision))->toBeTrue()
        ->and($indicator->role->is($role))->toBeTrue()
        ->and($indicator->competency->is($competency))->toBeTrue()
        ->and($competency->revision->is($revision))->toBeTrue();
});

test('a revision knows who published it and which revision it was cloned from', function (): void {
    $publisher = User::factory()->create();
    $parent = FrameworkCatalogRevision::factory()->create();
    $child = FrameworkCatalogRevision::factory()->draft()->create([
        'parent_revision_id' => $parent->id,
        'published_by_user_id' => $publisher->id,
    ]);

    expect($child->parent->is($parent))->toBeTrue()
        ->and($child->publishedBy->is($publisher))->toBeTrue()
        ->and($parent->parent)->toBeNull();
});

// ─── Audit / prompt overrides ────────────────────────────────────────────────

test('an audit log resolves its actor', function (): void {
    $org = Organization::factory()->create();
    $actor = User::factory()->create(['organization_id' => $org->id]);
    app(TenantResolver::class)->setOrgId($org->id);

    $log = AuditLog::factory()->create(['actor_id' => $actor->id]);

    expect($log->actor->is($actor))->toBeTrue();
});

test('a prompt override resolves the set it belongs to', function (): void {
    PromptTables::empty();
    $set = ConversationPromptSet::query()->create(['label' => 'baseline', 'content_sha256' => str_repeat('b', 64)]);
    $override = $set->overrides()->create(['role_code' => 'ICO', 'competency_code' => 'COL', 'locale' => 'en', 'body' => 'Probe.']);

    expect(ConversationPromptOverride::query()->findOrFail($override->id)->promptSet->is($set))->toBeTrue();
});

// ─── Tenant-scoped records resolve their parents ─────────────────────────────

test('a notification log resolves its organization', function (): void {
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);

    $log = NotificationLog::factory()->create(['organization_id' => $org->id]);

    expect($log->organization_id)->toBe($org->id)
        ->and($log->organization->is($org))->toBeTrue();
});

test('a role resolves its catalogue revision', function (): void {
    $revision = FrameworkCatalogRevision::factory()->draft()->create();

    expect(Role::factory()->create(['revision_id' => $revision->id])->revision->is($revision))->toBeTrue();
});
