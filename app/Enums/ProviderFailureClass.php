<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a HeyGen/Tavus provider call failed (PR1 — diagnosability, delta spec D4).
 *
 * A three-way split of what was, before this change, a binary retryable/hard-failure
 * flag on `ProviderException`. The binary flag folded every non-429 status — including
 * a 4xx caused by OUR OWN malformed request — into the same bucket as a genuine 5xx,
 * which meant a payload bug on our side returned 502 (blaming the provider) and
 * permanently burned the candidate's participant status to `errore` (terminal).
 *
 * `InterviewController::handleProviderFailure()` is the ONLY place this class is
 * switched on to decide HTTP status + session/participant writes.
 */
enum ProviderFailureClass: string
{
    /** HTTP 429 — rate-limited. Session stays pending; participant untouched. */
    case Throttle = 'throttle';

    /**
     * Any other 4xx (400, 401, 403, 404, 409, 415, 422, ...) — the provider correctly
     * rejected a request WE malformed. Not the provider's fault; participant untouched.
     */
    case ClientError = 'client_error';

    /** 5xx, timeout, or a malformed/unparseable success body — a genuine provider failure. */
    case Upstream = 'upstream';
}
