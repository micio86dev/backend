<?php

declare(strict_types=1);

/**
 * IntegritySummarizer — weighted proctoring risk score (C11 review surface).
 *
 * A verbatim port of `legacy-demo/src/lib/proctor-config.ts`
 * (`RISK_WEIGHTS`, `RISK_BANDS`, `summarizeIntegrity`), which `CLAUDE.md` names
 * as the source. The weights are the contract, so they are asserted here rather
 * than left to drift: a change to any of them changes what an operator is told
 * about a candidate, and that should never happen silently.
 *
 * Server-side ONLY. The backoffice must not carry a second implementation —
 * two weighted scores diverge, and the one an operator acts on has to be the
 * one the API can defend.
 *
 * REQ: Admin session review
 */

use App\Services\Proctoring\IntegritySummarizer;

function ev(string $kind, array $payload = []): array
{
    return ['kind' => $kind, 'payload' => $payload];
}

test('an empty session scores zero and bands low', function (): void {
    $summary = IntegritySummarizer::summarize([]);

    expect($summary['score'])->toBe(0.0)
        ->and($summary['band'])->toBe('low')
        ->and($summary['total'])->toBe(0);
});

test('durations are read from the payload in milliseconds and reported in seconds', function (): void {
    $summary = IntegritySummarizer::summarize([
        ev('tab_hidden', ['durationMs' => 4000]),
        ev('face_absent', ['durationMs' => 6000]),
    ]);

    expect($summary['tab_hidden_sec'])->toBe(4)
        ->and($summary['face_absent_sec'])->toBe(6);
});

test('a malformed or absent duration contributes nothing rather than throwing', function (array $payload): void {
    $summary = IntegritySummarizer::summarize([ev('tab_hidden', $payload)]);

    expect($summary['tab_hidden_sec'])->toBe(0)
        ->and($summary['score'])->toBe(0.0);
})->with([
    'missing' => [[]],
    'not numeric' => [['durationMs' => 'soon']],
    'negative' => [['durationMs' => -5000]],
    'null' => [['durationMs' => null]],
]);

test('per-event weights match the ported table', function (string $kind, int $count, float $expected): void {
    $events = array_fill(0, $count, ev($kind));

    expect(IntegritySummarizer::summarize($events)['score'])->toBe($expected);
})->with([
    'focus_lost = 3 each' => ['focus_lost', 2, 6.0],
    'fullscreen_exit = 5 each' => ['fullscreen_exit', 2, 10.0],
    'clipboard_copy = 4 each' => ['clipboard_copy', 1, 4.0],
    'clipboard_paste = 6 each' => ['clipboard_paste', 1, 6.0],
]);

test('per-second weights match the ported table', function (string $kind, int $ms, float $expected): void {
    $score = IntegritySummarizer::summarize([ev($kind, ['durationMs' => $ms])])['score'];

    expect($score)->toBe($expected);
})->with([
    'tab_hidden = 1.0/s' => ['tab_hidden', 10_000, 10.0],
    'face_absent = 0.5/s' => ['face_absent', 10_000, 5.0],
    'looking_away = 0.4/s' => ['looking_away', 10_000, 4.0],
    'multiple_faces = 4/s' => ['multiple_faces', 1_000, 4.0],
    'second_voice = 3.0/s' => ['second_voice', 1_000, 3.0],
]);

test('a second monitor scores only when the payload says it was extended', function (): void {
    expect(IntegritySummarizer::summarize([ev('second_monitor', ['isExtended' => true])])['score'])->toBe(8.0)
        ->and(IntegritySummarizer::summarize([ev('second_monitor', ['isExtended' => false])])['score'])->toBe(0.0);
});

test('a second monitor counts once however many times it is reported', function (): void {
    $summary = IntegritySummarizer::summarize([
        ev('second_monitor', ['isExtended' => true]),
        ev('second_monitor', ['isExtended' => true]),
    ]);

    // It is a fact about the setup, not a repeated behaviour: scoring it twice
    // would punish a client that re-reported the same display.
    expect($summary['score'])->toBe(8.0)
        ->and($summary['second_monitor'])->toBeTrue();
});

test('band thresholds are inclusive at their boundaries', function (float $score, string $band): void {
    // Driven through tab_hidden at 1.0/s so the score is exactly controllable.
    $summary = IntegritySummarizer::summarize([ev('tab_hidden', ['durationMs' => (int) ($score * 1000)])]);

    expect($summary['band'])->toBe($band);
})->with([
    'just under medium' => [14.9, 'low'],
    'exactly medium' => [15.0, 'medium'],
    'just under high' => [39.9, 'medium'],
    'exactly high' => [40.0, 'high'],
]);

test('the score is rounded to one decimal', function (): void {
    $summary = IntegritySummarizer::summarize([ev('looking_away', ['durationMs' => 3333])]);

    // 3.333s * 0.4 = 1.3332
    expect($summary['score'])->toBe(1.3);
});

test('every event is counted by kind, including kinds that carry no weight', function (): void {
    $summary = IntegritySummarizer::summarize([
        ev('phone_detected'),
        ev('phone_detected'),
        ev('looking_down'),
    ]);

    // `phone_detected` and `looking_down` are in the taxonomy but absent from
    // the weight table. They must still be COUNTED: an operator reading the
    // timeline needs to see them even though the ported score ignores them.
    expect($summary['counts']['phone_detected'])->toBe(2)
        ->and($summary['counts']['looking_down'])->toBe(1)
        ->and($summary['total'])->toBe(3)
        ->and($summary['score'])->toBe(0.0);
});

test('an unknown kind never throws', function (): void {
    $summary = IntegritySummarizer::summarize([ev('something_invented_later')]);

    expect($summary['total'])->toBe(1)
        ->and($summary['score'])->toBe(0.0);
});
