<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A HeyGen external voice could not be bound for a reason that is NOT about the
 * voice the operator chose: BEAI's own provider or vendor key is not set, a
 * concurrent save holds the bind lock, or LiveAvatar lost the vendor secret.
 *
 * It is deliberately not a validation error. A 422 on `config.ttsExternalVoiceId`
 * tells the operator to change the voice, which cannot help. The body follows the
 * voice-preview convention (`{message: <stable code>}`, never a provider body),
 * with the same classes: 503 when the platform is not configured or the condition
 * is transient, 502 when the provider failed.
 */
final class HeygenVoiceBindUnavailableException extends RuntimeException
{
    /** Registrar failure code => HTTP status. Every code stays the one the registrar returns. */
    public const STATUS = [
        'tts_provider_unconfigured' => 503,
        'tts_vendor_key_missing' => 503,
        'tts_bind_busy' => 503,
        'tts_secret_failed' => 502,
    ];

    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }

    public static function handles(string $code): bool
    {
        return array_key_exists($code, self::STATUS);
    }

    public function httpStatus(): int
    {
        return self::STATUS[$this->errorCode];
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->errorCode], $this->httpStatus());
    }
}
