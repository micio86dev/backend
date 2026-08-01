<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Exceptions\Admin\LifecycleNotReadyException;

/**
 * States each lifecycle read threshold once (C11 D2).
 *
 * Fail-closed: the only success path is an explicit match. There is no
 * `?? true` fallback — an unrecognized status denies for every scope,
 * including Summary (D2's "no lifecycle threshold" still means "one of the
 * known domain values", never an arbitrary/corrupt string).
 *
 * `errore` is deliberately ABSENT from ORDERED_STATUSES: it is
 * terminal-FAILED, not "further along" than in_valutazione. It IS present in
 * KNOWN_STATUSES, because the admin Summary read (list/detail) must still
 * work for an errored participant — the admin needs to see failures too
 * (spec: "List and detail return only RBAC-gated data ... regardless of
 * lifecycle status").
 *
 * REQ: Lifecycle Read-Gate (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 */
final class LifecycleReadGate
{
    /**
     * Ordered progression toward completion, used for the Transcript/Evaluation
     * threshold comparison. `errore` is deliberately absent (see class docblock).
     *
     * @var list<string>
     */
    private const ORDERED_STATUSES = ['in_attesa', 'in_corso', 'in_valutazione', 'completato'];

    /**
     * Every domain-legal status, including the terminal-failed `errore`.
     * Used only for the Summary scope, which has no ordered threshold.
     *
     * @var list<string>
     */
    private const KNOWN_STATUSES = ['in_attesa', 'in_corso', 'in_valutazione', 'completato', 'errore'];

    public function assert(string $status, ParticipantReadScope $scope): void
    {
        if ($scope === ParticipantReadScope::Summary) {
            if (in_array($status, self::KNOWN_STATUSES, true)) {
                return;
            }

            throw new LifecycleNotReadyException(
                resource: 'participant',
                currentStatus: $status,
                requiredStatus: 'known_lifecycle_status',
            );
        }

        $requiredStatus = match ($scope) {
            ParticipantReadScope::Transcript => 'in_valutazione',
            ParticipantReadScope::Evaluation => 'completato',
        };

        $currentIndex = array_search($status, self::ORDERED_STATUSES, true);
        $requiredIndex = array_search($requiredStatus, self::ORDERED_STATUSES, true);

        // $requiredIndex is always an int — both required statuses are hardcoded
        // members of ORDERED_STATUSES above. $currentIndex is false for any
        // status not in the ordered list (including 'errore' and unknown values).
        if ($currentIndex !== false && $requiredIndex !== false && $currentIndex >= $requiredIndex) {
            return;
        }

        throw new LifecycleNotReadyException(
            resource: $scope === ParticipantReadScope::Transcript ? 'transcript' : 'evaluation',
            currentStatus: $status,
            requiredStatus: $requiredStatus,
        );
    }
}
