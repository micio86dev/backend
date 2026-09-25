<?php

declare(strict_types=1);

namespace App\Http\Requests\PublicApi;

use App\Rules\PublicApi\Metadata;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/interviews` request validation (public-api step 5, SPEC.md §3.3
 * "Create interview — request", `CreateInterviewRequest` schema).
 *
 * FORMAT only — `project_id`'s prefix/existence/tenancy, the project-active
 * gate, the `exit_redirect_url` allowed-domain check and the duplicate-
 * enrolment check all need the resolved `Project`/`Organization` and live
 * in `App\Http\Controllers\PublicApi\InterviewController::store()` and
 * `App\Actions\PublicApi\EnrolCandidate` — mirroring
 * `App\Http\Controllers\PublicApi\ProjectController::show()`'s own "decode,
 * never bind" discipline for a public-id path/body field (G-36).
 *
 * `authorize()` returns `true` unconditionally: scope enforcement is
 * `App\Http\Middleware\PublicApi\RequireScope`'s job, applied per-route in
 * `routes/api.php` (`scope:interviews:write`) — the SAME division of labour
 * every other `/v1` write endpoint follows.
 */
final class CreateInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'string'],
            'candidate' => ['required', 'array'],
            'candidate.candidate_ref' => ['required', 'string', 'max:255'],
            // max:255 (gga round 3 finding 2) — matches participants.email's
            // own column width; without it a caller-supplied value long
            // enough to overflow that column reaches the database as a
            // truncation error (500) instead of the intended 422 here.
            'candidate.email' => ['required', 'email', 'max:255'],
            'candidate.display_name' => ['required', 'string', 'max:255'],
            // ISO 639-1, matching `openapi.yaml`'s `Language` schema pattern
            // exactly (`^[a-z]{2}$`) — no further allow-list check: the
            // contract does not restrict `candidate.language` to a
            // per-project or per-platform locale set, and this endpoint has
            // no such catalogue to validate against (unlike
            // `config/translatable.php`'s `supported_locales`, which is a
            // BACKOFFICE UI concept).
            'candidate.language' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z]{2}$/'],
            'metadata' => ['sometimes', 'nullable', new Metadata],
            // https-only (SPEC.md §3.3 "It must be https") — `url` alone
            // accepts http too, so the scheme is checked separately.
            // max:2048 (gga round 3 finding 2) — matches the migration's own
            // `varchar(2048)` column width for this field, and the admin
            // `StoreProjectRequest`/`UpdateProjectRequest` validation rule
            // for the same-shaped `exit_redirect_url` field.
            'exit_redirect_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048', 'starts_with:https://'],
        ];
    }
}
