<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Models\FrameworkDefaultQuestion;
use App\Models\User;
use App\Support\Superadmin\PlatformAuditWriter;

/**
 * Create one `FrameworkDefaultQuestion` row and record it in the platform
 * audit trail — extracted from `DefaultQuestionController::store()`
 * (feat/seed-default-questions) so that controller and
 * `catalogue:seed-default-questions` share exactly ONE write path rather
 * than the console command hand-rolling a second one. `FrameworkDefaultQuestion
 * ::withRevisionLockedForWrite()` is the SAME lock `DefaultQuestionController
 * ::update()`/`destroy()` and every other catalogue-content writer already
 * takes (`BumpsRevisionContentVersion`'s own docblock); `content_version`'s
 * bump fires from `FrameworkDefaultQuestion::create()` itself (the model's
 * own `saved` listener), not from anything this action does directly.
 */
final class CreateDefaultQuestion
{
    public function __construct(
        private readonly PlatformAuditWriter $auditWriter,
    ) {}

    /**
     * @param  array{revision_id: int, competency_id: int, text: array<string, string>, position: int}  $attributes
     */
    public function create(array $attributes, User $actor): FrameworkDefaultQuestion
    {
        return FrameworkDefaultQuestion::withRevisionLockedForWrite(
            $attributes['revision_id'],
            function () use ($attributes, $actor): FrameworkDefaultQuestion {
                $question = FrameworkDefaultQuestion::create($attributes);

                $this->auditWriter->record(
                    actorId: $actor->id,
                    action: 'catalogue.default_question.created',
                    subjectType: 'FrameworkDefaultQuestion',
                    subjectId: $question->id,
                    before: null,
                    after: $attributes,
                );

                return $question;
            },
        );
    }
}
