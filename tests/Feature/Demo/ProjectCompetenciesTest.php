<?php

declare(strict_types=1);

/**
 * `beai:demo-seed` — FrameworkVersion, AvatarTemplate and Project +
 * `project_competencies` writers (design D8, volume table; spec: "Framework
 * Version...Not Locked" via D8 override, "project_competencies Is Populated
 * for Every Seeded Project", "Exactly One Active, Valid Avatar Template").
 *
 * `project_competencies` is legacy Defect A: without it,
 * `AdminEvaluationSerializer`/`EvaluationPayloadAssembler`/
 * `ProgressPayloadAssembler` cannot resolve competency order and count, and
 * the evaluation report renders empty. This is the regression guard.
 */

use App\Models\AvatarTemplate;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Support\AvatarTemplates\ConfigValidator;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
use App\Support\Demo\DemoMarker;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

test('project_competencies is populated with contiguous 0-based positions matching the fixture order, for all 4 projects', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $expected = [
        DemoMarker::PREFIX.'sales-ico' => ['PRS', 'STG', 'DRV', 'COM', 'COL'],
        DemoMarker::PREFIX.'team-lead-fll' => ['STG', 'INN', 'CSF', 'OPX', 'INS'],
        DemoMarker::PREFIX.'mid-leader-mll' => ['PRS', 'STG', 'DRV'],
        DemoMarker::PREFIX.'closed-campaign' => ['PRS', 'STG', 'DRV', 'COM', 'COL'],
    ];

    TenantContextScope::runFor($this->org->id, function () use ($expected): void {
        foreach ($expected as $slug => $codes) {
            $project = Project::withTrashed()->where('slug', $slug)->firstOrFail();
            $pivot = $project->competencies()->get();

            expect($pivot->pluck('code')->all())->toBe($codes);
            expect($pivot->pluck('pivot.position')->all())->toBe(range(0, count($codes) - 1));
        }
    });
});

test('beai-demo-1.0.0 is created unlocked, and the deployment-wide lock state is printed', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])
        ->expectsOutputToContain('lock')
        ->assertExitCode(0);

    $version = TenantContextScope::runFor(
        $this->org->id,
        fn () => FrameworkVersion::where('version', DemoMarker::PREFIX.'1.0.0')->first(),
    );

    expect($version)->not->toBeNull();
    expect($version->is_locked)->toBeFalse();
});

test('exactly one avatar template is active, its config passes ConfigValidator, and duration caps are respected', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $templates = TenantContextScope::runFor(
        $this->org->id,
        fn () => AvatarTemplate::where('name', 'like', DemoMarker::PREFIX.'%')->get(),
    );

    expect($templates)->toHaveCount(2);
    expect($templates->where('is_active', true))->toHaveCount(1);

    foreach ($templates as $template) {
        expect(ConfigValidator::validate($template->provider, $template->config))->toBe([]);
    }

    $heygen = $templates->firstWhere('provider', 'heygen');
    $tavus = $templates->firstWhere('provider', 'tavus');

    expect($heygen->config['maxSessionDurationSec'])->toBeLessThanOrEqual(ProviderFieldSpecs::HEYGEN_MAX_SECONDS);
    expect($tavus->config['maxCallDurationSec'])->toBeLessThanOrEqual(ProviderFieldSpecs::TAVUS_MAX_SECONDS);
    expect($heygen->is_active)->toBeTrue();
    expect($tavus->is_active)->toBeFalse();
});

test('a pre-existing active template causes the demo HeyGen template to be created inactive, and the collision is reported', function (): void {
    TenantContextScope::runFor($this->org->id, function (): void {
        AvatarTemplate::create([
            'name' => 'Operator template',
            'provider' => 'heygen',
            'config' => ['avatarId' => 'op_1', 'voiceId' => 'op_voice'],
            'is_active' => true,
        ]);
    });

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])
        ->expectsOutputToContain('active')
        ->assertExitCode(0);

    $demoHeygen = TenantContextScope::runFor(
        $this->org->id,
        fn () => AvatarTemplate::where('name', 'like', DemoMarker::PREFIX.'%')->where('provider', 'heygen')->first(),
    );

    expect($demoHeygen)->not->toBeNull();
    expect($demoHeygen->is_active)->toBeFalse();
});
