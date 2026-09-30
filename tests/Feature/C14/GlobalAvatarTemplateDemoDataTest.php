<?php

declare(strict_types=1);

/**
 * Demo data and platform templates never touch each other (global-avatar-
 * templates A4, characterization).
 *
 * `beai:demo-seed` / `beai:demo-teardown` work inside ONE organization: a
 * platform template is invisible to them by construction (the strict tenant
 * scope never matches a NULL organization), and these tests pin that so a
 * later "helpful" switch to the read scope that includes platform rows fails
 * loudly. A teardown that matched a platform template would be refused by the
 * write guard mid-run; a seed that saw one as "already active" would silently
 * create the organization's own demo template retired.
 */

use App\Actions\AvatarTemplates\DuplicateAvatarTemplate;
use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\Demo\DemoMarker;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\FrameworkCatalogSeeder;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

function gddDemoTemplates(Organization $org): array
{
    return TenantContextScope::runFor($org->id, fn () => AvatarTemplate::where('name', 'like', DemoMarker::PREFIX.'%')->orderBy('id')->get()->all());
}

test('seeding creates the organization demo template ACTIVE even while a platform template is offered', function (): void {
    $global = PlatformTemplates::insertActiveGlobal(['name' => 'Offered platform template']);

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $byName = collect(gddDemoTemplates($this->org))->keyBy('name');

    expect($byName[DemoMarker::PREFIX.'heygen-it']->is_active)->toBeTrue()
        ->and($byName[DemoMarker::PREFIX.'tavus-en']->is_active)->toBeFalse()
        ->and(AvatarTemplate::platformOnly()->findOrFail($global->id)->is_active)->toBeTrue();
});

test('teardown removes only the organization demo templates and never a platform template carrying the demo prefix', function (): void {
    $global = PlatformTemplates::insertActiveGlobal(['name' => DemoMarker::PREFIX.'platform']);

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);
    expect(gddDemoTemplates($this->org))->toHaveCount(2);

    $this->artisan('beai:demo-teardown', ['--org' => 'acme'])->assertExitCode(0);

    $survivor = AvatarTemplate::platformOnly()->find($global->id);

    expect(gddDemoTemplates($this->org))->toBe([])
        ->and($survivor)->not->toBeNull()
        ->and($survivor->is_active)->toBeTrue();
});

test('a platform template with the demo prefix does not disturb the seed census', function (): void {
    PlatformTemplates::insertGlobal(['name' => DemoMarker::PREFIX.'platform']);

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    expect(gddDemoTemplates($this->org))->toHaveCount(2);
});

test('an organization duplicate name check ignores platform templates', function (): void {
    PlatformTemplates::insertGlobal(['name' => 'Reused name']);
    $source = TenantContextScope::runFor($this->org->id, fn () => AvatarTemplate::create([
        'name' => 'Reused name', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
    ]));
    $target = Organization::factory()->create();

    $created = app(DuplicateAvatarTemplate::class)->run($source, [$target->id]);

    // The platform "Reused name" would have forced a "(copy)" suffix had it counted.
    expect($created[0]['name'])->toBe('Reused name');
});
