<?php

declare(strict_types=1);

namespace App\Exceptions\ConversationLlm;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown by `AvatarTemplate::booted()` (I2) when a template is bound to a
 * model whose capability resolves to `native_duplex`
 * (pluggable-conversation-llm PR P3a, design D4/D11).
 *
 * `native_duplex` is registered and priced today but refused at EVERY write
 * path — `create`, `update`, and the portability import's
 * `forceFill()->save()` — because `LlmCapability::mode()` is an exhaustive
 * match with no default arm and I2 is enforced on the model's `saving`
 * event, the one hook `forceFill()` cannot dodge.
 *
 * Registered in `bootstrap/app.php` beside `UserGuardException`, same
 * machine-readable 422 shape.
 */
class UnsupportedLlmModeException extends Exception
{
    public function __construct(
        private readonly string $field = 'llm_model_id',
    ) {
        parent::__construct('The bound model does not support managed mode.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'The given data was invalid.',
            'errors' => [$this->field => ['mode_unsupported']],
        ], 422);
    }
}
