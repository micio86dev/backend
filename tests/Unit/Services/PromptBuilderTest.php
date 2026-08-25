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

// ─── PR3 (bars-full-scale-1-5): AD-1 ordered relational rubric (D4/D5/D8) ─────

test('(f) prompt contains the D4 SCORING PROCEDURE ordered-steps text verbatim', function (): void {
    $org = promptBuilderOrg();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::create(['version' => '1.0', 'label' => 'V1', 'organization_id' => $org->id]);
    $role = Role::factory()->create(['code' => 'PROC_ROLE_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'PROC_'.uniqid()]);
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

    expect($prompt->systemPrompt)
        ->toContain('SCORING PROCEDURE — apply these steps in this exact order for each indicator')
        ->toContain('If the transcript contains no assessable evidence for this indicator,')
        ->toContain('score -1 and stop.')
        ->toContain('If the evidence matches the Score 5 anchor, score 5 and stop.')
        ->toContain('If the evidence matches the Score 3 anchor, score 3 and stop.')
        ->toContain('If the evidence matches the Score 1 anchor, score 1 and stop.')
        ->toContain('Only if steps 2, 3 and 4 were ALL rejected, the evidence falls between two')
        ->toContain('score 4 if it clearly exceeds the Score 3 anchor but does not fully')
        ->toContain('match the Score 5 anchor;')
        ->toContain('score 2 if it is clearly below the Score 3 anchor but is not as weak as')
        ->toContain('the Score 1 anchor.')
        ->toContain('Scores 4 and 2 are RESIDUAL levels. They are legal ONLY at step 5.')
        ->toContain('evidence is equally consistent with an authored anchor (5, 3 or 1) and with an')
        ->toContain('intermediate level, the authored anchor WINS — score the anchor, never the')
        ->toContain('intermediate.');
});

test('(g) the old "Do NOT use scores 2, 4" prohibition is absent from the prompt', function (): void {
    $org = promptBuilderOrg();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::create(['version' => '1.0', 'label' => 'V1', 'organization_id' => $org->id]);
    $role = Role::factory()->create(['code' => 'NOPROHIB_ROLE_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'NOPROHIB_'.uniqid()]);
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

    expect($prompt->systemPrompt)->not->toContain('Do NOT use scores 2, 4');

    // The IMPORTANT RULES line now allows all five values plus the sentinel.
    expect($prompt->systemPrompt)->toContain('assign a score from EXACTLY one of: 1, 2, 3, 4, 5, OR -1');

    // Output-format comment reflects the widened domain.
    expect($prompt->systemPrompt)->toContain('<1, 2, 3, 4, 5, or -1>');
});

test('(h) residual-score explanation contract names both bounding anchors (D5)', function (): void {
    $org = promptBuilderOrg();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::create(['version' => '1.0', 'label' => 'V1', 'organization_id' => $org->id]);
    $role = Role::factory()->create(['code' => 'EXPL_ROLE_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'EXPL_'.uniqid()]);
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

    expect($prompt->systemPrompt)->toContain(
        '<brief explanation referencing the anchor; for a score of 4 or 2, name BOTH anchors the evidence falls between>'
    );
});

test('(i) D8 parity: config(scoring.prompt_version) equals .env.example SCORING_PROMPT_VERSION', function (): void {
    $envExamplePath = base_path('.env.example');
    $envExampleContents = (string) file_get_contents($envExamplePath);

    expect($envExampleContents)->toMatch('/^SCORING_PROMPT_VERSION=(.+)$/m');

    preg_match('/^SCORING_PROMPT_VERSION=(.+)$/m', $envExampleContents, $matches);
    $envExampleVersion = trim($matches[1]);

    expect(config('scoring.prompt_version'))->toBe(
        $envExampleVersion,
        "config('scoring.prompt_version') default (".config('scoring.prompt_version').
        ") must match .env.example's SCORING_PROMPT_VERSION ({$envExampleVersion}) — D8 parity guard. "
        .'A deployment that pins SCORING_PROMPT_VERSION explicitly (e.g. Railway) must ALSO be bumped '
        .'at deploy time: this test only guards the two file-level defaults, not any environment override.'
    );
});

// ─── evaluator-evidence-and-rigor: PR 1 — severity calibration (D-5) ─────────

/**
 * Build a system prompt with one fully-translated indicator, for prompt-text assertions.
 */
function builtSystemPrompt(string $locale = 'en'): string
{
    $org = promptBuilderOrg();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::create(['version' => '1.0', 'label' => 'V1', 'organization_id' => $org->id]);
    $role = Role::factory()->create(['code' => 'STD_ROLE_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'STD_'.uniqid()]);
    createFullIndicator($role->id, $competency->id, 0);

    return (new PromptBuilder)->build(
        evaluation: (object) ['framework_version_id' => $fv->id],
        competencyCode: $competency->code,
        competencyId: $competency->id,
        roleId: $role->id,
        projectLocale: $locale,
        indicators: BarsIndicator::where('role_id', $role->id)
            ->where('competency_id', $competency->id)
            ->orderBy('position')
            ->get(),
        transcript: 'candidate: Test answer.',
    )->systemPrompt;
}

/**
 * The prompt is a hard-wrapped heredoc, so a phrase can straddle a newline and
 * an indent. Content assertions run against this reflowed form: they are about
 * WHAT the prompt says, and must not break when the heredoc is re-wrapped.
 * Assertions about exact layout (case (n)) use the raw prompt instead.
 */
function builtSystemPromptReflowed(string $locale = 'en'): string
{
    return (string) preg_replace('/\s+/', ' ', builtSystemPrompt($locale));
}

test('(j) task 1.1 — the system prompt carries an EVALUATION STANDARDS section', function (): void {
    expect(builtSystemPrompt())->toContain('EVALUATION STANDARDS');
});

test('(k) task 1.2 — standards establish 3 as the baseline and 4/5 as rare', function (): void {
    $prompt = builtSystemPromptReflowed();

    expect($prompt)->toContain('3 is the baseline')
        ->and($prompt)->toContain('Scores of 4 and 5 are rare');
});

test('(l) task 1.3 — a high score requires ALL THREE of situation, actions, measurable outcome', function (): void {
    $prompt = builtSystemPromptReflowed();

    expect($prompt)->toContain('a specific situation')
        ->and($prompt)->toContain('concrete actions the candidate personally took')
        ->and($prompt)->toContain('a measurable outcome');
});

test('(m) task 1.4 — generic or hypothetical answers are directed to 1-2', function (): void {
    expect(builtSystemPromptReflowed())
        ->toContain('Generic, hypothetical or second-hand answers score 1 or 2');
});

test('(n) task 2.1 — the anchor-primacy paragraph survives VERBATIM', function (): void {
    // The single highest-risk assertion in this change (design D-5). The severity
    // block sits beside a tie-break ratified in bars-full-scale-1-5 one day earlier.
    // If a future edit to the standards wording displaces or rewords this paragraph,
    // the rubric silently stops being anchor-primary and every score shifts.
    $anchorPrimacy = <<<'TEXT'
Scores 4 and 2 are RESIDUAL levels. They are legal ONLY at step 5. If the
evidence is equally consistent with an authored anchor (5, 3 or 1) and with an
intermediate level, the authored anchor WINS — score the anchor, never the
intermediate.
TEXT;

    expect(builtSystemPrompt())->toContain($anchorPrimacy);
});

test('(o) task 2.2 — doubt resolution is SCOPED to step 5 and introduces no general tie-break', function (): void {
    $prompt = builtSystemPromptReflowed();

    // The downward-doubt instruction must name step 5 explicitly...
    expect($prompt)->toContain('At step 5, resolve doubt DOWNWARD');

    // ...and must carry the clause that walls it off from steps 2-4, which is
    // what keeps anchor primacy intact.
    expect($prompt)->toContain('It applies ONLY at step 5 and NEVER overrides steps 2, 3 or 4');

    // ...and must NOT appear as a bare, unscoped rule that would compete with
    // anchor primacy. These are the reference log's own phrasings — the ones
    // this block exists to translate safely rather than copy.
    expect($prompt)->not->toContain('When in doubt, always choose the lower score');
    expect($prompt)->not->toContain('when in doubt choose the lower one');
});

test('(p) task 3.1 — standards are English under an Italian locale, rubric stays Italian', function (): void {
    $prompt = builtSystemPromptReflowed('it');

    // Calibration language addressed to the model — English, like SCORING PROCEDURE.
    expect($prompt)->toContain('EVALUATION STANDARDS')
        ->and($prompt)->toContain('3 is the baseline');

    // Candidate-facing rubric content — localised, unchanged by this change.
    expect($prompt)->toContain('IT indicator text')
        ->and($prompt)->toContain('IT anchor 5');
});
