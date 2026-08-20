<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Http\CorsAllowlistStatus;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Return a machine-readable health status.
     *
     * This endpoint is NOT localized — it returns the literal string "ok" in every locale.
     * Machine-readable status payloads are exempt from the i18n mandate (D31).
     */
    public function __invoke(): JsonResponse
    {
        // deploy incident 2026-08-20 — see App\Support\Http\CorsAllowlistStatus
        // for why this is the fail-loud surface: an empty CORS allowlist
        // outside local/testing silently disabled cross-origin access to the
        // whole API, and this is the ONLY signal Docker's HEALTHCHECK
        // (Dockerfile) and Railway's platform monitor ever see.
        if (CorsAllowlistStatus::isMisconfigured()) {
            return response()->json([
                'status' => 'down',
                'reason' => 'cors_allowed_origins_empty',
            ], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
