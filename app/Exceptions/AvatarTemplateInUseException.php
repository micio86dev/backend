<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses to remove a template that live projects still point at.
 *
 * `projects.avatar_template_id` is NOT NULL with `restrictOnDelete`, and that
 * foreign key used to be the whole guard: a delete simply could not happen.
 * Soft-deleting the template made the delete an UPDATE of `deleted_at`, which
 * no foreign key inspects — so the database backstop now only covers a FORCE
 * delete, and the soft path needed a guard of its own.
 *
 * Without it, removing a template would leave every project that uses it
 * pointing at a row the tenant can no longer see: the project stays valid at
 * the database and unusable in the product, which is the worst of both.
 */
final class AvatarTemplateInUseException extends RuntimeException
{
    public function __construct(
        public readonly int $projectCount,
        public readonly int $organizationCount = 0,
    ) {
        parent::__construct('avatar_template_in_use');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'template_in_use',
            'message' => 'template_in_use',
            'project_count' => $this->projectCount,
            'organization_count' => $this->organizationCount,
        ], Response::HTTP_CONFLICT);
    }
}
