<?php

declare(strict_types=1);

use App\DTOs\Audit\AuditRequest;
use App\DTOs\Audit\AuditSubject;
use App\Services\Audit\JevRequestBuilder;

/**
 * RED — P1.7: `JevRequestBuilder` is PURE — `AuditRequest` → `[body, keyMap]`
 * (design D2/D3). The wire shape below follows design.md's "Wire payload
 * sketch — UNVERIFIED (C-C)" placeholder, built from the TypeSafe skill's
 * described programming model (state / questions / Noul), NOT the live API
 * reference — no network access was available this session to confirm it.
 */
test('emits ordinal question keys scoped to the request and keeps the ordinal to indicatorScoreId map in memory', function (): void {
    $builder = new JevRequestBuilder;

    $request = new AuditRequest('COL', [
        new AuditSubject(
            indicatorScoreId: 501,
            position: 0,
            indicatorText: 'Shares information proactively with peers outside their own team.',
            score: 4,
            explanation: 'Volunteered updates to Team B twice.',
            excerpts: ['I made sure to loop in the other team early.'],
        ),
        new AuditSubject(
            indicatorScoreId: 999,
            position: 1,
            indicatorText: 'Escalates blockers early.',
            score: 3,
            explanation: 'Raised a blocker on day two.',
            excerpts: ['I flagged it as soon as I noticed.'],
        ),
    ]);

    [, $keyMap] = $builder->build($request);

    expect($keyMap)->toBe(['i1' => 501, 'i2' => 999]);
});

test('the built payload contains no indicatorScoreId, no transcript field, no participant field, and no email field anywhere', function (): void {
    $builder = new JevRequestBuilder;

    $request = new AuditRequest('COL', [
        new AuditSubject(
            indicatorScoreId: 501,
            position: 0,
            indicatorText: 'Shares information proactively with peers outside their own team.',
            score: 4,
            explanation: 'Volunteered updates to Team B twice.',
            excerpts: ['I made sure to loop in the other team early.'],
        ),
    ]);

    [$body] = $builder->build($request);

    $encoded = (string) json_encode($body);

    expect($encoded)->not->toContain('501')
        ->and($encoded)->not->toContain('indicatorScoreId')
        ->and($encoded)->not->toContain('indicator_score_id')
        ->and($encoded)->not->toContain('transcript')
        ->and($encoded)->not->toContain('participant')
        ->and($encoded)->not->toContain('candidate_ref')
        ->and($encoded)->not->toContain('email');
});

test('the built payload carries the competency code, one indicator per subject keyed by its ordinal, and three Noul questions per subject', function (): void {
    $builder = new JevRequestBuilder;

    $request = new AuditRequest('COL', [
        new AuditSubject(
            indicatorScoreId: 501,
            position: 0,
            indicatorText: 'Shares information proactively with peers outside their own team.',
            score: 4,
            explanation: 'Volunteered updates to Team B twice.',
            excerpts: ['I made sure to loop in the other team early.'],
        ),
    ]);

    [$body, $keyMap] = $builder->build($request);

    expect($body['state']['competency_code'])->toBe('COL')
        ->and($body['state']['indicators'])->toHaveCount(1)
        ->and($body['state']['indicators'][0]['key'])->toBe('i1')
        ->and($body['state']['indicators'][0]['indicator'])->toBe('Shares information proactively with peers outside their own team.')
        ->and($body['state']['indicators'][0]['assigned_score'])->toBe(4)
        ->and($body['state']['indicators'][0]['explanation'])->toBe('Volunteered updates to Team B twice.')
        ->and($body['state']['indicators'][0]['excerpts'])->toBe(['I made sure to loop in the other team early.']);

    $questionIds = array_column($body['questions'], 'id');

    expect($questionIds)->toBe(['i1.relevance', 'i1.calibration', 'i1.grounding'])
        ->and($keyMap)->toBe(['i1' => 501]);
});

test('an empty subjects list produces an empty indicators list, an empty questions list, and an empty key map', function (): void {
    $builder = new JevRequestBuilder;

    [$body, $keyMap] = $builder->build(new AuditRequest('COL', []));

    expect($body['state']['indicators'])->toBe([])
        ->and($body['questions'])->toBe([])
        ->and($keyMap)->toBe([]);
});
