<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Unauthenticated liveness check for the BEAI Public API (`GET /v1/health`).
 *
 * SPEC.md §5.2 "Health: `/v1/health` (unauthenticated, no data) for client
 * monitors" and the vendored contract `public-api/openapi.yaml`'s `/health`
 * operation (`getHealth`), which declares ONLY a `200` response with the
 * literal body `{"status":"ok"}` — no auth, no DB, no other status code
 * (T-CONTRACT-003 pins that an undeclared status code is a contract
 * violation).
 *
 * Distinct from the existing `App\Http\Controllers\HealthController`
 * (`/api/health`): that one is the platform's OWN operational health probe
 * (fails loud on CORS misconfiguration) and is not part of this public
 * contract. This one never fails — it exists only so an external client's
 * monitor can confirm the API host answers, with no dependency to go down.
 */
class HealthController extends Controller
{
    /**
     * Return the literal, non-localized `{"status":"ok"}` body the contract requires.
     *
     * Machine-readable status payloads are exempt from the i18n mandate (D31,
     * wrapper CLAUDE.md "Machine-facing responses are not localized").
     */
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(['status' => 'ok'])
            ->header('Content-Type', 'application/json; charset=utf-8');
    }
}
