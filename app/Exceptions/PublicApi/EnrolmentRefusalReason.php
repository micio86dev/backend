<?php

declare(strict_types=1);

namespace App\Exceptions\PublicApi;

/**
 * The closed set of reasons `App\Actions\PublicApi\EnrolCandidate::handle()`
 * can refuse an enrolment (public-api step 5, SPEC.md §3.3).
 * `App\Http\Controllers\PublicApi\InterviewController::renderRefusal()` is
 * the ONLY translator from reason to HTTP status/`Problem` shape —
 * `DuplicateEmail` and `DuplicateCandidateRef` are kept as TWO distinct enum
 * cases (mirroring `App\Exceptions\Sso\EntryLinkRefusalReason`'s own
 * `Completed`/`Failed` split) because both the machine-readable `code` AND
 * the human-readable `detail` need to distinguish them: the `code` stays the
 * SAME contract value for both (`409 duplicate_enrolment`, SPEC.md §3.3 "A
 * second enrolment answers `409 duplicate_enrolment`"), but
 * `renderRefusal()` names the colliding FIELD in `detail`
 * ("candidate.email is already enrolled in this project." vs.
 * "candidate.candidate_ref is already enrolled in this project.") — a
 * caller integrating against this endpoint needs to know WHICH value to
 * change to retry. This is a DIFFERENT fact from SPEC.md's "no endpoint
 * reveals where else an address appears": that ruling is about never
 * disclosing whether an email exists in ANOTHER project or organization —
 * naming which field collided WITHIN THIS project, on a request the caller
 * itself just submitted, discloses nothing the caller did not already
 * supply.
 */
enum EnrolmentRefusalReason: string
{
    case ProjectNotActive = 'project_not_active';
    case DuplicateEmail = 'duplicate_email';
    case DuplicateCandidateRef = 'duplicate_candidate_ref';
    case RedirectUrlNotAllowed = 'redirect_url_not_allowed';
}
