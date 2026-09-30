<?php

declare(strict_types=1);

/**
 * Only an explicit platform context may write a platform (NULL-organization)
 * avatar template, and that context may write nothing else (A1).
 *
 * The rule is symmetric on purpose: `(organization_id IS NULL) === context
 * active` for create, update, delete and restore, so neither side can reach
 * the other by accident.
 */

use App\Exceptions\PlatformTemplateWriteRefusedException;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\AvatarTemplates\PlatformTemplateContext;
use App\Support\Tenancy\ActingOrganization;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;

function wgSuperadmin(): User
{
    return User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
}

/** @return array<string, mixed> */
function wgPayload(string $name): array
{
    return ['name' => $name, 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v']];
}

function wgRunPlatform(callable $fn): mixed
{
    return app(PlatformTemplateContext::class)->run(wgSuperadmin(), $fn);
}

test('creating inside the platform context persists a NULL organization, bare or acting', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setBypass(true);
    $bare = wgRunPlatform(fn (): AvatarTemplate => AvatarTemplate::create(wgPayload('Bare')));
    $resolver->setBypass(false);

    $acting = TenantContextScope::runFor($org->id, fn () => wgRunPlatform(
        fn (): AvatarTemplate => AvatarTemplate::create(wgPayload('Acting'))
    ));

    expect($bare->organization_id)->toBeNull()->and($acting->organization_id)->toBeNull()
        ->and(AvatarTemplate::platformOnly()->whereKey([$bare->id, $acting->id])->count())->toBe(2);
});

test('creating outside the platform context with no tenant still throws MissingTenantContext', function (): void {
    expect(fn () => AvatarTemplate::create(wgPayload('Nowhere')))->toThrow(MissingTenantContextException::class);
});

test('creating in an organization context stamps the organization, never NULL', function (): void {
    $org = Organization::factory()->create();

    $row = TenantContextScope::runFor($org->id, function (): AvatarTemplate {
        $template = new AvatarTemplate(wgPayload('Owned'));
        $template->forceFill(['organization_id' => null])->save();

        return $template;
    });

    expect($row->organization_id)->toBe($org->id);
});

test('POST /api/avatar-templates as a superadmin acting as an org creates that org\'s row', function (): void {
    $org = Organization::factory()->create();
    $su = wgSuperadmin();
    app(ActingOrganization::class)->set((int) $su->id, $org->id);

    $id = $this->withToken(auth('api')->login($su))
        ->postJson('/api/avatar-templates', wgPayload('Via the API'))
        ->assertCreated()
        ->json('data.id');

    expect(AvatarTemplate::withoutGlobalScopes()->find($id)->organization_id)->toBe($org->id);
});

test('update, delete, force delete and restore of a platform row are refused outside the context', function (): void {
    $global = PlatformTemplates::insertGlobal();
    $trashed = PlatformTemplates::insertGlobal(['deleted_at' => now()]);

    $live = AvatarTemplate::platformOnly()->findOrFail($global->id);
    $gone = AvatarTemplate::platformOnly()->onlyTrashed()->findOrFail($trashed->id);

    expect(fn () => $live->update(['name' => 'Changed']))->toThrow(PlatformTemplateWriteRefusedException::class)
        ->and(fn () => $live->delete())->toThrow(PlatformTemplateWriteRefusedException::class)
        ->and(fn () => $live->forceDelete())->toThrow(PlatformTemplateWriteRefusedException::class)
        ->and(fn () => $gone->restore())->toThrow(PlatformTemplateWriteRefusedException::class);

    expect(AvatarTemplate::platformOnly()->whereKey($global->id)->value('name'))->toBe($global->name);
});

test('update, delete and restore of a platform row are allowed inside the context', function (): void {
    $global = PlatformTemplates::insertGlobal();

    wgRunPlatform(function () use ($global): void {
        $row = AvatarTemplate::platformOnly()->findOrFail($global->id);
        $row->update(['name' => 'Renamed']);
        $row->delete();
        AvatarTemplate::platformOnly()->onlyTrashed()->findOrFail($global->id)->restore();
    });

    expect(AvatarTemplate::platformOnly()->whereKey($global->id)->value('name'))->toBe('Renamed');
});

test('an organization row cannot be updated or deleted from inside the platform context', function (): void {
    $org = Organization::factory()->create();
    $row = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create(wgPayload('Owned')));

    TenantContextScope::runFor($org->id, function () use ($row): void {
        wgRunPlatform(function () use ($row): void {
            expect(fn () => $row->update(['name' => 'Nope']))->toThrow(PlatformTemplateWriteRefusedException::class)
                ->and(fn () => $row->delete())->toThrow(PlatformTemplateWriteRefusedException::class);
        });
    });

    expect(AvatarTemplate::withoutGlobalScopes()->find($row->id)->name)->toBe('Owned');
});

