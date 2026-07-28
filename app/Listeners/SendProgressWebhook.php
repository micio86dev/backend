<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Events\CompetencySessionEnded;
use App\Events\ParticipantCreated;
use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\ProgressPayloadAssembler;
use App\Services\Webhooks\WebhookDeliveryRecorder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SendProgressWebhook — bridges the two D5 progress seams (SSO exchange, `/end`) to
 * the C10 delivery pipeline (design.md D4, task 13.4). Mirrors
 * App\Listeners\SendEvaluationWebhook exactly — same plain/try-catch/dispatch-if
 * -pending shape, same auto-discovery via a single union-typed `handle()` (verified
 * against `Illuminate\Reflection\Reflector::getParameterClassNames()` in PR5).
 *
 * dedupe_key (spec: "For progress events, dedupe_key MUST be derived from the
 * participant and the triggering boundary"):
 *   - creation:        "participant-created:{participantId}"
 *   - competency end:  "competency-ended:{participantId}:{competencyCode}"
 *
 * REQ: SendProgressWebhook listener (C10 D4/D5)
 */
class SendProgressWebhook
{
    public function __construct(
        private readonly ProgressPayloadAssembler $assembler,
        private readonly WebhookDeliveryRecorder $recorder,
    ) {}

    public function handle(ParticipantCreated|CompetencySessionEnded $event): void
    {
        try {
            if ($event instanceof ParticipantCreated) {
                $this->handleCreated($event);
            } else {
                $this->handleCompetencyEnded($event);
            }
        } catch (Throwable $e) {
            Log::error('SendProgressWebhook: failed to record/dispatch delivery', [
                'event' => $event::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleCreated(ParticipantCreated $event): void
    {
        $delivery = $this->recorder->record(
            $event->projectId,
            $event->participantId,
            WebhookEventType::Progress,
            'participant-created:'.$event->participantId,
            fn (string $deliveryId): array => $this->assembler->assemble($event->participantId, $deliveryId)
        );

        $this->dispatchIfPending($delivery);
    }

    private function handleCompetencyEnded(CompetencySessionEnded $event): void
    {
        $delivery = $this->recorder->record(
            $event->projectId,
            $event->participantId,
            WebhookEventType::Progress,
            'competency-ended:'.$event->participantId.':'.$event->competencyCode,
            fn (string $deliveryId): array => $this->assembler->assemble($event->participantId, $deliveryId)
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
