<?php

declare(strict_types=1);

/**
 * `ConversationLlmUsageEstimator` — the `chars4_context_resend_v1` formula
 * (pluggable-conversation-llm PR P6b, design D10, non-negotiable #3).
 *
 * THE MANDATORY ORACLE (design.md Gate Corrections B3; spec.md's numeric
 * oracle): `P=100`, participant turns `20/60/60`, avatar turns `80/80/80`.
 *
 *   c_1 = P + p_1                           = 100 + 20         = 120
 *   c_2 = P + (p_1+o_1) + p_2                = 100 + 100 + 60   = 260
 *   c_3 = P + (p_1+o_1+p_2+o_2) + p_3        = 100 + 240 + 60   = 400
 *
 * Revision 1's formula OMITTED the trailing `p_t` — the participant's
 * turn-t message IS the input the model is responding to — and produced
 * 100/200/340 instead. That under-count is asserted explicitly NOT
 * produced, because it reads as plausible on its own.
 *
 * These tests operate on TOKEN counts directly (not character strings):
 * tokenization (`tokens(s) = ceil(mb_strlen(s) / 4)`) is a separate,
 * independently-testable concern from the formula itself, and mixing the
 * two would make a formula bug indistinguishable from a rounding bug.
 *
 * REQ: conversation-llm "The context-resend estimator carries the current
 *      turn's eliciting utterance, tiers per-request from that request's
 *      own context, and refuses rather than coerces a missing rate"
 */

use App\Enums\LlmCapability;
use App\Models\LlmModel;
use App\Services\ConversationLlm\ConversationLlmUsageEstimator;

/**
 * An in-memory (never persisted) LlmModel — these tests exercise pure
 * arithmetic and must not require a database.
 */
function estimatorModel(array $rates = []): LlmModel
{
    $model = new LlmModel;
    $model->forceFill(array_merge([
        'key' => 'gemini-3-flash-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => LlmCapability::Text,
        'is_available' => true,
        'sort_order' => 0,
        'text_input_usd_per_million' => '1.000000',
        'text_output_usd_per_million' => '2.000000',
        'text_input_usd_per_million_high' => '4.000000',
        'text_output_usd_per_million_high' => '8.000000',
        'context_tier_threshold_tokens' => null,
    ], $rates));

    return $model;
}

/**
 * @param  list<array{participant_tokens: int, avatar_tokens: int}>  $turns
 */
function oracleTurns(): array
{
    return [
        ['participant_tokens' => 20, 'avatar_tokens' => 80],
        ['participant_tokens' => 60, 'avatar_tokens' => 80],
        ['participant_tokens' => 60, 'avatar_tokens' => 80],
    ];
}

test('the mandatory oracle: c_1=120, c_2=260, c_3=400 — the trailing p_t is carried', function (): void {
    $estimator = new ConversationLlmUsageEstimator;

    $result = $estimator->computeFromTokens(systemPromptTokens: 100, turns: oracleTurns(), model: null);

    // estimated_input_tokens is Σc_t across the three turns.
    expect($result['estimated_input_tokens'])->toBe(120 + 260 + 400)
        ->and($result['estimated_input_tokens'])->toBe(780);
});

test('the buggy omit-p_t formula (100/200/340) is explicitly NOT what this estimator produces', function (): void {
    $estimator = new ConversationLlmUsageEstimator;

    $result = $estimator->computeFromTokens(systemPromptTokens: 100, turns: oracleTurns(), model: null);

    // Revision 1's formula: c_t = P + Σ_{i<t}(p_i+o_i) — no trailing p_t.
    $buggyTotal = 100 + 200 + 340;

    expect($result['estimated_input_tokens'])->not->toBe($buggyTotal);
});

test('the naive Σ all chars / 4 total (480) is materially lower than the correct total (780)', function (): void {
    $estimator = new ConversationLlmUsageEstimator;

    $result = $estimator->computeFromTokens(systemPromptTokens: 100, turns: oracleTurns(), model: null);

    // naive = P + Σ p_i + Σ o_i, summed ONCE rather than per-turn — the
    // formula an engineer writes in five minutes, and wrong by design
    // because a conversational LLM re-sends its WHOLE history every turn.
    $naive = 100 + (20 + 60 + 60) + (80 + 80 + 80);

    expect($naive)->toBe(480)
        ->and($result['estimated_input_tokens'])->toBe(780)
        ->and($result['estimated_input_tokens'])->toBeGreaterThan($naive + 100); // materially, not by rounding
});

