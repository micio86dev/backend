<?php

declare(strict_types=1);

/**
 * Coverage honesty (proctoring-honest-coverage AD-2).
 *
 * The defect these pin down: on 2026-08-25 a real interview in which the
 * candidate deliberately looked away from the camera and picked up a phone was
 * reported as "Rischio basso, punteggio 0" — because the detectors had never
 * loaded and nothing distinguished "not measured" from "measured, nothing found".
 *
 * The governing asymmetry: a missing measurement can only ever RAISE the true
 * score, never lower it. So a medium/high band survives partial coverage (it is
 * a valid lower bound), while a LOW band does not — "low" is precisely the claim
 * the missing data cannot support.
 */

use App\Services\Proctoring\IntegritySummarizer;

/** @param list<array<string,mixed>> $events */
function summarizeEvents(array $events): array
{
    return IntegritySummarizer::summarize($events);
}

function unavailable(string $layer): array
{
    return ['kind' => 'proctor_unavailable', 'payload' => ['layer' => $layer]];
}

function lookingAway(int $ms): array
{
    return ['kind' => 'looking_away', 'payload' => ['durationMs' => $ms]];
}

test('a fully covered session with no findings still bands as low', function (): void {
    // The honest low: we watched, and nothing happened. An operator may rely on it.
    $summary = summarizeEvents([lookingAway(0)]);

    expect($summary['band'])->toBe('low')
        ->and($summary['coverage_complete'])->toBeTrue()
        ->and($summary['unavailable_layers'])->toBe([]);
});

test('a dead layer is reported as an unavailable layer', function (): void {
    $summary = summarizeEvents([unavailable('face')]);

    expect($summary['unavailable_layers'])->toBe(['face'])
        ->and($summary['coverage_complete'])->toBeFalse();
});

test('the LOW band is WITHHELD when coverage is incomplete', function (): void {
    // This is the whole change. Zero score plus a dead detector must never
    // render as a reassuring result about a real person.
    $summary = summarizeEvents([unavailable('face')]);

    expect($summary['score'])->toBe(0.0)
        ->and($summary['band'])->toBeNull();
});

test('a MEDIUM band survives incomplete coverage — it is a valid lower bound', function (): void {
    // looking_away is weighted 0.4/s; 40s = 16.0, over the 15.0 medium threshold.
    $summary = summarizeEvents([unavailable('phone'), lookingAway(40_000)]);

    expect($summary['band'])->toBe('medium')
        ->and($summary['coverage_complete'])->toBeFalse();
});

test('a HIGH band survives incomplete coverage', function (): void {
    // multiple_faces is weighted 4.0/s; 12s = 48.0, over the 40.0 high threshold.
    $summary = summarizeEvents([
        unavailable('phone'),
        ['kind' => 'multiple_faces', 'payload' => ['durationMs' => 12_000]],
    ]);

    expect($summary['band'])->toBe('high')
        ->and($summary['coverage_complete'])->toBeFalse();
});

test('several dead layers are all reported, deduplicated and ordered', function (): void {
    $summary = summarizeEvents([
        unavailable('phone'),
        unavailable('face'),
        unavailable('phone'),
    ]);

    expect($summary['unavailable_layers'])->toBe(['face', 'phone']);
});

test('a proctor_unavailable event contributes nothing to the score', function (): void {
    // It is a statement about the observer, never about the candidate. Scoring
    // it would penalise a person for a failure of ours.
    expect(summarizeEvents([unavailable('face')])['score'])->toBe(0.0);
});

test('an unavailable event with no named layer is still counted as a coverage gap', function (): void {
    // A malformed payload must fail towards honesty, not towards reassurance.
    $summary = summarizeEvents([['kind' => 'proctor_unavailable', 'payload' => []]]);

    expect($summary['coverage_complete'])->toBeFalse()
        ->and($summary['band'])->toBeNull();
});

test('proctor_unavailable appears in the timeline counts', function (): void {
    // An operator reading the timeline must see WHY the band is missing.
    $summary = summarizeEvents([unavailable('face')]);

    expect($summary['counts']['proctor_unavailable'] ?? 0)->toBe(1);
});

test('historical sessions with no coverage events are unchanged', function (): void {
    // Backwards compatibility in one direction only: nothing already recorded is
    // reinterpreted by this change.
    $summary = summarizeEvents([lookingAway(1_000)]);

    expect($summary['band'])->toBe('low')
        ->and($summary['coverage_complete'])->toBeTrue();
});
