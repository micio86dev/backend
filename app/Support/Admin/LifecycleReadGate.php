<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Exceptions\Admin\LifecycleNotReadyException;

/**
 * States each lifecycle read threshold once (C11 D2; two-clause rule per
 * operator-participant-visibility D1).
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
 * A gated scope (Transcript/Evaluation) is no longer a single ordered
 * comparison. Each scope names a `minimum` — a point ON the ORDERED_STATUSES
 * progression — plus an `off_progression` allow-list of statuses that are
 * NOT on it and are admitted through a second, explicitly disjoint clause
 * (operator-participant-visibility D1). `errore` is never appended to
 * ORDERED_STATUSES: doing so would assert it ranks ABOVE every other status
 * (array_search would then let it satisfy every threshold, including
 * Evaluation — exactly what the design rejects). Instead it is named,
 * per-scope, in `off_progression`.
 *
 * Two invariants keep the two clauses from contaminating each other, each
 * pinned by a dedicated test (LifecycleReadGateTest.php), not by this
 * comment:
 *   - Disjointness: no `off_progression` member may also appear in
 *     ORDERED_STATUSES. With that held, the ranked clause is structurally
 *     unreachable for an off-progression status, and vice versa.
 *   - Closure: every `off_progression` member must be a KNOWN_STATUSES
 *     value. A typo admits nothing; fail-closed survives.
 *
 * REQ: Lifecycle Read-Gate (openspec/changes/operator-participant-visibility/specs/admin-read-api/spec.md)
 */
final class LifecycleReadGate
{
    /**
     * Ordered progression toward completion, used for the Transcript/Evaluation
     * threshold comparison. `errore` is deliberately absent (see class docblock).
     * BYTE-FOR-BYTE UNCHANGED by operator-participant-visibility — `errore`
     * MUST NEVER enter this list.
     *
     * @var list<string>
     */
    private const ORDERED_STATUSES = ['in_attesa', 'in_corso', 'in_valutazione', 'completato'];

    /**
     * Every domain-legal status, including the terminal-failed `errore`.
     * Used for the Summary scope (no ordered threshold) and as the closure
     * set every `off_progression` member must belong to.
     *
     * @var list<string>
     */
    private const KNOWN_STATUSES = ['in_attesa', 'in_corso', 'in_valutazione', 'completato', 'errore'];

    /**
     * Per-scope rule: `minimum` is a point ON ORDERED_STATUSES; `off_progression`
     * lists statuses that are NOT on it and are admitted independently of the
     * ranked comparison (D1). Evaluation's `off_progression` is deliberately
     * empty — a BARS verdict for a crashed interview is never permitted.
     *
     * @var array<string, array{minimum: string, off_progression: list<string>}>
     */
    private const SCOPE_RULES = [
        'transcript' => ['minimum' => 'in_corso', 'off_progression' => ['errore']],
        'evaluation' => ['minimum' => 'completato', 'off_progression' => []],
    ];

    public function assert(string $status, ParticipantReadScope $scope): void
    {
        if ($this->permits($status, $scope)) {
            return;
        }

        if ($scope === ParticipantReadScope::Summary) {
            throw new LifecycleNotReadyException(
                resource: 'participant',
                currentStatus: $status,
                requiredStatus: 'known_lifecycle_status',
            );
        }

        $scopeKey = $this->scopeKey($scope);

        throw new LifecycleNotReadyException(
            resource: $scopeKey,
            currentStatus: $status,
            requiredStatus: self::SCOPE_RULES[$scopeKey]['minimum'],
        );
    }

    /**
     * The same rule `assert()` enforces, as a question rather than a demand.
     *
     * Exists for a read that must DEGRADE rather than refuse: the session
     * review is a Summary read that additionally carries the BARS evidence for
     * the competency it probed, and that evidence is Evaluation-scoped. Raising
     * the whole endpoint to the Evaluation threshold would 409 an in-flight
     * candidate's session review — the review's main use — so the caller asks
     * whether the block may be INCLUDED and omits it otherwise.
     *
     * Sharing one implementation is the point: a second copy of the threshold
     * logic is a second place for `errore` to accidentally rank above
     * `completato`. Fail-closed is inherited unchanged — an unrecognized status
     * returns false for every scope.
     */
    public function permits(string $status, ParticipantReadScope $scope): bool
    {
        if ($scope === ParticipantReadScope::Summary) {
            return in_array($status, self::KNOWN_STATUSES, true);
        }

        $rule = self::SCOPE_RULES[$this->scopeKey($scope)];

        return $this->reaches($status, $rule['minimum'])
            || in_array($status, $rule['off_progression'], true);
    }

    private function scopeKey(ParticipantReadScope $scope): string
    {
        return $scope === ParticipantReadScope::Transcript ? 'transcript' : 'evaluation';
    }

    /**
     * Partial is NOT a new lifecycle rule: complete iff the status would have
     * passed the OLD Transcript threshold (`in_valutazione`), before D1
     * loosened it to `in_corso`. `errore` is unranked → never reaches →
     * partial, with zero special-casing. An unrecognized status also reads
     * partial — fail-closed in the disclosure direction too
     * (operator-participant-visibility D2).
     */
    public function isTranscriptPartial(string $status): bool
    {
        return ! $this->reaches($status, 'in_valutazione');
    }

    /**
     * The ranked-progression comparison, extracted verbatim from the single
     * ordered comparison this class used before D1 — named, not rewritten.
     * False for any status not on ORDERED_STATUSES (including `errore` and
     * unrecognized values).
     */
    private function reaches(string $status, string $threshold): bool
    {
        $currentIndex = array_search($status, self::ORDERED_STATUSES, true);
        $thresholdIndex = array_search($threshold, self::ORDERED_STATUSES, true);

        // $thresholdIndex is always an int — every threshold passed here is a
        // hardcoded ORDERED_STATUSES member (SCOPE_RULES minimums above, or
        // the literal 'in_valutazione' in isTranscriptPartial()).
        return $currentIndex !== false && $thresholdIndex !== false && $currentIndex >= $thresholdIndex;
    }
}