test('the tier is selected PER REQUEST from c_t, never from the running total', function (): void {
    // Every INDIVIDUAL c_t stays under the threshold even though Σc_t
    // crosses it well before the last turn.
    $turns = [
        ['participant_tokens' => 100, 'avatar_tokens' => 100], // c_1 = 100
        ['participant_tokens' => 100, 'avatar_tokens' => 100], // c_2 = 300
        ['participant_tokens' => 100, 'avatar_tokens' => 100], // c_3 = 500
        ['participant_tokens' => 100, 'avatar_tokens' => 100], // c_4 = 700
        ['participant_tokens' => 100, 'avatar_tokens' => 100], // c_5 = 900
    ];
    $sumCt = 100 + 300 + 500 + 700 + 900; // 2500 — exceeds the threshold
    $maxCt = 900;                          // never does, individually

    $model = estimatorModel(['context_tier_threshold_tokens' => 1000]);
    expect($maxCt)->toBeLessThan(1000)->and($sumCt)->toBeGreaterThan(1000);

    $estimator = new ConversationLlmUsageEstimator;
    $result = $estimator->computeFromTokens(systemPromptTokens: 0, turns: $turns, model: $model);

    // Entirely low-tier cost: Σ(c_t/1e6 * 1.0 + o_t/1e6 * 2.0).
    $expectedCost = (100 + 300 + 500 + 700 + 900) / 1_000_000 * 1.0
        + (100 * 5) / 1_000_000 * 2.0;

    expect($result['estimated_cost_usd'])->toBe(round($expectedCost, 6));
});

test('rate_out is selected from c_t, not from o_t', function (): void {
    // A single turn: participant message huge (pushes c_t over threshold),
    // avatar reply tiny (would suggest the LOW tier if keyed on o_t alone).
    $turns = [
        ['participant_tokens' => 200, 'avatar_tokens' => 1],
    ];
    // c_1 = 0 + 0 + 200 = 200, over the threshold of 100.
    $model = estimatorModel(['context_tier_threshold_tokens' => 100]);

    $estimator = new ConversationLlmUsageEstimator;
    $result = $estimator->computeFromTokens(systemPromptTokens: 0, turns: $turns, model: $model);

    // HIGH rates: input 4.0, output 8.0 — NOT the low output rate (2.0)
    // that o_t=1 alone would suggest.
    $expectedCost = (200 / 1_000_000) * 4.0 + (1 / 1_000_000) * 8.0;

    expect($result['estimated_cost_usd'])->toBe(round($expectedCost, 6));
});

test('an avatar-first transcript excludes the opening greeting from G, but its tokens live on in every later c_t', function (): void {
    // p_1 = 0 → turn 1 is the opening greeting: no c_1/o_1 billed for it.
    $turns = [
        ['participant_tokens' => 0, 'avatar_tokens' => 50],  // opening greeting — excluded from G
        ['participant_tokens' => 30, 'avatar_tokens' => 40], // c_2 = P + (0+50) + 30
    ];

    $estimator = new ConversationLlmUsageEstimator;
    $result = $estimator->computeFromTokens(systemPromptTokens: 10, turns: $turns, model: null);

    // Only ONE billed turn (the greeting contributes no c_t/o_t of its own).
    // c_2 = 10 + (0+50) + 30 = 90; estimated_output_tokens = o_2 only = 40.
    expect($result['estimated_input_tokens'])->toBe(90)
        ->and($result['estimated_output_tokens'])->toBe(40);
});

test('a NULL rate yields estimated_cost_usd === null, never 0.0', function (): void {
    $model = estimatorModel(['text_input_usd_per_million' => null]);

    $estimator = new ConversationLlmUsageEstimator;
    $result = $estimator->computeFromTokens(systemPromptTokens: 100, turns: oracleTurns(), model: $model);

    expect($result['estimated_cost_usd'])->toBeNull()
        ->and($result['estimated_cost_usd'])->not->toBe(0.0);
});

test('no model at all also refuses the cost, never coerces to zero', function (): void {
    $estimator = new ConversationLlmUsageEstimator;
    $result = $estimator->computeFromTokens(systemPromptTokens: 100, turns: oracleTurns(), model: null);

    expect($result['estimated_cost_usd'])->toBeNull();
});

test('forecastCostUsd is a single total for the reference interview, never null for a fully-priced model', function (): void {
    config()->set('conversation_llm.forecast', [
        'reference_minutes' => 15,
        'reference_turns' => 3,
        'reference_system_prompt_chars' => 400,
        'reference_participant_chars_per_turn' => 80,
        'reference_avatar_chars_per_turn' => 320,
    ]);
    $model = estimatorModel();

    $estimator = new ConversationLlmUsageEstimator;
    $forecast = $estimator->forecastCostUsd($model);

    expect($forecast)->not->toBeNull()
        ->and($forecast)->toBeGreaterThan(0.0);
});

test('forecastCostUsd refuses (null) for a model with no published rate, never coerces to zero', function (): void {
    config()->set('conversation_llm.forecast', [
        'reference_minutes' => 15,
        'reference_turns' => 3,
        'reference_system_prompt_chars' => 400,
        'reference_participant_chars_per_turn' => 80,
        'reference_avatar_chars_per_turn' => 320,
    ]);
    $model = estimatorModel(['text_input_usd_per_million' => null]);

    $estimator = new ConversationLlmUsageEstimator;

    expect($estimator->forecastCostUsd($model))->toBeNull();
});
