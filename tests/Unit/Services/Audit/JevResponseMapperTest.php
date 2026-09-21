<?php

declare(strict_types=1);

use App\Enums\Audit\AuditOutcomeReason;
use App\Exceptions\Audit\AuditJudgeException;
use App\Services\Audit\JevResponseMapper;
use Illuminate\Support\Facades\Log;

/**
 * Response mapping rules (design D3, table-driven). Response envelope shape
 * confirmed against the live TypeSafe API contract
 * (https://docs.typesafe.ai/api.md, read during the
 * scoring-audit-jev-prod-recovery follow-up): each answer is a NESTED object
 * `{"type": "noul", "noul": 0.9}`, not a flat float — the original P1.9
 * placeholder guessed the latter, and every real response silently mapped
 * to "malformed" because of it.
 *
 * P3b (`omissions` assertions below): resolves the gap P1 left open —
 * `AuditBatchResult` now carries WHY a subject was omitted, not only THAT it
 * was, distinguishing the three `AuditOutcomeReason::Verdict*` cases design
 * D3/C-E define.
 */
function jevNou(float|string $value): array
{
    return ['type' => 'noul', 'noul' => $value];
}

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
            'i1.relevance' => jevNou('not-a-number'),
            'i1.calibration' => jevNou(0.8),
            'i1.grounding' => jevNou(0.7),
        ],
    ], ['i1' => 501], 120);

    expect($result->verdicts)->toBe([])
        ->and($result->omissions)->toBe([501 => AuditOutcomeReason::VerdictUnparseable]);
});

test('a flat float answer (the pre-fix placeholder shape) is treated as unparseable, never as a valid probability', function (): void {
    $mapper = new JevResponseMapper;

    $result = $mapper->map([
        'answers' => [
            'i1.relevance' => 0.9,
            'i1.calibration' => jevNou(0.8),
            'i1.grounding' => jevNou(0.7),
        ],
    ], ['i1' => 501], 120);

    expect($result->verdicts)->toBe([])
        ->and($result->omissions)->toBe([501 => AuditOutcomeReason::VerdictUnparseable]);
});

test('an answer carrying a numeric "noul" key but a different "type" is treated as unparseable, never as a valid probability', function (): void {
    $mapper = new JevResponseMapper;

    $result = $mapper->map([
        'answers' => [
            // Same nested shape as a real Noul answer, but the discriminator
            // says something else — checking for a numeric "noul" key alone
            // would silently accept it as a probability it never claimed to be.
            'i1.relevance' => ['type' => 'something-else', 'noul' => 0.9],
            'i1.calibration' => jevNou(0.8),
            'i1.grounding' => jevNou(0.7),
        ],
    ], ['i1' => 501], 120);

    expect($result->verdicts)->toBe([])
        ->and($result->omissions)->toBe([501 => AuditOutcomeReason::VerdictUnparseable]);
});

test('a probability outside [0,1] omits the subject, reason ProbabilityOutOfDomain', function (): void {
    $mapper = new JevResponseMapper;

    $result = $mapper->map([
        'answers' => [
            'i1.relevance' => jevNou(1.5),
            'i1.calibration' => jevNou(0.8),
            'i1.grounding' => jevNou(0.7),
        ],
    ], ['i1' => 501], 120);

    expect($result->verdicts)->toBe([])
        ->and($result->omissions)->toBe([501 => AuditOutcomeReason::ProbabilityOutOfDomain]);
});

test('a "usage" field that is not an object throws AuditJudgeException', function (): void {
    $mapper = new JevResponseMapper;

    expect(fn () => $mapper->map([
        'answers' => ['i1.relevance' => jevNou(0.9), 'i1.calibration' => jevNou(0.9), 'i1.grounding' => jevNou(0.9)],
        'usage' => 'not-an-object',
    ], ['i1' => 501], 120))->toThrow(AuditJudgeException::class);
});

test('a "model" field that is not a string throws AuditJudgeException', function (): void {
    $mapper = new JevResponseMapper;

    expect(fn () => $mapper->map([
        'answers' => ['i1.relevance' => jevNou(0.9), 'i1.calibration' => jevNou(0.9), 'i1.grounding' => jevNou(0.9)],
        'model' => ['unexpected' => 'shape'],
    ], ['i1' => 501], 120))->toThrow(AuditJudgeException::class);
});

test('records the response\'s own pinned "model" field as judgeModel, not the config alias', function (): void {
    $mapper = new JevResponseMapper;

    $result = $mapper->map([
        'answers' => ['i1.relevance' => jevNou(0.9), 'i1.calibration' => jevNou(0.9), 'i1.grounding' => jevNou(0.9)],
        'model' => 'jev-1.13.0',
    ], ['i1' => 501], 120);

    expect($result->judgeModel)->toBe('jev-1.13.0');
});

test('falls back to the config alias for judgeModel only when the response omits "model" entirely — provenance is lost in that case', function (): void {
    // config/scoring.php's judge_model docblock: this fallback exists only so
    // the non-nullable judge_model_version column always has a value; it is
    // NOT a second source of precise provenance.
    config()->set('scoring.audit.judge_model', 'jev-latest');
    $mapper = new JevResponseMapper;

    $result = $mapper->map([
        'answers' => ['i1.relevance' => jevNou(0.9), 'i1.calibration' => jevNou(0.9), 'i1.grounding' => jevNou(0.9)],
    ], ['i1' => 501], 120);

    expect($result->judgeModel)->toBe('jev-latest');
});

test('a "usage" object with a non-numeric token count defaults that count to zero rather than throwing', function (): void {
    $mapper = new JevResponseMapper;

    $result = $mapper->map([
        'answers' => ['i1.relevance' => jevNou(0.9), 'i1.calibration' => jevNou(0.9), 'i1.grounding' => jevNou(0.9)],
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
            'i1.relevance' => jevNou(0.9),
            'i1.calibration' => jevNou(0.85),
            'i1.grounding' => jevNou(0.8),
            'i99.relevance' => jevNou(0.5),
            'i99.calibration' => jevNou(0.5),
            'i99.grounding' => jevNou(0.5),
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
            'i1.relevance' => jevNou(0.9),
            'i1.calibration' => jevNou(0.42),
            'i1.grounding' => jevNou(0.8),
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
