<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\Models\InterviewSession;

/**
 * Assembles the session transcript from utterances for a given InterviewSession.
 *
 * Ordering (CW3 — determinism-critical):
 *   orderBy('ts')->orderBy('id') — dual sort required.
 *   ts alone is NOT guaranteed unique: HeyGen bulk-replace can produce utterances
 *   with identical timestamps; the autoincrement id is the stable secondary sort.
 *
 * Serialization format:
 *   "{speaker}: {text}" per utterance, joined by \n.
 *
 * This same string MUST be passed to both:
 *   1. The LLM prompt (user message in PromptBuilder).
 *   2. ExcerptValidator for substring validation.
 *
 * REQ: TranscriptAssembler (C9 D3 CW3)
 */
final class TranscriptAssembler
{
    /**
     * Assemble the transcript for the given session.
     *
     * @param  InterviewSession  $session  The session to assemble utterances from.
     * @return string The assembled transcript string.
     */
    public function assemble(InterviewSession $session): string
    {
        $utterances = $session->utterances()
            ->withoutGlobalScopes()
            ->orderBy('ts')
            ->orderBy('id')
            ->get();

        $lines = $utterances->map(
            static fn ($u): string => "{$u->speaker}: {$u->text}"
        );

        return $lines->implode("\n");
    }
}
