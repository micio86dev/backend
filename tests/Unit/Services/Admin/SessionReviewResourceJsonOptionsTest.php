<?php

declare(strict_types=1);

/**
 * gga review finding (MEDIUM): `EvaluationResource`/`DashboardMetricsResource`
 * both override `jsonOptions()` to preserve a BARS score's decimal
 * (`json_encode(3.0)` emits `3` without it), but `SessionReviewResource` —
 * the session-review surface this same evaluation data ALSO renders through
 * — did not. The SAME competency, scored 3.00, would render `3.0` on the
 * evaluation report and `3` on session review: exactly the disagreement
 * `SessionReviewResource`'s own class docblock says the two surfaces must
 * never have.
 *
 * Asserts on the ACTUAL encoded response body (gga review finding, second
 * pass) — a prior version asserted `jsonOptions()`'s return value plus a
 * hand-rolled `json_encode()` call that never touched this class at all
 * (it would pass with the class deleted). `toResponse()->getContent()` is
 * what proves the flag reaches the real encoder on the production path,
 * and specifically proves it reaches it BEFORE `withResponse()` would —
 * this class's own docblock's load-bearing claim.
 */

use App\Http\Resources\Admin\SessionReviewResource;
use App\Models\InterviewSession;
use Illuminate\Http\Request;

test('SessionReviewResource preserves a whole-number BARS score as a decimal on the wire', function (): void {
    $session = InterviewSession::factory()->make();

    $resource = new SessionReviewResource($session, [], [], null, null, [
        'competency_code' => 'COM',
        'score' => 3.0,
        'reliability' => '100%',
        'behaviors' => [],
        'unscorable_reason' => null,
    ]);

    $body = $resource->toResponse(Request::create('/'))->getContent();

    expect($body)->toContain('"score":3.0');
});
