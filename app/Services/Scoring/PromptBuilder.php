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
 * REQ: PromptBuilder (C9 D3/D6 FIX-11)
 */
final class PromptBuilder
{
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

        $systemPrompt = <<<PROMPT
You are a BARS (Behaviorally Anchored Rating Scale) evaluator. Your task is to score the candidate's interview transcript against the competency indicators below.

IMPORTANT RULES:
- You MUST evaluate EXACTLY {$indicatorCount} indicator(s), in the EXACT SAME ORDER they are listed below.
- For each indicator, assign a score from EXACTLY one of: 1, 3, or 5 (the closest anchor), OR -1 if the indicator cannot be assessed from the transcript.
- Do NOT use scores 2, 4, or any other value. Do NOT interpolate between anchors.
- Excerpts MUST be verbatim substrings of the transcript provided. Do NOT paraphrase or invent text.
- If a behavior is not assessable from the transcript, use score -1 and provide an empty excerpts array.
- Return ONLY the JSON object below, with no additional text or commentary.

COMPETENCY INDICATORS (evaluate in this exact order):
{$rubric}

OUTPUT FORMAT (strict JSON, return the behaviors array in the SAME ORDER as the indicators above):
{
  "behaviors": [
    {
      "indicator": "<echo the indicator text>",
      "score": <1, 3, 5, or -1>,
      "explanation": "<brief explanation referencing the anchor>",
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
