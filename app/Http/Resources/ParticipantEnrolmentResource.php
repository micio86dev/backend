<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ParticipantEnrolmentResource (candidate-external-reference).
 *
 * Serializes a Participant for the OPERATOR and INTEGRATION responses: the M2M
 * participant endpoints (create on both paths, index, show, reschedule,
 * cancel), the scheduled branch of `POST /api/entry-links`, and the backoffice
 * reschedule/cancel actions.
 *
 * It is the candidate-facing `ParticipantResource` shape plus the calling
 * system's own identifiers: `external_id` (integer or null) and `source`
 * (string or null), both ALWAYS present. The two resources are separate
 * classes on purpose. The candidate is an outsider holding a short-lived token
 * and has no use for another system's identifiers, so `/api/candidate/session`
 * keeps `ParticipantResource` byte-for-byte; a single resource that branched on
 * the caller would make the next field added "for operators" reach candidates
 * by default. `ParticipantArchTest` pins `ParticipantResource` to the candidate
 * session controller and to this class.
 *
 * Composed from `ParticipantResource::toArray()` rather than copied, so the
 * shared fields cannot drift apart; the two new keys are appended after them.
 *
 * REQ: M2M Participant Create Accepts And Returns The External Reference,
 *      Operator Entry Link Persists The External Reference
 *      (sdd/candidate-external-reference/spec/participant-sso)
 *
 * @mixin Participant
 */
final class ParticipantEnrolmentResource extends JsonResource
{
    /**
     * The `ParticipantResource` shape plus `external_id` and `source`.
     *
     * @return array{id: int, candidate_ref: string, display_name: string, role_code: string|null, language: string|null, status: 'in_attesa'|'in_corso'|'in_valutazione'|'completato'|'errore', started_at: string|null, completed_at: string|null, created_at: string|null, scheduled_at: string|null, scheduling_status: 'pending'|'notice_sent'|'started'|'cancelled'|null, branding: array{name: string|null, primary_color: string|null, logo_url: string|null}, project: array{id: int, role_code: string|null, language: string, assessment_type: 'standard'|'potential', exit_redirect_url: string|null, error_redirect_url: string|null}|null, external_id: int|null, source: string|null}
     *
     * @scramble-return array{id: int, candidate_ref: string, display_name: string, role_code: string|null, language: string|null, status: 'in_attesa'|'in_corso'|'in_valutazione'|'completato'|'errore', started_at: string|null, completed_at: string|null, created_at: string|null, scheduled_at: string|null, scheduling_status: 'pending'|'notice_sent'|'started'|'cancelled'|null, branding: array{name: string|null, primary_color: string|null, logo_url: string|null}, project: array{id: int, role_code: string|null, language: string, assessment_type: 'standard'|'potential', exit_redirect_url: string|null, error_redirect_url: string|null}|null, external_id: int|null, source: string|null}
     */
    public function toArray(Request $request): array
    {
        /** @var Participant $participant */
        $participant = $this->resource;

        return [
            ...(new ParticipantResource($participant))->toArray($request),
            'external_id' => $participant->external_id,
            'source' => $participant->source,
        ];
    }
}
