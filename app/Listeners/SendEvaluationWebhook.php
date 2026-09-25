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
use RuntimeException;
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
        // withoutGlobalScope('tenant') ONLY — never the plural withoutGlobalScopes()
        // (tests/Arch/C11/AdminTenancySafetyArchTest.php allowlist). No ambient
        // tenant context exists here: this listener runs synchronously off
        // ScoreEvaluationJob, before/without the request-scoped resolver.
        /** @var Evaluation $evaluation */
        $evaluation = Evaluation::withoutGlobalScope('tenant')->findOrFail($event->evaluationId);
        // Org-scoped (pre-commit gate, round 5, finding 1): `Participant` is a PLAIN
        // model, never auto-scoped — an unscoped findOrFail() here would happily
        // resolve a participant belonging to a DIFFERENT organization than this
        // Evaluation's own organization_id if the two ever disagreed (corrupt data,
        // a future bug elsewhere), and this read's own participant/project_id
        // decides which organization WebhookDeliveryRecorder writes the delivery
        // row under — BEFORE EvaluationPayloadAssembler's own (already org-scoped)
        // read ever runs. `$evaluation->organization_id` is already loaded above —
        // no extra query needed to close this.
        /** @var Participant $participant */
        $participant = Participant::where('organization_id', $evaluation->organization_id)->findOrFail($evaluation->participant_id);

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
        // $event->organizationId comes from ScoreEvaluationJob's own org
        // derivation at the EvaluationFailed dispatch site (see the event
        // class docblock), not re-derived here from this listener's own
        // Participant read — closing the round-5 finding where both values
        // traced back to the same unscoped row. It is NOT fully independent
        // of $event->participantId: when the job itself resolves the org via
        // Participant::withoutGlobalScopes()->find($this->participantId),
        // that is still the same participant id, just read once, inside the
        // job, instead of twice. Design D2 forbids putting the org directly
        // on the job's queue payload, so a genuinely independent source does
        // not exist on this path; this is the closest available one, and the
        // filter below still holds as defence in depth even though it cannot
        // fail against a job-resolved id.
        if ($event->organizationId === null) {
            // Only the genuine "cannot derive organization context" branch of
            // ScoreEvaluationJob::failed() leaves this null — no trustworthy org is
            // known anywhere, not even by the job itself. Recording a delivery
            // would require trusting the very read this fix exists to stop
            // trusting, so this is logged and swallowed by the caller's own
            // try/catch instead (mirrors the "forced exception" test's discipline).
            throw new RuntimeException(
                "SendEvaluationWebhook: EvaluationFailed for participant {$event->participantId} carries no organizationId — cannot safely org-scope the webhook read."
            );
        }

        /** @var Participant $participant */
        $participant = Participant::where('organization_id', $event->organizationId)->findOrFail($event->participantId);

        // EvaluationFailed carries no evaluation_id. If a processing-stage Evaluation
        // row happens to exist, dedupe on it (consistent with the completed path);
        // otherwise fall back to a participant-scoped key.
        // withoutGlobalScope('tenant') ONLY, same reasoning as handleCompleted()
        // above; $participant is already org-scoped so this lookup is bound to
        // one organization's rows regardless.
        $evaluation = Evaluation::withoutGlobalScope('tenant')->where('participant_id', $participant->id)->first();
        $dedupeKey = $evaluation !== null ? (string) $evaluation->id : 'participant-failed:'.$participant->id;

        $delivery = $this->recorder->record(
            $participant->project_id,
            $participant->id,
            WebhookEventType::Evaluation,
            $dedupeKey,
            // $participant->organization_id: already org-scoped above (the real
            // fix, not the round-4 circularity) — threaded through so the
            // assembler's own Participant read stays org-scoped too.
            fn (string $deliveryId): array => $this->assembler->assembleForFailedParticipant($participant->id, $participant->organization_id, $deliveryId)
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