test('the organization of a row can never change on update', function (): void {
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    $row = TenantContextScope::runFor($a->id, fn (): AvatarTemplate => AvatarTemplate::create(wgPayload('Owned')));

    TenantContextScope::runFor($a->id, function () use ($row, $b): void {
        $row->forceFill(['organization_id' => $b->id]);
        expect(fn () => $row->save())->toThrow(PlatformTemplateWriteRefusedException::class);
    });

    // Promoting an organization row to the platform is refused too, even inside the context.
    $promote = AvatarTemplate::withoutGlobalScopes()->find($row->id);
    wgRunPlatform(function () use ($promote): void {
        $promote->forceFill(['organization_id' => null]);
        expect(fn () => $promote->save())->toThrow(PlatformTemplateWriteRefusedException::class);
    });

    expect(AvatarTemplate::withoutGlobalScopes()->find($row->id)->organization_id)->toBe($a->id);
});

test('provider bookkeeping saved quietly on a platform row works from any context', function (): void {
    $global = PlatformTemplates::insertGlobal();
    $org = Organization::factory()->create();

    TenantContextScope::runFor($org->id, function () use ($global): void {
        $row = AvatarTemplate::platformOnly()->findOrFail($global->id);
        $row->forceFill(['llm_sync_status' => 'ok'])->saveQuietly();
    });

    expect(AvatarTemplate::platformOnly()->whereKey($global->id)->value('llm_sync_status'))->toBe('ok');
});

test('a quiet save can never re-home a template or move it across the platform boundary', function (): void {
    // `saveQuietly()` skips model EVENTS, so the guards above never see it; the
    // model overrides it to apply the same organization invariant directly.
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal();
    $owned = TenantContextScope::runFor($a->id, fn (): AvatarTemplate => AvatarTemplate::create(wgPayload('Owned')));

    // platform -> organization
    $row = AvatarTemplate::platformOnly()->findOrFail($global->id);
    $row->forceFill(['organization_id' => $b->id]);
    expect(fn () => $row->saveQuietly())->toThrow(PlatformTemplateWriteRefusedException::class);

    // organization -> another organization, and organization -> platform
    foreach ([$b->id, null] as $target) {
        $row = AvatarTemplate::withoutGlobalScopes()->findOrFail($owned->id);
        $row->organization_id = $target;
        expect(fn () => $row->saveQuietly())->toThrow(PlatformTemplateWriteRefusedException::class);
    }

    // The other quiet entry point routes through the same check.
    $row = AvatarTemplate::withoutGlobalScopes()->findOrFail($owned->id);
    $row->organization_id = $b->id;
    expect(fn () => $row->updateQuietly(['description' => 'x']))->toThrow(PlatformTemplateWriteRefusedException::class);

    expect(AvatarTemplate::withoutGlobalScopes()->find($global->id)->organization_id)->toBeNull()
        ->and(AvatarTemplate::withoutGlobalScopes()->find($owned->id)->organization_id)->toBe($a->id);
});

test('a quiet create of a platform row outside the platform context is refused', function (): void {
    $row = new AvatarTemplate(wgPayload('Quiet global'));
    $row->organization_id = null;

    expect(fn () => $row->saveQuietly())->toThrow(PlatformTemplateWriteRefusedException::class)
        ->and(AvatarTemplate::withoutGlobalScopes()->where('name', 'Quiet global')->exists())->toBeFalse();
});

test('provider bookkeeping saved quietly on an organization row works from a bare context', function (): void {
    $org = Organization::factory()->create();
    $owned = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create(wgPayload('Owned')));

    $row = AvatarTemplate::withoutGlobalScopes()->findOrFail($owned->id);
    $row->forceFill(['llm_sync_status' => 'synced'])->saveQuietly();
    $row->updateQuietly(['description' => 'edited quietly']);

    $fresh = AvatarTemplate::withoutGlobalScopes()->findOrFail($owned->id);
    expect($fresh->llm_sync_status)->toBe('synced')->and($fresh->description)->toBe('edited quietly');
});

test('other tenant models are untouched by the platform branch', function (): void {
    expect(fn () => Project::create(['name' => 'No tenant']))->toThrow(MissingTenantContextException::class);

    // Even inside the platform context: only AvatarTemplate admits platform rows.
    expect(fn () => wgRunPlatform(fn () => Project::create(['name' => 'Still no tenant'])))
        ->toThrow(MissingTenantContextException::class);
});
