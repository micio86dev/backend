<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
        return response()->json(['status' => 'ok']);
    }
}
