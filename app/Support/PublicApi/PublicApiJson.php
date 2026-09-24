<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use Illuminate\Http\JsonResponse;

/**
 * `response()->json($data, $status, [], JSON_PRESERVE_ZERO_FRACTION)` — one
 * shared responder for every BEAI Public API (`/v1`) JSON body that CAN
 * carry a float (gga finding 2, step 6 review): `Scoring.competencies.
 * {code}.{score,reliability}` and `Answer.{started_at_seconds,
 * answer_duration_seconds}`.
 *
 * Without the flag, PHP's own `json_encode()` renders a whole-number float
 * as a bare integer (`json_encode(1.0) === "1"`) — indistinguishable, on
 * the wire, from a genuinely integer-typed field. `App\Http\Resources\
 * Admin\EvaluationResource::jsonOptions()` already applies the identical
 * flag for the SAME reason (`score: 4.0`, never `4`, per the reference
 * shape `evaluation-report-example.json`) — this class is the equivalent
 * for every `/v1` response built with a plain `response()->json(...)` call
 * rather than a `JsonResource` (which would otherwise need its own
 * `jsonOptions()` override per class instead of one shared call site).
 *
 * Used even where a given response currently carries no float value
 * (`Transcript`, the `events` page) — a deliberately uniform call site
 * across every `/v1` controller action, so a future field addition that
 * DOES introduce a float never has to remember to opt in.
 */
final class PublicApiJson
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
