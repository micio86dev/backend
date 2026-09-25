<?php

declare(strict_types=1);

namespace App\PublicApi\Serializers;

use App\Models\Participant;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Support\PublicApi\PublicId;
use App\Support\PublicApi\WebhookDeliveryId;

/**
 * Public-safe `WebhookDelivery` shape for the BEAI Public API (`/v1`) —
 * SPEC.md §3.6 "Delivery log", `public-api/openapi.yaml`'s `WebhookDelivery`
 * schema, exercised by `GET /v1/webhooks/deliveries` and
 * `POST /v1/webhooks/deliveries/{id}/redeliver`.
 *
 * SPEC.md §3.6 names the exposed set explicitly: "Exposed: `event_type,
 * status, interview_id, project_id, candidate_ref, target_url,
 * attempt_count, max_attempts, payload_version, last_response_status,
 * last_attempt_at, next_attempt_at, delivered_at, created_at`. Not exposed:
 * `payload`, `dedupe_key`, `skip_reason`, `last_error`." No admin-facing
 * resource exists for this model to diff against (unlike every other `/v1`
 * serializer, whose exclusion/addition list `Tests\Helpers\PublicApi\
 * ExposureCatalogue` freezes against an admin equivalent) — every field
 * below is instead justified directly against this SPEC.md sentence and
 * the model's own columns, never against an admin comparison. `last_error`
 * (the receiver-facing HTTP error/exception text, already redacted and
 * truncated for the AUDIT TRAIL by `App\Services\Webhooks\SecretRedactor`)
 * is deliberately never surfaced through this read-only public log at all —
 * the original step 7 brief proposed a `last_response_excerpt` field
 * derived from it, which SPEC.md's own binding exclusion list rules out;
 * reported as a correction rather than silently implemented against the
 * brief.
 *
 * `$delivery->project`/`$delivery->participant` MUST be eager-loaded by
 * the caller — never lazy-loaded here, mirroring every other `/v1`
 * serializer's own "no N+1 per row" discipline (see
 * `InterviewController::index()`'s own docblock).
 */
final class WebhookDeliverySerializer
{
    /**
     * @return array{id: string, event_type: string, status: string, interview_id: string, project_id: string, candidate_ref: string, target_url: string|null, attempt_count: int, max_attempts: int, payload_version: string, last_response_status: int|null, last_attempt_at: string|null, next_attempt_at: string|null, delivered_at: string|null, created_at: string}
     */
    public static function toArray(WebhookDelivery $delivery): array
    {
        /** @var Participant $participant */
        $participant = $delivery->participant;
        /** @var Project $project */
        $project = $delivery->project;

        return [
            'id' => WebhookDeliveryId::encode($delivery),
            'event_type' => $delivery->event_type->value,
            'status' => $delivery->status->value,
            'interview_id' => PublicId::encode($participant),
            'project_id' => PublicId::encode($project),
            'candidate_ref' => $participant->candidate_ref,
            'target_url' => $delivery->target_url,
            'attempt_count' => $delivery->attempt_count,
            'max_attempts' => $delivery->max_attempts,
            'payload_version' => $delivery->payload_version,
            'last_response_status' => $delivery->last_response_status,
            'last_attempt_at' => $delivery->last_attempt_at?->toIso8601String(),
            'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'created_at' => $delivery->created_at->toIso8601String(),
        ];
    }
}
