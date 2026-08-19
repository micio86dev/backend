<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ProviderFailureClass;

/**
 * Thrown when a provider's transcript response 2xx-succeeds but does not match
 * the real wire shape (PR4, design D7 — "fail loud on drift, soft on transport").
 *
 * Distinguishes a genuine CONTRACT DRIFT from a genuinely empty transcript:
 *   - HTTP non-2xx                              → soft (unchanged): `Log::warning`, return `[]`.
 *   - 2xx, `data.transcript_data` present + `[]` → soft: genuinely no transcript yet, return `[]`.
 *   - 2xx, `data.transcript_data` absent/not-array,
 *     or a row missing `role`/`transcript`,
 *     or `transcript` non-string,
 *     or an unrecognized `role`                 → THROW (this exception).
 *
 * Classified `Upstream` (never `ClientError`): a shape mismatch is the PROVIDER
 * returning something we did not agree on — not a request WE malformed. Maps to
 * HTTP 502 if surfaced through `InterviewController::handleProviderFailure()`'s
 * classification switch; `end()` returns 502 directly (see F1 fix) since the
 * transcript reconciliation exception is caught at the `/end` level, not routed
 * through `handleProviderFailure()` (which is a `/start`-only helper).
 *
 * CRITICAL (F1): this exception is thrown BEFORE `InterviewController::replaceUtterances()`
 * runs, and propagates out of the `/end` explicit `DB::transaction()` closure —
 * Laravel rolls the whole transaction back automatically, so the DELETE never
 * commits and already-persisted utterances survive.
 *
 * REQ: Transcript Parsing from the Real Provider Response Shape (delta spec, interview-session)
 * REQ: ProviderTranscriptShapeException (PR4 task 4.4 — design D7)
 */
final class ProviderTranscriptShapeException extends ProviderException
{
    public function __construct(string $message)
    {
        parent::__construct($message, ProviderFailureClass::Upstream);
    }
}
