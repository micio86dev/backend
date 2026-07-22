<?php

declare(strict_types=1);

namespace App\Exceptions\Scoring;

/**
 * Thrown when no BARS indicators exist for the given competency.
 *
 * Triggers unscorable_reason = 'role_no_bars'.
 * No LLM call is made; no ai_requests row is produced.
 *
 * REQ: PromptBuilder — missing BARS catalog (C9 D6)
 */
final class RoleNoBarsException extends \RuntimeException
{
    public function __construct(string $competencyCode)
    {
        parent::__construct("No BARS indicators found for competency [{$competencyCode}].");
    }
}
