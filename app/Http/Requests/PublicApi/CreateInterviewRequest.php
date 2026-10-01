<?php

declare(strict_types=1);

namespace App\Http\Requests\PublicApi;

use App\Rules\PublicApi\Metadata;
use App\Support\Participant\ExternalReference;
use Illuminate\Foundation\Http\FormRequest;

// Internal notes, not published (Scramble exports a request class docblock as the
// schema description):
//
// `POST /v1/interviews` request validation (public-api step 5, SPEC.md §3.3
// "Create interview — request", `CreateInterviewRequest` schema).
//
// FORMAT only — `project_id`'s prefix/existence/tenancy, the project-active
// gate, the `exit_redirect_url` allowed-domain check and the duplicate-
// enrolment check all need the resolved `Project`/`Organization` and live
// in `App\Http\Controllers\PublicApi\InterviewController::store()` and
// `App\Actions\PublicApi\EnrolCandidate` — mirroring
// `App\Http\Controllers\PublicApi\ProjectController::show()`'s own "decode,
// never bind" discipline for a public-id path/body field (G-36).
//
// `authorize()` returns `true` unconditionally: scope enforcement is
// `App\Http\Middleware\PublicApi\RequireScope`'s job, applied per-route in
// `routes/api.php` (`scope:interviews:write`) — the SAME division of labour
// every other `/v1` write endpoint follows.
//
// Why the length caps below: `candidate.email` is capped at 255 (gga round 3
// finding 2) to match participants.email's own column width — without it a
// caller-supplied value long enough to overflow that column reaches the
// database as a truncation error (500) instead of the intended 422.
// `exit_redirect_url` is capped at 2048 (same review) to match the
// migration's own `varchar(2048)` column and the admin
// `StoreProjectRequest`/`UpdateProjectRequest` rule for the same-shaped field;
// https-only per SPEC.md §3.3 ("It must be https"), and `url` alone accepts
// http too, so the scheme is checked separately.
//
// `candidate.language` is ISO 639-1, matching `openapi.yaml`'s `Language`
// schema pattern exactly (`^[a-z]{2}$`) — no further allow-list check: the
// contract does not restrict it to a per-project or per-platform locale set,
// and this endpoint has no such catalogue to validate against (unlike
// `config/translatable.php`'s `supported_locales`, which is a BACKOFFICE UI
// concept).
/**
 * The body of `POST /v1/interviews`: the candidate to enrol, the project to
 * enrol them on, and optional metadata and redirect URL.
 */
final class CreateInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `candidate.external_id` / `candidate.source` are the optional external
     * reference (candidate-external-reference): the SAME rule set every other
     * write surface spreads, prefixed for the nested `candidate` object, so a
     * body is refused identically wherever it arrives. A refusal is the
     * ordinary `422 validation_failed` whose `errors[].field` names the nested
     * field. This prose lives here, not in the class docblock, because Scramble
     * publishes the class docblock as the schema description.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'string'],
            'candidate' => ['required', 'array'],
            'candidate.candidate_ref' => ['required', 'string', 'max:255'],
            // Valid email address, at most 255 characters.
            'candidate.email' => ['required', 'email', 'max:255'],
            'candidate.display_name' => ['required', 'string', 'max:255'],
            // ISO 639-1 language code: two lowercase letters, for example `it`.
            'candidate.language' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z]{2}$/'],
            ...ExternalReference::rules('candidate.'),
            'metadata' => ['sometimes', 'nullable', new Metadata],
            // Must be an https URL of at most 2048 characters.
            'exit_redirect_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048', 'starts_with:https://'],
        ];
    }
}
