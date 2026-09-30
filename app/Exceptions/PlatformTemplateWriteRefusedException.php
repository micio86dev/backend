<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

/**
 * A write crossed the platform/organization boundary of an avatar template.
 *
 * Not rendered for the user: reaching it means a code path wrote a platform row
 * outside the platform context, or an organization row inside it. That is a
 * defect to log and fix, never input to explain back to a caller.
 */
final class PlatformTemplateWriteRefusedException extends LogicException
{
    public function __construct(string $action)
    {
        parent::__construct("Refused to {$action} an avatar template across the platform/organization boundary.");
    }
}
