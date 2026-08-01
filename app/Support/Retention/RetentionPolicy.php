<?php

declare(strict_types=1);

namespace App\Support\Retention;

use Illuminate\Support\Carbon;

/**
 * Resolves "is this artifact class due for purge, and from when" (C13).
 *
 * Kept apart from the command that acts on it, because the two answer different
 * questions and only one of them is dangerous. This class decides; the command
 * deletes. That separation is what lets the decision be tested exhaustively
 * against fixture durations without a single row ever being at risk.
 */
final class RetentionPolicy
{
    /** Every artifact class the purge knows how to handle. */
    public const CLASSES = [
        'snapshot',
        'transcript',
        'webhook_payload',
        'participant_pii',
    ];

    public function isEnabled(): bool
    {
        return (bool) config('retention.enabled', false);
    }

    /**
     * The cutoff for a class: artifacts created before this are due.
     *
     * Returns null when the class has no ratified duration. Null is NOT
     * "keep forever" and NOT "delete now" — it is an unratified decision, and
     * the caller must skip the class and say so. Guessing in either direction
     * is how a purge either becomes useless or becomes a data-loss incident.
     */
    public function cutoffFor(string $class): ?Carbon
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $days = config("retention.days.{$class}");

        if (! is_int($days) || $days < 1) {
            return null;
        }

        return Carbon::now()->subDays($days);
    }

    /**
     * Classes with no ratified duration, for the command to report.
     *
     * Reported rather than logged quietly: an operator running a purge that
     * silently skipped half its inventory would reasonably believe the data was
     * gone.
     *
     * @return list<string>
     */
    public function unratifiedClasses(): array
    {
        return array_values(array_filter(
            self::CLASSES,
            fn (string $c): bool => $this->cutoffFor($c) === null,
        ));
    }

    public function batchSize(): int
    {
        return max(1, (int) config('retention.batch_size', 500));
    }
}
