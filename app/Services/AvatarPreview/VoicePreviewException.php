<?php

declare(strict_types=1);

namespace App\Services\AvatarPreview;

use RuntimeException;

/**
 * A voice preview could not be produced.
 *
 * `code` is one of the fixed `voice_preview_*` values below and is the ONLY
 * thing that reaches the client. The message is that same fixed code, never a
 * provider response body, so nothing catching this can leak a vendor error or
 * an API key by echoing it.
 */
final class VoicePreviewException extends RuntimeException
{
    public const UNAVAILABLE = 'voice_preview_unavailable';

    public const NOT_CONFIGURED = 'voice_preview_provider_not_configured';

    public const VOICE_NOT_FOUND = 'voice_preview_voice_not_found';

    public const PROVIDER_ERROR = 'voice_preview_provider_error';

    private const STATUS = [
        self::UNAVAILABLE => 422,
        self::NOT_CONFIGURED => 503,
        self::VOICE_NOT_FOUND => 404,
        self::PROVIDER_ERROR => 502,
    ];

    /**
     * @param  string|null  $reason  Optional machine sub-reason a UI can translate
     *                               (only for `voice_preview_unavailable`).
     */
    public function __construct(public readonly string $errorCode, public readonly ?string $reason = null)
    {
        parent::__construct($errorCode);
    }

    public function httpStatus(): int
    {
        return self::STATUS[$this->errorCode];
    }
}
