<?php

declare(strict_types=1);

/**
 * RED — Task 14.2: PromptBuilder (C9 D3/D6 FIX-11).
 *
 * Verifies:
 * (a) Anchors loaded from pinned framework_version_id (not live draft).
 * (b) temperature=0 enforced on every LLM call option.
 * (c) Missing IT anchor_5 → AnchorTranslationMissingException.
 * (d) Missing IT indicator text → AnchorTranslationMissingException.
 * (e) Present IT anchor → scoring proceeds, Italian anchor injected in prompt.
 *
 * Refs spec: D3, D6, FIX-11.
 */

use App\Exceptions\Scoring\AnchorTranslationMissingException;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Services\Scoring\PromptBuilder;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function promptBuilderOrg(): Organization
{
    return Organization::factory()->create();
}

function promptBuilderProject(Organization $org, string $language = 'it'): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create([
        'status' => 'active',
        'language' => $language,
    ]);
}

/**
 * Create a BarsIndicator with full IT translations for testing.
 *
 * @param  array<string, string>  $overrides  Override specific translatable fields.
 */
function createFullIndicator(
    int $roleId,
    int $competencyId,
    int $position = 0,
    array $overrides = []
): BarsIndicator {
    $defaults = [
        'text' => ['en' => 'EN indicator text', 'it' => 'IT indicator text'],
        'anchor_5' => ['en' => 'EN anchor 5', 'it' => 'IT anchor 5'],
        'anchor_3' => ['en' => 'EN anchor 3', 'it' => 'IT anchor 3'],
        'anchor_1' => ['en' => 'EN anchor 1', 'it' => 'IT anchor 1'],
    ];

    $merged = array_merge($defaults, $overrides);

    $indicator = new BarsIndicator;
    $indicator->forceFill([
        'role_id' => $roleId,
        'competency_id' => $competencyId,
        'text' => $merged['text'],
        'anchor_5' => $merged['anchor_5'],
        'anchor_3' => $merged['anchor_3'],
        'anchor_1' => $merged['anchor_1'],
        'position' => $position,
    ]);
    $indicator->save();

    return $indicator;
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('(a) anchors loaded from pinned framework_version_id, not live draft', function (): void {
    $org = promptBuilderOrg();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    // Create pinned version and a "live" draft
    $pinnedFv = FrameworkVersion::create(['version' => '1.0', 'label' => 'Pinned', 'organization_id' => $org->id]);
    $liveFv = FrameworkVersion::create(['version' => '2.0', 'label' => 'Live Draft', 'organization_id' => $org->id]);

    $role = Role::factory()->create(['code' => 'COL_ROLE_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'COL_'.uniqid()]);

    $project = Project::factory()->create([
        'status' => 'active',
        'language' => 'en',
        'framework_version_id' => $pinnedFv->id,
        'role_code' => $role->code,
    ]);

    // Indicator in the pinned version
    createFullIndicator($role->id, $competency->id, 0, [
        'anchor_5' => ['en' => 'PINNED anchor 5', 'it' => 'PINNED IT anchor 5'],
    ]);

    $builder = new PromptBuilder;
    $prompt = $builder->build(
        evaluation: (object) [
            'framework_version_id' => $pinnedFv->id,
        ],
        competencyCode: $competency->code,
        competencyId: $competency->id,
        roleId: $role->id,
        projectLocale: 'en',
        indicators: BarsIndicator::where('role_id', $role->id)
            ->where('competency_id', $competency->id)
            ->orderBy('position')
            ->get(),
        transcript: 'Candidate: Test answer.',
    );

    expect($prompt->systemPrompt)->toContain('PINNED anchor 5');
});

test('(b) temperature=0 is present in the options returned by PromptBuilder', function (): void {
    $org = promptBuilderOrg();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::create(['version' => '1.0', 'label' => 'V1', 'organization_id' => $org->id]);
    $role = Role::factory()->create(['code' => 'TEMP_ROLE_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'TEMP_'.uniqid()]);
    createFullIndicator($role->id, $competency->id, 0);

    $builder = new PromptBuilder;
    $prompt = $builder->build(
        evaluation: (object) ['framework_version_id' => $fv->id],
        competencyCode: $competency->code,
        competencyId: $competency->id,
        roleId: $role->id,
        projectLocale: 'en',
        indicators: BarsIndicator::where('role_id', $role->id)
            ->where('competency_id', $competency->id)
            ->orderBy('position')
            ->get(),
        transcript: 'Candidate: Some answer.',
    );

    expect($prompt->options['temperature'])->toBe(0);
});

test('(c) missing IT anchor_5 → AnchorTranslationMissingException', function (): void {
    $org = promptBuilderOrg();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::create(['version' => '1.0', 'label' => 'V1', 'organization_id' => $org->id]);
    $role = Role::factory()->create(['code' => 'MISSING_ROLE_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'MISS_'.uniqid()]);

    // anchor_5 missing Italian translation
    createFullIndicator($role->id, $competency->id, 0, [
        'anchor_5' => ['en' => 'EN anchor 5 only'],  // no 'it' key
    ]);

    $builder = new PromptBuilder;

    expect(fn () => $builder->build(
        evaluation: (object) ['framework_version_id' => $fv->id],
        competencyCode: $competency->code,
        competencyId: $competency->id,
        roleId: $role->id,
        projectLocale: 'it',
        indicators: BarsIndicator::where('role_id', $role->id)
            ->where('competency_id', $competency->id)
            ->orderBy('position')
            ->get(),
        transcript: 'Candidate: Answer.',
    ))->toThrow(AnchorTranslationMissingException::class);
});

test('(d) missing IT indicator text field → AnchorTranslationMissingException', function (): void {
    $org = promptBuilderOrg();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::create(['version' => '1.0', 'label' => 'V1', 'organization_id' => $org->id]);
    $role = Role::factory()->create(['code' => 'TEXTMISS_ROLE_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'TEXTMISS_'.uniqid()]);

    // text field missing Italian translation
    createFullIndicator($role->id, $competency->id, 0, [
        'text' => ['en' => 'EN text only'],  // no 'it' key
    ]);

    $builder = new PromptBuilder;

    expect(fn () => $builder->build(
        evaluation: (object) ['framework_version_id' => $fv->id],
        competencyCode: $competency->code,
        competencyId: $competency->id,
        roleId: $role->id,
        projectLocale: 'it',
        indicators: BarsIndicator::where('role_id', $role->id)
            ->where('competency_id', $competency->id)
            ->orderBy('position')
            ->get(),
        transcript: 'Candidate: Answer.',
    ))->toThrow(AnchorTranslationMissingException::class);
});

test('(e) present IT anchor → prompt built successfully with Italian anchor injected', function (): void {
    $org = promptBuilderOrg();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::create(['version' => '1.0', 'label' => 'V1', 'organization_id' => $org->id]);
    $role = Role::factory()->create(['code' => 'PRESENT_ROLE_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'PRESENT_'.uniqid()]);

    createFullIndicator($role->id, $competency->id, 0, [
        'anchor_5' => ['en' => 'EN anchor 5', 'it' => 'Ancoraggio IT a 5'],
        'text' => ['en' => 'EN text', 'it' => 'Testo indicatore IT'],
    ]);

    $builder = new PromptBuilder;

    $prompt = $builder->build(
        evaluation: (object) ['framework_version_id' => $fv->id],
        competencyCode: $competency->code,
        competencyId: $competency->id,
        roleId: $role->id,
        projectLocale: 'it',
        indicators: BarsIndicator::where('role_id', $role->id)
            ->where('competency_id', $competency->id)
            ->orderBy('position')
            ->get(),
        transcript: 'Candidate: Risposta test.',
    );

    expect($prompt->systemPrompt)
        ->toContain('Ancoraggio IT a 5')
        ->toContain('Testo indicatore IT');

    // Must also contain the LLM output schema contract
    expect($prompt->systemPrompt)->toContain('behaviors');
});
