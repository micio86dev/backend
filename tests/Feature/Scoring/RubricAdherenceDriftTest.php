<?php

declare(strict_types=1);

/**
 * @ai group — real-LLM rubric-adherence / drift detection (bars-full-scale-1-5, Phase 4).
 *
 * D9's honest premise: golden cassettes replay a recorded response — the model is NOT in
 * the loop, so a cassette can never detect that a new model version draws the
 * exceeds/meets line differently. This test is the ONLY mechanism in the suite that can,
 * because it is the only one that calls a live LLM.
 *
 * It asserts BANDS, never exact values, against a live model:
 *   (a) every returned score is a member of the legal domain {1, 2, 3, 4, 5, -1};
 *   (b) at least one RESIDUAL score (2 or 4) is emitted for the deliberately mid-band
 *       indicator — i.e. the model actually uses the widened domain when the evidence
 *       calls for it, rather than always snapping to an authored anchor;
 *   (c) no score is out of domain (0, 6, or any other value the parser/validator would
 *       otherwise silently coerce or crash on).
 *
 * An exact-value assertion against a live model would be a flaky test, not a drift
 * detector (design.md D9). This test runs ONLY in the `ai-integration` GitHub Actions
 * workflow (workflow_dispatch / release/*), which sets ANTHROPIC_API_KEY as a secret.
 * It is skipped in every other run — including this apply session, which has no
 * ANTHROPIC_API_KEY in its environment — via the same `->group('ai')->skip(...)` pattern
 * already established in AnthropicLLMProviderTest.php. Zero AI spend on the delivery path.
 *
 * REQ: Drift detection (bars-full-scale-1-5 design.md D9, Phase 4 task 4.1)
 *
 * Placement note (discovered while wiring this test): `tests/Integration/` is NOT
 * registered in `phpunit.xml` (only `tests/Unit`, `tests/Feature`, `tests/Arch` are),
 * so `AvatarBehavioralComplianceTest.php`'s `tests/Integration/Conversation/` location
 * and its file-level `@group ai` docblock comment are never actually discovered by a
 * bare `--group ai` run — that file's tests always markTestSkipped() anyway, so the
 * gap is silent. Confirmed via `./vendor/bin/pest --group ai --list-tests`, which
 * showed only AnthropicLLMProviderTest's fluent-`->group('ai')`-tagged test. This test
 * therefore lives under `tests/Feature/Scoring/` (a registered suite) and is tagged via
 * the fluent `->group('ai')` call, not a docblock — the only way `php artisan test
 * --group ai` (what ai-integration.yml actually runs) will pick it up.
 */

