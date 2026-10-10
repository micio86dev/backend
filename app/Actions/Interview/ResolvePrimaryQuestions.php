<?php

declare(strict_types=1);

namespace App\Actions\Interview;

use App\Models\Project;
use App\Models\ProjectQuestion;
use Illuminate\Support\Collection;

/**
 * ResolvePrimaryQuestions: a MOVE of `InterviewController::primaryQuestionsFor()`
 * (tavus-single-session-interview API-03b), so the single-competency path and the
 * multi-competency plan read the operator's primaries one way.
 */
final class ResolvePrimaryQuestions
{
    /**
     * The competency's complete primary-question set — the operator's own
     * `project_questions` rows for this project × competency, localized
     * (framework-catalogue-authoring PR7, D7 — renamed from
     * `authoredQuestionsFor()`: these rows ARE the primaries, not questions
     * additional to a separately-sized budget).
     *
     * `project_questions` has been writable from the backoffice since C4 and
     * was read by NOTHING: the model, its controller, its request and its
     * resource referenced each other and no part of the interview referenced
     * any of them. An operator could author the exact question they needed
     * asked, watch it save, and then listen to the avatar improvise something
     * else. This is the read that was missing.
     *
     * Ordered by `position`, because an operator writing a second question as
     * a follow-up to the first means it to come second.
     *
     * LOCALE RESOLUTION: the PROJECT's language, falling back to the platform
     * default and then to whatever single translation exists. A question the
     * operator wrote only in Italian is still the question they want asked;
     * dropping it because the project is `en` would silently return the
     * write-only behaviour for exactly the operators most likely to hit it.
     *
     * @return list<string>
     */
    public function handle(Project $project, int $competencyId): array
    {
        $fallback = (string) config('app.fallback_locale', 'en');
        $locale = $project->language ?? $fallback;

        return ProjectQuestion::where('project_id', $project->id)
            ->where('competency_id', $competencyId)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('text')
            ->map(function (mixed $text) use ($locale, $fallback): string {
                if (! is_array($text)) {
                    return '';
                }

                $resolved = $text[$locale] ?? $text[$fallback] ?? null;

                if (! is_string($resolved)) {
                    // Last resort: the first non-empty translation there is.
                    $resolved = collect($text)->first(
                        static fn (mixed $value): bool => is_string($value) && trim($value) !== ''
                    );
                }

                return is_string($resolved) ? trim($resolved) : '';
            })
            ->filter(static fn (string $question): bool => $question !== '')
            ->values()
            // Typed, so the list-ness is proven rather than asserted: the
            // composer's boundary is `list<string>`, and `filter()` does not
            // promise a list through its signature.
            ->pipe(static fn (Collection $questions): array => array_values($questions->all()));
    }
}
