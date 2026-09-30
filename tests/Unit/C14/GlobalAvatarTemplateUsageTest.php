<?php

declare(strict_types=1);

/**
 * Cross-organization usage counts of platform avatar templates (A3).
 *
 * The count is a cross-tenant aggregate, so it must be ONE grouped query
 * whatever the number of organizations, count only live projects (the same
 * predicate as the model's `deleting` hook), and carry counts and nothing
 * else: no organization identity.
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Support\AvatarTemplates\GlobalAvatarTemplateUsage;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;

/** Pins `$count` new projects of `$org` to the template with the given id. */
function gauPin(Organization $org, int $templateId, int $count = 1): void
{
    TenantContextScope::runFor($org->id, function () use ($org, $templateId, $count): void {
        $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

        Project::factory()->count($count)->create([
            'organization_id' => $org->id,
            'framework_version_id' => $fv->id,
            'avatar_template_id' => $templateId,
        ]);
    });
}

test('counts live projects and distinct organizations per template', function (): void {
    $one = PlatformTemplates::insertGlobal();
    $three = PlatformTemplates::insertGlobal();
    $unused = PlatformTemplates::insertGlobal();

    gauPin($a = Organization::factory()->create(), $one->id);
    gauPin($a, $three->id, 2);
    gauPin(Organization::factory()->create(), $three->id, 2);
    gauPin(Organization::factory()->create(), $three->id);

    $usage = app(GlobalAvatarTemplateUsage::class)->for([$one->id, $three->id, $unused->id]);

    expect($usage[$one->id])->toBe(['organization_count' => 1, 'project_count' => 1])
        ->and($usage[$three->id])->toBe(['organization_count' => 3, 'project_count' => 5])
        ->and($usage[$unused->id])->toBe(['organization_count' => 0, 'project_count' => 0]);
});

test('a soft-deleted project is not counted', function (): void {
    $global = PlatformTemplates::insertGlobal();
    gauPin($org = Organization::factory()->create(), $global->id, 2);

    TenantContextScope::runFor($org->id, fn () => Project::query()->first()->delete());

    expect(app(GlobalAvatarTemplateUsage::class)->for([$global->id])[$global->id])
        ->toBe(['organization_count' => 1, 'project_count' => 1]);
});

test('the number of queries does not grow with the number of organizations', function (): void {
    $global = PlatformTemplates::insertGlobal();
    gauPin(Organization::factory()->create(), $global->id);

    $count = function () use ($global): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(GlobalAvatarTemplateUsage::class)->for([$global->id]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $withOne = $count();

    foreach (range(1, 12) as $ignored) {
        gauPin(Organization::factory()->create(), $global->id);
    }

    expect($count())->toBe($withOne)->and($withOne)->toBe(1)
        ->and(app(GlobalAvatarTemplateUsage::class)->for([$global->id])[$global->id]['organization_count'])->toBe(13);
});

test('the result carries only the two counts', function (): void {
    $global = PlatformTemplates::insertGlobal();
    gauPin(Organization::factory()->create(), $global->id);

    expect(array_keys(app(GlobalAvatarTemplateUsage::class)->for([$global->id])[$global->id]))
        ->toBe(['organization_count', 'project_count']);
});

test('asking for no templates issues no query', function (): void {
    DB::enableQueryLog();
    DB::flushQueryLog();

    expect(app(GlobalAvatarTemplateUsage::class)->for([]))->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});
