<?php

declare(strict_types=1);

namespace App\Exceptions\Participant;

/**
 * The closed set of reasons `RecoverFailedParticipant` refuses a recovery
 * (participant-error-recovery, design D5/D6).
 *
 * - EvaluationAlreadyDelivered: a scoring-stage failure — an `evaluation`
 *   WebhookDelivery row already exists for this participant. BEAI already
 *   told the calling system this assessment is over; re-opening it would be
 *   an integration contract break with no superseding-delivery concept.
 * - NothingToRecover: no `InterviewSession` at status=error exists. Every
 *   interview-stage failure leaves exactly one error session
 *   (createOrResumeSession() always runs before issue()); a scoring-stage
 *   failure never does. This is the structural discriminator between the two
 *   failure classes and also catches the ZeroCompetencies invariant case.
 * - NotFailed: the participant is not in `errore` (and not the idempotent
 *   `in_attesa` no-op case) — a live participant (in_corso/in_valutazione/
 *   completato) cannot be recovered.
 */
enum RecoveryRefusalReason: string
{
    case EvaluationAlreadyDelivered = 'evaluation_already_delivered';
    case NothingToRecover = 'nothing_to_recover';
    case NotFailed = 'not_failed';
}
