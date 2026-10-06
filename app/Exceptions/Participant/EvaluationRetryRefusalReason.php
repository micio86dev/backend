<?php

declare(strict_types=1);

namespace App\Exceptions\Participant;

/**
 * The closed set of reasons `AuthorizeEvaluationRetry` refuses an evaluation
 * retry (scoring-retry-rt-b, design D4). The cases are declared in the order
 * the action checks them, under the participant row lock:
 *
 * - RetryAlreadyConsumed: the evaluation already carries `retry_attempt = true`.
 *   Checked FIRST on purpose: a participant mid-retry is `in_attesa`, so a
 *   status-first order would answer a second authorization `not_completed`.
 * - NotCompleted: the participant is not `completato`.
 * - TestModeParticipant: a test-mode participant is never scored by the real
 *   job (`DispatchScoringJob` skips it), so a retry would strand it.
 * - EvaluationNotPending: no evaluation row, or its status is not `pending`
 *   (a `completed` evaluation has nothing to re-interview).
 * - ProjectInaccessible: the project is closed, not yet live, past its
 *   deadline or soft-deleted, so no entry link can be minted.
 *
 * The machine values are rendered as the `reason` of the 409 response and are
 * mapped to i18n keys by the backoffice.
 */
enum EvaluationRetryRefusalReason: string
{
    case RetryAlreadyConsumed = 'retry_already_consumed';
    case NotCompleted = 'not_completed';
    case TestModeParticipant = 'test_mode_participant';
    case EvaluationNotPending = 'evaluation_not_pending';
    case ProjectInaccessible = 'project_inaccessible';
}
