<?php

declare(strict_types=1);

use App\DTOs\Audit\AuditRequest;
use App\DTOs\Audit\AuditSubject;
use App\Exceptions\Audit\AuditJudgeException;
use App\Services\Audit\TypesafeJevJudge;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * `TypesafeJevJudge` — raw `Http`, structured exactly per design D3.
 * Endpoint path confirmed against the live TypeSafe API contract
 * (https://docs.typesafe.ai/api.md, read during the
 * scoring-audit-jev-prod-recovery follow-up): `POST /v1/systemone`, not the
 * original `/v1/judgments` placeholder.
 */
function auditRequestFixture(): AuditRequest
{
    return new AuditRequest('COL', [
        new AuditSubject(
            indicatorScoreId: 501,
            position: 0,
            indicatorText: 'Shares information proactively with peers outside their own team.',
            score: 4,
            explanation: 'Volunteered updates to Team B twice.',
            excerpts: ['I made sure to loop in the other team early.'],
        ),
    ]);
}

test('a 5xx response yields a retryable exception', function (): void {
    Http::fake(['*' => Http::response('Internal error text — a candidate excerpt might leak here.', 503)]);

    $judge = new TypesafeJevJudge;

    try {
        $judge->judge(auditRequestFixture());
        $this->fail('Expected AuditJudgeException to be thrown.');
    } catch (AuditJudgeException $e) {
        expect($e->isRetryable())->toBeTrue();
    }
});

test('a 4xx response yields a non-retryable exception', function (): void {
    Http::fake(['*' => Http::response('Bad request body — a candidate excerpt might leak here.', 422)]);

    $judge = new TypesafeJevJudge;

    try {
        $judge->judge(auditRequestFixture());
        $this->fail('Expected AuditJudgeException to be thrown.');
    } catch (AuditJudgeException $e) {
        expect($e->isRetryable())->toBeFalse();
    }
});

test('a 429 (rate limited) response yields a retryable exception', function (): void {
    Http::fake(['*' => Http::response('Too many requests.', 429)]);

    $judge = new TypesafeJevJudge;

    try {
        $judge->judge(auditRequestFixture());
        $this->fail('Expected AuditJudgeException to be thrown.');
    } catch (AuditJudgeException $e) {
        expect($e->isRetryable())->toBeTrue();
    }
});

test('a 408 (request timeout) response yields a retryable exception', function (): void {
    Http::fake(['*' => Http::response('Request timeout.', 408)]);

    $judge = new TypesafeJevJudge;

    try {
        $judge->judge(auditRequestFixture());
        $this->fail('Expected AuditJudgeException to be thrown.');
    } catch (AuditJudgeException $e) {
        expect($e->isRetryable())->toBeTrue();
    }
});

test('a transport failure yields a retryable exception', function (): void {
    Http::fake(function (): never {
        throw new ConnectionException('Could not connect to TypeSafe.');
    });

    $judge = new TypesafeJevJudge;

    try {
        $judge->judge(auditRequestFixture());
        $this->fail('Expected AuditJudgeException to be thrown.');
    } catch (AuditJudgeException $e) {
        expect($e->isRetryable())->toBeTrue();
    }
});

test('the exception message contains no response body — status code only, never candidate excerpts', function (): void {
    Http::fake(['*' => Http::response('Internal error text — a candidate excerpt might leak here.', 500)]);

    $judge = new TypesafeJevJudge;

    try {
        $judge->judge(auditRequestFixture());
        $this->fail('Expected AuditJudgeException to be thrown.');
    } catch (AuditJudgeException $e) {
        expect($e->getMessage())->not->toContain('Internal error text')
            ->and($e->getMessage())->not->toContain('candidate excerpt')
            ->and($e->getMessage())->toContain('500');
    }
});

test('a successful response is delegated to the response mapper and returns an AuditBatchResult', function (): void {
    Http::fake(['*' => Http::response([
        'answers' => [
            'i1.relevance' => ['type' => 'noul', 'noul' => 0.9],
            'i1.calibration' => ['type' => 'noul', 'noul' => 0.85],
            'i1.grounding' => ['type' => 'noul', 'noul' => 0.8],
        ],
    ], 200)]);

    $judge = new TypesafeJevJudge;

    $result = $judge->judge(auditRequestFixture());

    expect($result->verdicts)->toHaveKey(501)
        ->and($result->verdicts[501]->supportProbability)->toBe(0.8);
});

test('the request is posted to /v1/systemone against the configured base URL', function (): void {
    config()->set('scoring.audit.base_url', 'https://api.typesafe.ai');
    Http::fake(['*' => Http::response(['answers' => []], 200)]);

    $judge = new TypesafeJevJudge;
    $judge->judge(auditRequestFixture());

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.typesafe.ai/v1/systemone');
});
