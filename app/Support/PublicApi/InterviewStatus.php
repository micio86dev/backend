<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

/**
 * The BEAI Public API (`/v1`) `Interview.status` — a 1:1 English rendering
 * of the binding candidate lifecycle (public-api step 5, SPEC.md §3.3, G-06).
 *
 * The stored, Italian `participants.status` value IS the source of truth
 * (`App\Models\Participant::$allowedTransitions` owns the transitions; this
 * class only RENDERS, it never drives them — SPEC.md §3.3 "Transitions are
 * the model guard's; the API observes them and never drives them"). No
 * public-only state exists, and no stored state is left unmapped:
 *
 *   pending          = in_attesa
 *   in_progress      = in_corso
 *   under_evaluation = in_valutazione
 *   completed        = completato
 *   error            = errore
 */
enum InterviewStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case UnderEvaluation = 'under_evaluation';
    case Completed = 'completed';
    case Error = 'error';

    /**
     * @throws \ValueError when `$stored` is not one of the five binding
     *                     lifecycle values — a stored value this enum does not recognise is a
     *                     data-integrity bug, never a value to silently coerce.
     */
    public static function fromStored(string $stored): self
    {
        return match ($stored) {
            'in_attesa' => self::Pending,
            'in_corso' => self::InProgress,
            'in_valutazione' => self::UnderEvaluation,
            'completato' => self::Completed,
            'errore' => self::Error,
            default => throw new \ValueError(sprintf('InterviewStatus: unrecognised stored value "%s".', $stored)),
        };
    }

    public function toStored(): string
    {
        return match ($this) {
            self::Pending => 'in_attesa',
            self::InProgress => 'in_corso',
            self::UnderEvaluation => 'in_valutazione',
            self::Completed => 'completato',
            self::Error => 'errore',
        };
    }

    /**
     * The public status value set, e.g. for `Rule::in()` on the `?status=`
     * list filter (gga round 4 finding 6 —
     * `App\Http\Controllers\PublicApi\InterviewController::validateFilterFormats()`
     * previously hand-built this same list via `array_map` over
     * `self::cases()` inline; a single source of truth here matches
     * `App\Enums\ProjectStatus`/`AssessmentType`/`RoleCode`'s own `values()`
     * convention).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
