<?php

declare(strict_types=1);

namespace App\Services\Proctoring;

/**
 * Weighted proctoring risk score for one interview session (C11 review surface).
 *
 * A verbatim port of `summarizeIntegrity()` from
 * `legacy-demo/src/lib/proctor-config.ts`, which `CLAUDE.md` names as the
 * source of the proctoring taxonomy. The weights and band thresholds are
 * reproduced exactly; changing either changes what an operator is told about a
 * candidate, so both are pinned by tests rather than left to drift.
 *
 * SERVER-SIDE ONLY, deliberately (design D3). The backoffice renders what this
 * returns and does not recompute it: two implementations of a weighted score
 * diverge, and the figure an operator acts on must be the one the API can
 * defend.
 *
 * Pure — no I/O, no models. It takes plain arrays so it can be unit-tested
 * without a database and reused wherever events are already in memory.
 *
 * REQ: Admin session review
 */
final class IntegritySummarizer
{
    /**
     * Per-second weights, applied to the accumulated duration of each kind.
     */
    private const WEIGHT_PER_SECOND = [
        'tab_hidden' => 1.0,
        'face_absent' => 0.5,
        'looking_away' => 0.4,
        'multiple_faces' => 4.0,
        'second_voice' => 3.0,
    ];

    /**
     * Per-occurrence weights, applied to the count of each kind.
     */
    private const WEIGHT_PER_EVENT = [
        'focus_lost' => 3.0,
        'fullscreen_exit' => 5.0,
        'clipboard_copy' => 4.0,
        'clipboard_paste' => 6.0,
    ];

    /**
     * A second monitor is a fact about the setup, not a repeated behaviour, so
     * it scores once however many times the client reports it.
     */
    private const WEIGHT_SECOND_MONITOR = 8.0;

    /** score < MEDIUM → low; MEDIUM ≤ score < HIGH → medium; ≥ HIGH → high. */
    private const BAND_MEDIUM = 15.0;

    private const BAND_HIGH = 40.0;

    /**
     * @param  list<array{kind: string, payload?: array<string, mixed>|null}>  $events
     * @return array<string, mixed>
     */
    public static function summarize(array $events): array
    {
        $counts = [];
        $seconds = array_fill_keys(array_keys(self::WEIGHT_PER_SECOND), 0.0);
        $secondMonitor = false;

        foreach ($events as $event) {
            $kind = $event['kind'];
            $payload = $event['payload'] ?? null;

            // Counted BEFORE any weighting: kinds the score ignores
            // (`phone_detected`, `looking_down`) still belong in the timeline an
            // operator reads. A score of zero does not mean nothing happened.
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;

            if (array_key_exists($kind, $seconds)) {
                $seconds[$kind] += self::durationSeconds($payload);
            }

            if ($kind === 'second_monitor' && ($payload['isExtended'] ?? null) === true) {
                $secondMonitor = true;
            }
        }

        $score = $secondMonitor ? self::WEIGHT_SECOND_MONITOR : 0.0;

        foreach (self::WEIGHT_PER_SECOND as $kind => $weight) {
            $score += $seconds[$kind] * $weight;
        }

        foreach (self::WEIGHT_PER_EVENT as $kind => $weight) {
            $score += ($counts[$kind] ?? 0) * $weight;
        }

        $score = round($score, 1);

        return [
            'score' => $score,
            'band' => self::band($score),
            'counts' => $counts,
            'total' => count($events),
            'tab_hidden_sec' => (int) round($seconds['tab_hidden']),
            'face_absent_sec' => (int) round($seconds['face_absent']),
            'looking_away_sec' => (int) round($seconds['looking_away']),
            'multiple_faces_sec' => (int) round($seconds['multiple_faces']),
            'second_voice_sec' => (int) round($seconds['second_voice']),
            'second_monitor' => $secondMonitor,
            'fullscreen_exits' => $counts['fullscreen_exit'] ?? 0,
            'clipboard_copies' => $counts['clipboard_copy'] ?? 0,
            'clipboard_pastes' => $counts['clipboard_paste'] ?? 0,
        ];
    }

    /**
     * A duration the client never sent, or sent malformed, contributes nothing.
     *
     * Proctoring payloads arrive from a browser under conditions nobody
     * controls; a session must still be reviewable when one event is garbage,
     * so this returns zero rather than throwing.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private static function durationSeconds(?array $payload): float
    {
        $ms = $payload['durationMs'] ?? null;

        if (! is_numeric($ms)) {
            return 0.0;
        }

        $ms = (float) $ms;

        return $ms > 0 ? $ms / 1000 : 0.0;
    }

    private static function band(float $score): string
    {
        if ($score >= self::BAND_HIGH) {
            return 'high';
        }

        return $score >= self::BAND_MEDIUM ? 'medium' : 'low';
    }
}
