<?php

declare(strict_types=1);

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
 */
test('a missing ordinal key omits that subject from verdicts', function (): void {
    $mapper = new JevResponseMapper;

    $result = $mapper->map(['answers' => []], ['i1' => 501], 120);

    expect($result->verdicts)->toBe([]);
});

test('a non-numeric probability omits the subject from verdicts', function (): void {
    $mapper = new JevResponseMapper;

    $result = $mapper->map([
        'answers' => [
            'i1.relevance' => 'not-a-number',
            'i1.calibration' => 0.8,
            'i1.grounding' => 0.7,
        ],
    ], ['i1' => 501], 120);

    expect($result->verdicts)->toBe([]);
});

test('a probability outside [0,1] omits the subject from verdicts', function (): void {
    $mapper = new JevResponseMapper;

    $result = $mapper->map([
        'answers' => [
            'i1.relevance' => 1.5,
            'i1.calibration' => 0.8,
            'i1.grounding' => 0.7,
        ],
    ], ['i1' => 501], 120);

    expect($result->verdicts)->toBe([]);
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
