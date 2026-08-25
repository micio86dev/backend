<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTOs\Scoring\PromptPayload;
use App\Exceptions\Scoring\AnchorTranslationMissingException;
use App\Exceptions\Scoring\RoleNoBarsException;
use App\Models\BarsIndicator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the LLM prompt for a single competency scoring call.
 *
 * L-2 hard-fail (D6 FIX-11):
 *   Uses hasTranslation($field, $projectLocale) on ALL four translatable fields:
 *   {text, anchor_5, anchor_3, anchor_1}. A miss on ANY field → throws
 *   AnchorTranslationMissingException (never silent EN fallback).
 *   MUST NOT use hasTranslationGap() which is hardcoded to 'it'.
 *
 * LLM output schema requested (D3):
 *   { "behaviors": [{"indicator": string, "score": int, "explanation": string, "excerpts": [string]}] }
 *   No roll-up score or reliability requested from the LLM (recomputed server-side in D4).
 *
 * Indicator order (FIX-8):
 *   The prompt MUST explicitly instruct the LLM to return indicators in the EXACT
 *   SAME ORDER they were injected (ordered by position). The parser relies on array
 *   position for mapping; reordering causes silent misattribution.
 *
 * Options enforced:
 *   temperature=0 (determinism-critical), model_version from config.
 *
 * Scoring procedure (AD-1/D4, bars-full-scale-1-5):
 *   The domain widened from {1,3,5,-1} to {1,2,3,4,5,-1}. Scores 4 and 2 are
 *   RESIDUAL levels — legal only when the evidence falls between two authored
 *   anchors, never free LLM discretion. SCORING_PROCEDURE below encodes the
 *   anchor-primacy tie-break STRUCTURALLY: it is an ordered, early-stopping
 *   procedure, not a flat table, so a genuine tie between an authored anchor
 *   and a residual level is resolved before the residual step is ever reached.
 *   Any future edit to this rubric's wording MUST bump prompt_version (D8).
 *
 * Severity calibration (evaluator-evidence-and-rigor D-5):
 *   EVALUATION_STANDARDS is injected AFTER SCORING_PROCEDURE and establishes that
 *   3 is the baseline, 4/5 are rare, and generic answers score 1-2. Its
 *   doubt-resolution clause is SCOPED to step 5 and must stay that way — an
 *   unscoped downward rule would silently repeal the anchor-primacy tie-break
 *   above. Read that constant's docblock before touching its wording.
 *
 * REQ: PromptBuilder (C9 D3/D6 FIX-11, widened AD-1/D4/D5/D8, calibrated D-5)
 */
final class PromptBuilder
{
    /**
     * The AD-1 ordered relational rubric for residual score levels (D4).
     *
     * Injected verbatim between the IMPORTANT RULES block and the indicator
     * rubric. The early-stop control flow — not a sentence appended after a
     * flat table — is what makes the anchor-primacy tie-break structural: a
     * tie between an authored anchor and a residual level is resolved at
     * steps 2-4, before step 5 (the only place 4/2 may be assigned) is ever
     * reached.
     */
    private const SCORING_PROCEDURE = <<<'PROCEDURE'
SCORING PROCEDURE — apply these steps in this exact order for each indicator:
  1. If the transcript contains no assessable evidence for this indicator,
     score -1 and stop.
  2. If the evidence matches the Score 5 anchor, score 5 and stop.
  3. If the evidence matches the Score 3 anchor, score 3 and stop.
  4. If the evidence matches the Score 1 anchor, score 1 and stop.
  5. Only if steps 2, 3 and 4 were ALL rejected, the evidence falls between two
     anchors. Then, and only then:
       - score 4 if it clearly exceeds the Score 3 anchor but does not fully
         match the Score 5 anchor;
       - score 2 if it is clearly below the Score 3 anchor but is not as weak as
         the Score 1 anchor.

Scores 4 and 2 are RESIDUAL levels. They are legal ONLY at step 5. If the
evidence is equally consistent with an authored anchor (5, 3 or 1) and with an
intermediate level, the authored anchor WINS — score the anchor, never the
intermediate.
PROCEDURE;

    /**
     * Severity calibration (evaluator-evidence-and-rigor D-5).
     *
     * ⚠️ READ BEFORE EDITING THIS WORDING.
     *
     * This block is injected AFTER SCORING_PROCEDURE, and that placement is
     * load-bearing. Its doubt-resolution clause is deliberately SCOPED to
     * step 5 of that procedure. An unscoped "when in doubt choose the lower
     * one" — the phrasing the reference log uses, and the phrasing this block
     * exists to translate safely — would read as a general override and would
     * silently repeal the anchor-primacy tie-break ratified in
     * `bars-full-scale-1-5` (archive/2026-08-25). Anchor primacy says evidence
     * matching an authored anchor is scored AT that anchor, full stop; a
     * general downward rule would quietly start pulling 3s down to 2s.
     *
     * `PromptBuilderTest` case (o) guards the scoping and case (n) asserts the
     * anchor-primacy paragraph survives verbatim. If you reword anything here,
     * both must still pass, and `prompt_version` MUST be bumped (D8/D-6):
     * evaluations scored under different calibration are not comparable.
     */
    private const EVALUATION_STANDARDS = <<<'STANDARDS'
EVALUATION STANDARDS — calibrate severity BEFORE applying the procedure above:
  - Be rigorous and demanding. You are assessing evidence of past behaviour, not
    effort, likeability or eloquence.
  - 3 is the baseline. A candidate who described a real episode and their own part
    in it adequately scores 3. Most indicators backed by usable evidence land here.
  - Scores of 4 and 5 are rare. A score of 5 requires ALL THREE of: a specific
    situation described with concrete detail; concrete actions the candidate
    personally took — what "we" or "the team" did is not what the candidate did;
    and a measurable outcome. Missing any one of the three is not a 5.
  - Generic, hypothetical or second-hand answers score 1 or 2. "I would normally",
    "our team always", and advice on how the job ought to be done describe no
    episode and are not evidence of past behaviour.
  - Fluency is not competence. A confident, well-structured answer with no concrete
    episode behind it is a LOW score, not a high one.
  - At step 5, resolve doubt DOWNWARD: award 4 only when the evidence CLEARLY
    exceeds the Score 3 anchor, and 2 only when it is CLEARLY below it. If you are
    unsure whether it clears the anchor, it does not.
    This scoping is absolute. It applies ONLY at step 5 and NEVER overrides steps
    2, 3 or 4: evidence matching an authored anchor is scored AT that anchor.
STANDARDS;

