<?php

declare(strict_types=1);

namespace App\DTOs\Conversation;

/**
 * The combined, segmented context for one multi-competency conversation
 * (tavus-single-session-interview API-02, design D1/N3).
 *
 * `$version` is the one configured prompt version for the whole text and
 * `$promptSetRef` the one stored set every segment came from (null for the baseline), so the
 * durable stamp is `QuestionContext::stampedPromptVersion()`'s `{version}+{ref}`.
 */
final readonly class ConversationPlan
{
    /**
     * @param  list<string>  $coveredCodes  Codes of the segments in `$text`, in order.
     * @param  bool  $truncated  True when fewer entries were covered than were given.
     * @param  int  $chars  `mb_strlen($text)`, the measure `max_context_chars` bounds.
     * @param  list<array{code: string, primary_questions: list<string>, follow_up_budget: int}>  $competencies
     *                                                                                                           The covered entries as `interview_sessions.conversation_plan` stores them. Never an anchor.
     */
    public function __construct(
        public string $text,
        public string $version,
        public ?string $promptSetRef,
        public array $coveredCodes,
        public bool $truncated,
        public int $chars,
        public array $competencies = [],
    ) {}

    /**
     * The frozen plan stored on the creating row (design N2): codes, authored primary
     * questions, follow-up budgets and the length. It holds no anchor, indicator or prompt text.
     *
     * @return array{competencies: list<array{code: string, primary_questions: list<string>, follow_up_budget: int}>, chars: int}
     */
    public function stored(): array
    {
        return ['competencies' => $this->competencies, 'chars' => $this->chars];
    }
}
