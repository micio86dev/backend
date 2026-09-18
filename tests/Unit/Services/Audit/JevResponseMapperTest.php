<?php

declare(strict_types=1);

use App\Enums\Audit\AuditOutcomeReason;
use App\Exceptions\Audit\AuditJudgeException;
use App\Services\Audit\JevResponseMapper;
use Illuminate\Support\Facades\Log;

/**
 * RED — P1.9 (table-driven): response mapping rules (design D3). Response
 * envelope shape — UNVERIFIED (C-C), same placeholder-status caveat as
 * `JevRequestBuilderTest`: a flat `answers` map keyed by question id
 * (`"i1.relevance" => 0.9`), the natural continuation of the request
 * envelope's own question-id namespace and Noul's "probability of yes"
 * contract, guessed from the TypeSafe skill's description because no
 * network access was available to confirm the live response shape.
 *
 * P3b (`omissions` assertions below): resolves the gap P1 left open —
 * `AuditBatchResult` now carries WHY a subject was omitted, not only THAT it
 * was, distinguishing the three `AuditOutcomeReason::Verdict*` cases design
 * D3/C-E define.
 */
test('a subject whose ordinal key is entirely absent omits that subject, reason VerdictMissing', function (): void {
    $mapper = new JevResponseMapper;

    $result = $mapper->map(['answers' => []], ['i1' => 501], 120);

    expect($result->verdicts)->toBe([])
        ->and($result->omissions)->toBe([501 => AuditOutcomeReason::VerdictMissing]);
});

test('a non-numeric probability omits the subject, reason VerdictUnparseable', function (): void {
    $mapper = new JevResponseMapper;

    $result = $mapper->map([
        'answers' => [
            'i1.relevance' => 'not-a-number',
            'i1.calibration' => 0.8,
            'i1.grounding' => 0.7,
        ],
    ], ['i1' => 501], 120);

    expect($result->verdicts)->toBe([])
        ->and($result->omissions)->toBe([501 => AuditOutcomeReason::VerdictUnparseable]);
});

test('a probability outside [0,1] omits the subject, reason ProbabilityOutOfDomain', function (): void {
    $mapper = new JevResponseMapper;

    $result = $mapper->map([
        'answers' => [
            'i1.relevance' => 1.5,
            'i1.calibration' => 0.8,
            'i1.grounding' => 0.7,
        ],
    ], ['i1' => 501], 120);

    expect($result->verdicts)->toBe([])
        ->and($result->omissions)->toBe([501 => AuditOutcomeReason::ProbabilityOutOfDomain]);
});

test('a "usage" field that is not an object throws AuditJudgeException', function (): void {
    $mapper = new JevResponseMapper;

    expect(fn () => $mapper->map([
        'answers' => ['i1.relevance' => 0.9, 'i1.calibration' => 0.9, 'i1.grounding' => 0.9],
        'usage' => 'not-an-object',
    ], ['i1' => 501], 120))->toThrow(AuditJudgeException::class);
});

test('a "model" field that is not a string throws AuditJudgeException', function (): void {
    $mapper = new JevResponseMapper;

    expect(fn () => $mapper->map([
        'answers' => ['i1.relevance' => 0.9, 'i1.calibration' => 0.9, 'i1.grounding' => 0.9],
        'model' => ['unexpected' => 'shape'],
    ], ['i1' => 501], 120))->toThrow(AuditJudgeException::class);
});

test('a "usage" object with a non-numeric token count defaults that count to zero rather than throwing', function (): void {
    $mapper = new JevResponseMapper;

    $result = $mapper->map([
        'answers' => ['i1.relevance' => 0.9, 'i1.calibration' => 0.9, 'i1.grounding' => 0.9],
        'usage' => ['input_tokens' => 'not-a-number', 'output_tokens' => 50],
    ], ['i1' => 501], 120);

    expect($result->inputTokens)->toBe(0)
        ->and($result->outputTokens)->toBe(50);
});

test('answers present for keys never sent are ignored and logged at warning', function (): void {
    Log::spy();

    $mapper = new JevResponseMapper;

    $result = $mapper->map([
        'answers' => [
            'i1.relevance' => 0.9,
            'i1.calibration' => 0.85,
            'i1.grounding' => 0.8,
            'i99.relevance' => 0.5,
            'i99.calibration' => 0.5,
            'i99.grounding' => 0.5,
        ],
    ], ['i1' => 501], 120);

    expect($result->verdicts)->toHaveCount(1)
        ->and($result->verdicts)->toHaveKey(501);

    Log::shouldHaveReceived('warning')->once();
});

test('support_probability is the minimum of the three raw probabilities for a fully valid subject', function (): void {
    $mapper = new JevResponseMapper;

    $result = $mapper->map([
        'answers' => [
            'i1.relevance' => 0.9,
            'i1.calibration' => 0.42,
            'i1.grounding' => 0.8,
        ],
    ], ['i1' => 501], 120);

    expect($result->verdicts[501]->indicatorScoreId)->toBe(501)
        ->and($result->verdicts[501]->supportProbability)->toBe(0.42)
        ->and($result->verdicts[501]->questionProbabilities)->toBe([
            'relevance' => 0.9,
            'calibration' => 0.42,
            'grounding' => 0.8,
        ]);
});

test('an unparseable or non-object envelope throws AuditJudgeException', function (mixed $json): void {
    $mapper = new JevResponseMapper;

    expect(fn () => $mapper->map($json, ['i1' => 501], 120))
        ->toThrow(AuditJudgeException::class);
})->with([
    'null' => [null],
    'scalar' => ['not-an-object'],
    'missing answers key' => [['foo' => 'bar']],
    'non-array answers' => [['answers' => 'not-an-array']],
]);