    /**
     * Build the prompt payload for a single competency scoring call.
     *
     * @param  object  $evaluation  Must expose framework_version_id.
     * @param  string  $competencyCode  Competency code (for error messages).
     * @param  int  $competencyId  Competency primary key.
     * @param  int  $roleId  Role primary key.
     * @param  string  $projectLocale  Project language code (e.g. 'en', 'it').
     * @param  Collection<int, BarsIndicator>  $indicators  Ordered by position.
     * @param  string  $transcript  Assembled session transcript.
     *
     * @throws RoleNoBarsException When $indicators is empty.
     * @throws AnchorTranslationMissingException When any field lacks a $projectLocale translation.
     */
    public function build(
        object $evaluation,
        string $competencyCode,
        int $competencyId,
        int $roleId,
        string $projectLocale,
        Collection $indicators,
        string $transcript,
    ): PromptPayload {
        if ($indicators->isEmpty()) {
            throw new RoleNoBarsException($competencyCode);
        }

        // Build the indicator rubric, checking all 4 translatable fields per indicator.
        $rubricLines = [];

        foreach ($indicators as $indicator) {
            $fieldsToCheck = ['text', 'anchor_5', 'anchor_3', 'anchor_1'];

            foreach ($fieldsToCheck as $field) {
                if (! $indicator->hasTranslation($field, $projectLocale)) {
                    throw new AnchorTranslationMissingException($competencyCode, $field, $projectLocale);
                }
            }

            $indicatorText = $indicator->getTranslation('text', $projectLocale);
            $anchor5 = $indicator->getTranslation('anchor_5', $projectLocale);
            $anchor3 = $indicator->getTranslation('anchor_3', $projectLocale);
            $anchor1 = $indicator->getTranslation('anchor_1', $projectLocale);

            $rubricLines[] = sprintf(
                "Indicator (position %d): %s\n  Score 5: %s\n  Score 3: %s\n  Score 1: %s",
                $indicator->position,
                $indicatorText,
                $anchor5,
                $anchor3,
                $anchor1,
            );
        }

        $rubric = implode("\n\n", $rubricLines);
        $indicatorCount = $indicators->count();
        $scoringProcedure = self::SCORING_PROCEDURE;
        $evaluationStandards = self::EVALUATION_STANDARDS;

        $systemPrompt = <<<PROMPT
You are a BARS (Behaviorally Anchored Rating Scale) evaluator. Your task is to score the candidate's interview transcript against the competency indicators below.

IMPORTANT RULES:
- You MUST evaluate EXACTLY {$indicatorCount} indicator(s), in the EXACT SAME ORDER they are listed below.
- For each indicator, assign a score from EXACTLY one of: 1, 2, 3, 4, 5, OR -1 if the indicator cannot be assessed from the transcript.
- Excerpts MUST be verbatim substrings of what the CANDIDATE said. Do NOT paraphrase or invent text, and never quote the interviewer's own question as evidence about the candidate.
- The transcript below is the WHOLE interview, covering every competency. The segment enclosed between the "=== TARGET COMPETENCY ... PRIMARY EVIDENCE BEGINS/ENDS ===" markers is the competency you are scoring now. Weight that segment first, but evidence the candidate gave elsewhere in the interview IS admissible when it genuinely bears on an indicator. The marker lines themselves are not part of the transcript and must never be quoted.
- If a behavior is not assessable from the transcript, use score -1 and provide an empty excerpts array.
- Return ONLY the JSON object below, with no additional text or commentary.

{$scoringProcedure}

{$evaluationStandards}

COMPETENCY INDICATORS (evaluate in this exact order):
{$rubric}

OUTPUT FORMAT (strict JSON, return the behaviors array in the SAME ORDER as the indicators above):
{
  "behaviors": [
    {
      "indicator": "<echo the indicator text>",
      "score": <1, 2, 3, 4, 5, or -1>,
      "explanation": "<brief explanation referencing the anchor; for a score of 4 or 2, name BOTH anchors the evidence falls between>",
      "excerpts": ["<verbatim substring 1>", "<verbatim substring 2>"]
    }
  ]
}
PROMPT;

        $options = [
            'temperature' => 0,
            'model' => config('scoring.model_version', 'fake-llm-provider-v1'),
            'prompt_version' => config('scoring.prompt_version', '1.0.0'),
        ];

        return new PromptPayload(
            systemPrompt: $systemPrompt,
            userMessage: "Transcript:\n{$transcript}",
            options: $options,
        );
    }
}
