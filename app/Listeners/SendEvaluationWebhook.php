<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Events\EvaluationCompleted;
use App\Events\EvaluationFailed;
use App\Jobs\DeliverWebhookJob;
use App\Models\Evaluation;
use App\Models\Participant;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\EvaluationPayloadAssembler;
use App\Services\Webhooks\WebhookDeliveryRecorder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SendEvaluationWebhook — bridges C9's EvaluationCompleted/EvaluationFailed to the C10
 * delivery pipeline (design.md D4, task 11.2).
 *
 * Auto-discovered exactly like App\Listeners\DispatchScoringJob — a UNION-typed
 * `handle(EvaluationCompleted|EvaluationFailed $event)` parameter is correctly
 * resolved by Laravel's DiscoverEvents (verified:
 * Illuminate\Reflection\Reflector::getParameterClassNames() explicitly walks
 * ReflectionUnionType). No App\Providers\EventServiceProvider::$listen edit — it
 * stays empty, per the C9 precedent.
 *
 * Plain (NOT ShouldQueue) — runs synchronously inside whatever dispatched the event
 * (ScoreEvaluationJob in production). The WHOLE body is wrapped in
 * try/catch(\Throwable): a webhook-recording failure must NEVER propagate back into
 * ScoreEvaluationJob, where an uncaught exception would flip an already-successfully
 * -scored participant to 'errore' (ScoreEvaluationJob::failed(), :719-751) — an
 * unrelated C10 bug corrupting a correct C9 outcome.
 *
 * REQ: SendEvaluationWebhook listener (C10 D4)
 */
class SendEvaluationWebhook
{
    public function __construct(
        private readonly EvaluationPayloadAssembler $assembler,
        private readonly WebhookDeliveryRecorder $recorder,
    ) {}

    public function handle(EvaluationCompleted|EvaluationFailed $event): void
    {
        try {
            if ($event instanceof EvaluationCompleted) {
                $this->handleCompleted($event);
            } else {
                $this->handleFailed($event);
            }
        } catch (Throwable $e) {
            Log::error('SendEvaluationWebhook: failed to record/dispatch delivery', [
                'event' => $event::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleCompleted(EvaluationCompleted $event): void
    {
        /** @var Evaluation $evaluation */
        $evaluation = Evaluation::withoutGlobalScopes()->findOrFail($event->evaluationId);
        /** @var Participant $participant */
        $participant = Participant::findOrFail($evaluation->participant_id);

        // dedupe_key for evaluation events IS the evaluation_id (spec: "For evaluation
        // events, dedupe_key MUST be the evaluation_id").
        $dedupeKey = (string) $evaluation->id;

        $delivery = $this->recorder->record(
            $participant->project_id,
            $participant->id,
            WebhookEventType::Evaluation,
            $dedupeKey,
            fn (string $deliveryId): array => $this->assembler->assembleForEvaluation($evaluation->id, $deliveryId)
        );

        $this->dispatchIfPending($delivery);
    }

    private function handleFailed(EvaluationFailed $event): void
    {
        /** @var Participant $participant */
        $participant = Participant::findOrFail($event->participantId);

        // EvaluationFailed carries no evaluation_id. If a processing-stage Evaluation
        // row happens to exist, dedupe on it (consistent with the completed path);
        // otherwise fall back to a participant-scoped key.
        $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->first();
        $dedupeKey = $evaluation !== null ? (string) $evaluation->id : 'participant-failed:'.$participant->id;

        $delivery = $this->recorder->record(
            $participant->project_id,
            $participant->id,
            WebhookEventType::Evaluation,
            $dedupeKey,
            fn (string $deliveryId): array => $this->assembler->assembleForFailedParticipant($participant->id, $deliveryId)
        );

        $this->dispatchIfPending($delivery);
    }

    private function dispatchIfPending(WebhookDelivery $delivery): void
    {
        if ($delivery->status === WebhookDeliveryStatus::Pending) {
            DeliverWebhookJob::dispatch($delivery->id)->afterCommit();
        }
    }
}
