<?php

declare(strict_types=1);

namespace App\Services\Provider;

/**
 * Context passed to ProviderSessionService::issue() for provider session initialization.
 *
 * Carries the competency and question index so the provider can contextualize the session.
 * The full question text and greeting are C7b's contract; C7a passes the minimal identifiers.
 *
 * REQ: QuestionContext DTO (C7a)
 */
readonly class QuestionContext
{
    public function __construct(
        public string $competencyCode,
        public int    $questionIndex,
    ) {}
}
