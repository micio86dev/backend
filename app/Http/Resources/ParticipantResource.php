<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * ParticipantResource (C6 — Participant + SSO Ingress).
 *
 * Serializes a Participant for API responses.
 *
 * Exposes safe candidate-facing fields only. Includes project context
 * (id, role_code, language, assessment_type) and exit_redirect_url
 * for candidate session responses.
 *
 * SECURITY INVARIANTS:
 * - organization_id is intentionally excluded from candidate-facing responses
 *   (no internal-id leak to candidates).
 * - key_hash, webhook_secret, and other internal fields are never exposed.
 *
 * REQ: Candidate Session Endpoint
 *
 * @mixin Participant
 */
class ParticipantResource extends JsonResource
{
    /**
     * Explicit whitelist — safe fields only. As `Admin\ParticipantResource`
     * (design.md D1) for `status`/`role_code`/`id`, plus the nested
     * `project.assessment_type` union (closed by the FormRequest + model
     * immutability guard, `Project.php:147-150`) and `project.id` `(int)`.
     *
     * @return array{id: int, candidate_ref: string, display_name: string, role_code: string|null, language: string|null, status: 'in_attesa'|'in_corso'|'in_valutazione'|'completato'|'errore', started_at: string|null, completed_at: string|null, created_at: string|null, project: array{id: int, role_code: string|null, language: string, assessment_type: 'standard'|'potential', exit_redirect_url: string|null, error_redirect_url: string|null}|null}
     *
     * @scramble-return array{id: int, candidate_ref: string, display_name: string, role_code: string|null, language: string|null, status: 'in_attesa'|'in_corso'|'in_valutazione'|'completato'|'errore', started_at: string|null, completed_at: string|null, created_at: string|null, project: array{id: int, role_code: string|null, language: string, assessment_type: 'standard'|'potential', exit_redirect_url: string|null, error_redirect_url: string|null}|null}
     */
    public function toArray(Request $request): array
    {
        /** @var Participant $participant */
        $participant = $this->resource;

        $project = $participant->project;

        return [
            'id' => (int) $participant->id,
            'candidate_ref' => $participant->candidate_ref,
            'display_name' => $participant->display_name,
            'role_code' => $participant->role_code,
            'language' => $participant->language,
            'status' => $participant->status,
            'started_at' => $participant->started_at?->toISOString(),
            'completed_at' => $participant->completed_at?->toISOString(),
            'created_at' => $participant->created_at->toISOString(),
            /*
             * Branding, and ONLY branding.
             *
             * The candidate is not a user of this organization and cannot call
             * `/api/organization` — that endpoint is admin-authenticated. So
             * the two values the page has to paint with ride along with the
             * bootstrap the app already makes, rather than needing a second
             * endpoint and a second round trip before anything can render.
             *
             * Exactly two keys. A candidate is an outsider holding a
             * short-lived token; the organization's name, webhook
             * configuration and every other setting are none of their
             * business, and the test asserts this key set exactly so a field
             * added to `OrganizationResource` later cannot leak here.
             *
             * Nulls are sent as nulls, never as a fabricated default: "no
             * colour" means the app leaves its own tokens alone, and sending
             * the Quint purple from here would put a second copy of that
             * constant on the wire for the two to drift apart.
             */
            'branding' => [
                'primary_color' => $participant->organization?->primary_color,
                'logo_url' => $participant->organization?->logo_path === null
                    ? null
                    : Storage::url($participant->organization->logo_path),
            ],
            'project' => $project ? [
                'id' => (int) $project->id,
                'role_code' => $project->role_code,
                'language' => $project->language,
                'assessment_type' => $project->assessment_type,
                'exit_redirect_url' => $project->exit_redirect_url,
                'error_redirect_url' => $project->error_redirect_url,
            ] : null,
        ];
    }
}
