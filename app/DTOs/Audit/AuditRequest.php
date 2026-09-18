<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

/**
 * One judge() call's worth of state: a single competency's judgeable
 * indicators, batched to mirror `PromptBuilder`'s own batching shape
 * (proposal assumption 3, design D2).
 */
final readonly class AuditRequest
{
    /** @param  list<AuditSubject>  $subjects */
    public function __construct(
        public string $competencyCode,
        public array $subjects,
    ) {}
}
