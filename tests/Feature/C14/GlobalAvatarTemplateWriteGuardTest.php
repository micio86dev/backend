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

test('the guards are model events, so a quiet save skips them and app code must never write organization_id that way', function (): void {
    // Pins the boundary rather than blessing it. Provider bookkeeping needs
    // saveQuietly() to work on a platform row from any context (test above), and
    // the same mechanism would let a quiet write re-home a template. Nothing in
    // the application does that, and AvatarTemplateScopeEntryPointsArchTest
    // ('quiet writes on an avatar template never carry organization_id') keeps
    // it that way: this is a code-review rule, not a runtime guarantee.
    $global = PlatformTemplates::insertGlobal();
    $org = Organization::factory()->create();

    $row = AvatarTemplate::platformOnly()->findOrFail($global->id);
    $row->forceFill(['organization_id' => $org->id])->saveQuietly();

    expect(AvatarTemplate::withoutGlobalScopes()->find($global->id)->organization_id)->toBe($org->id);
});

test('other tenant models are untouched by the platform branch', function (): void {
    expect(fn () => Project::create(['name' => 'No tenant']))->toThrow(MissingTenantContextException::class);

    // Even inside the platform context: only AvatarTemplate admits platform rows.
    expect(fn () => wgRunPlatform(fn () => Project::create(['name' => 'Still no tenant'])))
        ->toThrow(MissingTenantContextException::class);
});
