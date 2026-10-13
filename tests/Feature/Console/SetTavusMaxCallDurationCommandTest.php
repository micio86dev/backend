<?php

declare(strict_types=1);

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $config
 */
function tavusTemplateFor(Organization $org, string $provider = 'tavus', array $config = []): AvatarTemplate
{
    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Demo template '.uniqid(),
        'provider' => $provider,
        'config' => $config + ['maxCallDurationSec' => 600, 'replicaId' => 'r-1'],
    ]));
}

function storedMaxCallDuration(AvatarTemplate $template): mixed
{
    return AvatarTemplate::withoutGlobalScopes()->findOrFail($template->id)->config['maxCallDurationSec'] ?? null;
}

test('sets maxCallDurationSec on a tavus template and keeps the other config keys', function (): void {
    $org = Organization::factory()->create();
    $template = tavusTemplateFor($org);

    Artisan::call('beai:set-tavus-max-call', ['template' => $template->id, 'seconds' => 1200]);

    expect(storedMaxCallDuration($template))->toBe(1200);
    $config = TenantContextScope::runFor($org->id, fn (): array => AvatarTemplate::query()->findOrFail($template->id)->config);
    expect($config)->toMatchArray(['replicaId' => 'r-1']);
});

test('dry run reports the change and saves nothing', function (): void {
    $org = Organization::factory()->create();
    $template = tavusTemplateFor($org);

    Artisan::call('beai:set-tavus-max-call', ['template' => $template->id, 'seconds' => 1200, '--dry-run' => true]);

    expect(storedMaxCallDuration($template))->toBe(600);
});

test('refuses a template whose provider is not tavus and changes nothing', function (): void {
    $org = Organization::factory()->create();
    $template = tavusTemplateFor($org, 'heygen');

    $exit = Artisan::call('beai:set-tavus-max-call', ['template' => $template->id, 'seconds' => 1200]);

    expect($exit)->toBe(1);
    expect(storedMaxCallDuration($template))->toBe(600);
});

test('refuses a value above the Tavus ceiling', function (): void {
    $org = Organization::factory()->create();
    $template = tavusTemplateFor($org);

    $exit = Artisan::call('beai:set-tavus-max-call', ['template' => $template->id, 'seconds' => ProviderFieldSpecs::TAVUS_MAX_SECONDS + 1]);

    expect($exit)->toBe(1);
    expect(storedMaxCallDuration($template))->toBe(600);
});

test('reports a missing template id without failing silently', function (): void {
    $exit = Artisan::call('beai:set-tavus-max-call', ['template' => 999999, 'seconds' => 1200]);

    expect($exit)->toBe(1);
});

test('refuses a value below the minimum and a non-numeric value, changing nothing', function (string $seconds): void {
    $org = Organization::factory()->create();
    $template = tavusTemplateFor($org);

    $exit = Artisan::call('beai:set-tavus-max-call', ['template' => $template->id, 'seconds' => $seconds]);

    expect($exit)->toBe(1);
    expect(storedMaxCallDuration($template))->toBe(600);
})->with(['29', 'abc']);

test('an unknown id and a platform id are both refused with exit 1, dry run or not, and change nothing', function (string $target, bool $dryRun): void {
    $platform = PlatformTemplates::insertGlobal(['provider' => 'tavus', 'config' => ['maxCallDurationSec' => 600]]);
    $unknownId = $platform->id + 1000;
    $id = $target === 'platform' ? $platform->id : $unknownId;

    $exit = Artisan::call('beai:set-tavus-max-call', [
        'template' => $id,
        'seconds' => 1200,
        '--dry-run' => $dryRun,
    ]);

    expect($exit)->toBe(1);
    // The lookup excludes platform rows (organization_id IS NULL), so a platform id is
    // answered exactly like an unknown id: there is no separate "platform template" refusal.
    expect(Artisan::output())->toContain('No avatar template with that id.');
    expect(storedMaxCallDuration($platform))->toBe(600);
})->with(['platform', 'unknown'])->with([true, false]);