use App\DTOs\Scoring\IndicatorRef;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Role;
use App\Services\LLM\AnthropicLLMProvider;
use App\Services\Scoring\EvaluationParser;
use App\Services\Scoring\IndicatorValidator;
use App\Services\Scoring\PromptBuilder;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('live LLM emits only domain-legal scores and uses at least one residual level on a mid-band answer', function (): void {
    $realKey = (string) getenv('ANTHROPIC_API_KEY');
    config(['scoring.anthropic.api_key' => $realKey]);

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::create(['version' => '1.0', 'label' => 'RubricDrift', 'organization_id' => $org->id]);
    $role = Role::factory()->create(['code' => 'DRIFT_ROLE_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'DRIFT_'.uniqid()]);

    // Three indicators: one clearly strong (expect 5), one clearly weak (expect 1),
    // and one deliberately mid-band answer that exceeds the Score 3 anchor but does
    // not fully match the Score 5 anchor — the case the widened domain exists for.
    //
    // THE THIRD ANSWER IS THE WHOLE TEST, AND IT USED TO FAIL TO BE MID-BAND.
    // As originally written it satisfied every clause of its own Score 5 anchor —
    // specific, actionable, constructively framed, AND "I checked back with them a
    // few days later". `SCORING_PROCEDURE` step 2 therefore matched and stopped, so
    // the fixture asked for a residual from a transcript that could not produce one.
    // Worse, the ambiguous reading could not save it either: the procedure's
    // anchor-primacy tie-break ("if the evidence is equally consistent with an
    // authored anchor and with an intermediate level, the authored anchor WINS")
    // actively steers away from a residual whenever an anchor fits.
    //
    // The answer now states the ABSENCE explicitly rather than merely omitting the
    // follow-up. Omission would read as "not assessable" and invite -1; a stated
    // "I never followed up" makes step 2 fail on a named clause while steps 3 and 4
    // still fail too (it is specific and actionable, so it clearly exceeds the
    // generic-feedback Score 3 anchor, and it is not a refusal to give feedback).
    // Only step 5 remains, which is what a score of 4 means.
    $indicatorSpecs = [
        [
            'text' => 'Respond to customer complaints professionally',
            'anchor_5' => 'Stays calm and empathetic throughout, fully resolves the complaint, and follows up to confirm satisfaction.',
            'anchor_3' => 'Stays professional and resolves the complaint, without a follow-up.',
            'anchor_1' => 'Responds curtly or dismissively and does not resolve the complaint.',
        ],
        [
            'text' => 'Prioritize competing tasks under time pressure',
            'anchor_5' => 'Clearly explains the prioritization logic, reorders remaining work, and communicates the plan to stakeholders unprompted.',
            'anchor_3' => 'Picks a reasonable priority order when asked, without explaining the logic.',
            'anchor_1' => 'Cannot articulate any priority order and treats every task as equally urgent.',
        ],
        [
            'text' => 'Give constructive feedback to a peer',
            'anchor_5' => 'Gives specific, actionable feedback, frames it constructively, and checks the peer understood.',
            'anchor_3' => 'Gives generic feedback ("communicate more") without a specific example or action.',
            'anchor_1' => 'Avoids giving any feedback when directly asked.',
        ],
    ];

    foreach ($indicatorSpecs as $i => $spec) {
        $ind = new BarsIndicator;
        $ind->forceFill([
            'role_id' => $role->id,
            'competency_id' => $competency->id,
            'text' => ['en' => $spec['text']],
            'anchor_5' => ['en' => $spec['anchor_5']],
            'anchor_3' => ['en' => $spec['anchor_3']],
            'anchor_1' => ['en' => $spec['anchor_1']],
            'position' => $i,
        ]);
        $ind->save();
    }

    $indicators = BarsIndicator::where('role_id', $role->id)
        ->where('competency_id', $competency->id)
        ->orderBy('position')
        ->get();

    $transcript = <<<'TRANSCRIPT'
Interviewer: Tell me about a time you handled an upset customer.
Candidate: A customer called in furious about a billing error. I stayed calm, listened
to the whole complaint without interrupting, apologized, fixed the charge on the call,
and then emailed them the next day to confirm everything looked right and ask if there
was anything else I could help with.

Interviewer: Tell me about a time you had to juggle several urgent tasks at once.
Candidate: Honestly I just started with whatever came in first and worked down the list.
I didn't really think about which one mattered most, I just tried to get through
everything as fast as possible.

Interviewer: Tell me about a time you gave a colleague feedback on their work.
Candidate: I told them the report was missing the regional breakdown the client always
asks for, and suggested they add a one-page summary table at the front next time so it's
easier to find. I never followed up though, so I don't know whether they took it on board
or even understood what I meant.
TRANSCRIPT;

    $builder = new PromptBuilder;
    $promptPayload = $builder->build(
        evaluation: (object) ['framework_version_id' => $fv->id],
        competencyCode: $competency->code,
        competencyId: $competency->id,
        roleId: $role->id,
        projectLocale: 'en',
        indicators: $indicators,
        transcript: $transcript,
    );

    $fullPrompt = $promptPayload->systemPrompt."\n\n".$promptPayload->userMessage;

    $response = (new AnthropicLLMProvider)->complete($fullPrompt, [
        'temperature' => 0,
        'model' => config('scoring.model_version', 'claude-haiku-4-5-20251001'),
    ]);

    $indicatorRefs = $indicators->values()->map(
        static fn (BarsIndicator $indicator, int $i): IndicatorRef => new IndicatorRef(
            position: $i,
            text: $indicator->getTranslation('text', 'en'),
        )
    )->all();

    $dtos = (new EvaluationParser)->parse($response->content, $indicatorRefs);

    $validator = new IndicatorValidator;
    $scores = [];

    foreach ($dtos as $dto) {
        // (a)/(c): domain membership — a validator throw here IS the drift signal.
        $validator->validate($dto);
        $scores[] = $dto->score;
    }

    // (a) every returned score is a member of the legal domain.
    foreach ($scores as $score) {
        expect($score)->toBeIn([1, 2, 3, 4, 5, -1],
            "Live LLM emitted score {$score}, outside the legal domain {1,2,3,4,5,-1}."
        );
    }

    // (b) at least one RESIDUAL level (2 or 4) is emitted — the widened domain is
    // actually reachable, not merely declared legal and never used.
    $residualScores = array_values(array_filter($scores, static fn (int $s): bool => in_array($s, [2, 4], true)));

    // The message is deliberately SHORT and leads with the data. Pest truncates a
    // long custom failure message in the middle ("Expected at least one residua…
    // which."), which is exactly what happened on the first real run of this gate:
    // the scores the message existed to report never reached the CI log, and the
    // diagnosis had to be reconstructed by reading the fixture instead. Guidance
    // for whoever is reading the failure belongs in the comment below, where it
    // cannot be truncated; the assertion itself carries only the evidence.
    //
    // If this fails, the two candidate causes are: the model has drifted toward
    // always snapping to an authored anchor (the real signal this test exists to
    // catch), or the third answer has stopped being mid-band against its anchors.
    // Check the fixture against SCORING_PROCEDURE before touching the assertion —
    // and do not loosen it without establishing which of the two it is.
    expect($residualScores)->not->toBeEmpty(
        'No residual (2 or 4). Scores: ['.implode(', ', $scores).']'
    );
})->group('ai')->skip(
    fn (): bool => empty(getenv('ANTHROPIC_API_KEY')),
    'ANTHROPIC_API_KEY not set — skipping real API rubric-adherence/drift test.',
);
