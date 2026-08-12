<?php

declare(strict_types=1);

namespace App\Support\Admin;

/**
 * The whitelisted filter set for the evaluations index/summary surface
 * (backoffice-missing-pages D6/D7). Immutable value object built from an
 * already-validated `EvaluationIndexRequest` — nothing here reads raw
 * request input, so `EvaluationIndexQuery::build()` can never receive an
 * unvalidated value.
 */
final class EvaluationIndexFilters
{
    public function __construct(
        public readonly ?int $projectId = null,
        public readonly ?string $assessmentType = null,
        public readonly ?string $roleCode = null,
        public readonly ?string $status = null,
        public readonly ?string $evaluatedFrom = null,
        public readonly ?string $evaluatedTo = null,
    ) {}
}
