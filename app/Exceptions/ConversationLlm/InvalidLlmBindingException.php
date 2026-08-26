<?php

declare(strict_types=1);

namespace App\Exceptions\ConversationLlm;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown by `AvatarTemplate::booted()` for I3 (`credential_not_found`) and
 * I4 (`vendor_mismatch`) (pluggable-conversation-llm PR P3a, design D4).
 *
 * `credential_not_found` is used for BOTH "no such credential" and "someone
 * else's credential" — deliberately, so the response is not an existence
 * oracle (the same 404-not-403 doctrine D9 states elsewhere, applied to a
 * 422 body here).
 */
class InvalidLlmBindingException extends Exception
{
    public function __construct(
        private readonly string $field,
        private readonly string $errorCode,
    ) {
        parent::__construct("Invalid LLM binding on [{$field}]: {$errorCode}");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'The given data was invalid.',
            'errors' => [$this->field => [$this->errorCode]],
        ], 422);
    }
}
