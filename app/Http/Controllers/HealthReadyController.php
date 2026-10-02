<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Health\SchemaStatus;
use Illuminate\Http\JsonResponse;

class HealthReadyController extends Controller
{
    // Internal notes, not published (Scramble exports docblock prose as public text):
    // Readiness, as opposed to liveness (HealthController, deliberately DB-free).
    // The reasons are machine-facing constants, never localized (D31), and never
    // carry migration names, SQL, host names or exception text.
    /**
     * Check that the platform is ready to serve.
     *
     * Answers `200` with `{"status":"ok"}` when the database is reachable and its schema is
     * current, or `503` with `{"status":"down","reason":"..."}` where the reason is
     * `pending_migrations` or `database_unavailable`. The payload is machine-readable and is
     * not localized.
     */
    public function __invoke(SchemaStatus $schema): JsonResponse
    {
        $reason = $schema->reason();

        if ($reason !== null) {
            return response()->json(['status' => 'down', 'reason' => $reason], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
