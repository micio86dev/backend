<?php

declare(strict_types=1);

/**
 * The `deleted` hook of `AvatarTemplate` unbinds the language model of a soft-deleted template through a query
 * that strips the global scopes (the soft-deleted row is invisible to a scoped query). That strip must stay
 * confined to the deleted row (hr-avatar-adapter A12): soft-deleting one organization's bound template leaves an
 * identically bound template of another organization, and a bound platform template, exactly as they were.
 */

use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Models\User;
use App\Support\AvatarTemplates\PlatformTemplateContext;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

/** A heygen template row bound to the given model and credential; `$orgId` null is a platform template. */
function dhsBoundTemplate(?int $orgId, string $name, LlmModel $model, LlmCredential $credential): int
{
    return DB::table('avatar_templates')->insertGetId([
        'organization_id' => $orgId,
        'name' => $name,
        'provider' => 'heygen',
        'config' => json_encode(['avatarId' => 'av', 'voiceId' => 'v']),
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return array{0: int|null, 1: int|null, 2: string|null} */
function dhsRow(int $id): array
{
    $row = DB::table('avatar_templates')->where('id', $id)->first();

    return [$row->llm_model_id, $row->llm_credential_id, $row->deleted_at];
}

/** @return array{0: LlmModel, 1: LlmCredential} */
function dhsBinding(): array
{
    $model = LlmModel::create([
        'key' => 'dhs-model',
        'vendor' => 'google',
        'display_name' => 'Model',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
    ]);
    $credential = new LlmCredential;
    $credential->forceFill([
        'name' => 'Hook scope credential',
        'vendor' => 'google',
        'api_key' => 'sk-real-key',
        'key_last_four' => 'real',
        'key_fingerprint' => hash('sha256', uniqid('', true)),
    ])->save();

    return [$model, $credential];
}

test('soft-deleting a bound template unbinds only that row', function (): void {
    [$model, $credential] = dhsBinding();

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $deleted = dhsBoundTemplate($orgA->id, 'Org A', $model, $credential);
    $otherOrg = dhsBoundTemplate($orgB->id, 'Org B', $model, $credential);
    $platform = dhsBoundTemplate(null, 'Platform', $model, $credential);

    TenantContextScope::runFor($orgA->id, fn () => AvatarTemplate::findOrFail($deleted)->delete());

    [$deletedModel, $deletedCredential, $deletedAt] = dhsRow($deleted);
    expect($deletedModel)->toBeNull()
        ->and($deletedCredential)->toBeNull()
        ->and($deletedAt)->not->toBeNull();

    foreach ([$otherOrg, $platform] as $untouched) {
        expect(dhsRow($untouched))->toBe([$model->id, $credential->id, null]);
    }
});

test('deleting a bound platform template through the platform route unbinds it and leaves the others', function (): void {
    [$model, $credential] = dhsBinding();
    $org = Organization::factory()->create();
    $platform = dhsBoundTemplate(null, 'Platform', $model, $credential);
    $otherPlatform = dhsBoundTemplate(null, 'Other platform', $model, $credential);
    $ownedByOrg = dhsBoundTemplate($org->id, 'Org', $model, $credential);

    $this->withToken(TemplateActors::token('bare', $org))->deleteJson("/api/admin/avatar-templates/{$platform}")->assertNoContent();

    [$deletedModel, $deletedCredential, $deletedAt] = dhsRow($platform);
    expect($deletedModel)->toBeNull()
        ->and($deletedCredential)->toBeNull()
        ->and($deletedAt)->not->toBeNull();

    foreach ([$otherPlatform, $ownedByOrg] as $untouched) {
        expect(dhsRow($untouched))->toBe([$model->id, $credential->id, null]);
    }
});

/**
 * The hook must unbind the deleted row whatever tenant context the delete runs in (hr-avatar-adapter A13, R3-001):
 * a keyed update that kept the tenant scope silently matched zero rows when the context did not own the row, and
 * the ON DELETE RESTRICT credential foreign key (a 500 on credential removal) came back.
 */
test('soft-deleting a bound platform template while another organization context is bound unbinds it', function (): void {
    [$model, $credential] = dhsBinding();
    $other = Organization::factory()->create();
    $platform = AvatarTemplate::withoutGlobalScopes()->findOrFail(dhsBoundTemplate(null, 'Platform', $model, $credential));
    $untouched = dhsBoundTemplate($other->id, 'Other org', $model, $credential);
    $superadmin = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    TenantContextScope::runFor($other->id, fn () => app(PlatformTemplateContext::class)->run($superadmin, fn () => $platform->delete()));

    [$deletedModel, $deletedCredential, $deletedAt] = dhsRow($platform->id);
    expect($deletedModel)->toBeNull()
        ->and($deletedCredential)->toBeNull()
        ->and($deletedAt)->not->toBeNull()
        ->and(dhsRow($untouched))->toBe([$model->id, $credential->id, null]);
});

test('soft-deleting a bound platform template with no tenant context bound unbinds it', function (): void {
    [$model, $credential] = dhsBinding();
    $org = Organization::factory()->create();
    $platform = AvatarTemplate::withoutGlobalScopes()->findOrFail(dhsBoundTemplate(null, 'Platform', $model, $credential));
    $untouched = dhsBoundTemplate($org->id, 'Org', $model, $credential);
    $superadmin = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    app(PlatformTemplateContext::class)->run($superadmin, fn () => $platform->delete());

    [$deletedModel, $deletedCredential, $deletedAt] = dhsRow($platform->id);
    expect($deletedModel)->toBeNull()
        ->and($deletedCredential)->toBeNull()
        ->and($deletedAt)->not->toBeNull()
        ->and(dhsRow($untouched))->toBe([$model->id, $credential->id, null]);
});

test('soft-deleting a bound organization template while another organization context is bound unbinds it', function (): void {
    [$model, $credential] = dhsBinding();
    $owner = Organization::factory()->create();
    $other = Organization::factory()->create();
    $template = AvatarTemplate::withoutGlobalScopes()->findOrFail(dhsBoundTemplate($owner->id, 'Owner', $model, $credential));
    $untouched = dhsBoundTemplate($other->id, 'Other org', $model, $credential);

    TenantContextScope::runFor($other->id, fn () => $template->delete());

    [$deletedModel, $deletedCredential, $deletedAt] = dhsRow($template->id);
    expect($deletedModel)->toBeNull()
        ->and($deletedCredential)->toBeNull()
        ->and($deletedAt)->not->toBeNull()
        ->and(dhsRow($untouched))->toBe([$model->id, $credential->id, null]);
});

test('soft-deleting a bound organization template with no tenant context bound unbinds it', function (): void {
    [$model, $credential] = dhsBinding();
    $owner = Organization::factory()->create();
    $template = AvatarTemplate::withoutGlobalScopes()->findOrFail(dhsBoundTemplate($owner->id, 'Owner', $model, $credential));

    $template->delete();

    [$deletedModel, $deletedCredential, $deletedAt] = dhsRow($template->id);
    expect($deletedModel)->toBeNull()
        ->and($deletedCredential)->toBeNull()
        ->and($deletedAt)->not->toBeNull();
});
