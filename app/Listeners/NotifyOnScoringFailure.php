<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\NotificationSubjectType;
use App\Enums\NotificationType;
use App\Events\EvaluationFailed;
use App\Jobs\SendOperatorNotificationJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Auto-discovered, PLAIN listener (C12, D8 layer 4).
 *
 * The try/catch here matters more than in the sibling listener, and for a
 * specific reason: EvaluationFailed is dispatched from inside
 * ScoreEvaluationJob::failed(). This listener therefore runs SYNCHRONOUSLY
 * within the scoring job's own failure handling. A throw here would corrupt the
 * handling of the failure it is reporting — turning "we could not score this
 * candidate" into "we could not score this candidate AND we lost the record of
 * why".
 */
final class NotifyOnScoringFailure
{
    public function handle(EvaluationFailed $event): void
    {
        try {
            SendOperatorNotificationJob::dispatch(
                NotificationType::ScoringFailed,
                NotificationSubjectType::Participant,
                $event->participantId,
            )->afterCommit();
        } catch (Throwable $e) {
            Log::error('notification.listener.failed', [
                'listener' => self::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
