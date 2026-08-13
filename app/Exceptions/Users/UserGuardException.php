<?php

declare(strict_types=1);

namespace App\Exceptions\Users;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown by UserGuards when a user-management write would violate an
 * organization invariant (backoffice-missing-pages D4).
 *
 * Carries a machine-readable `error` code — `last_admin` | `self_demotion` |
 * `self_deactivation` — rendered as HTTP 422, matching the machine-facing
 * (non-localized) error-body convention already used elsewhere
 * (CompositionException/AnchorTranslationMissingException in bootstrap/app.php).
 */
class UserGuardException extends Exception
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => $this->errorCode,
            'message' => $this->getMessage(),
        ], 422);
    }
}
