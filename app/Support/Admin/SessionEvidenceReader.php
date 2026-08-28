<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\InterviewSession;
use App\Services\Admin\AdminEvaluationSerializer;
use App\Services\Scoring\ExcerptValidator;
use App\Services\Scoring\TranscriptAssembler;

/**
 * The BARS evidence for the competency ONE interview session probed, for the
 * admin session review.
 *
 * THE JOIN, AND WHY IT IS EXACT
 * -----------------------------
 * `indicator_scores` carries no `interview_session_id`, so the link is
 * composite. It is nonetheless a bijection, enforced by three database unique
 * constraints rather than by convention:
 *
 *   interview_sessions   UNIQUE (participant_id, competency_code)
 *   evaluations          UNIQUE (participant_id)
 *   competency_results   UNIQUE (evaluation_id, competency_code)
 *
 * So a session fixes exactly one `(participant_id, competency_code)` pair; that
 * participant has at most one evaluation; that evaluation has at most one
 * result for that competency. `competency_code` ALONE is emphatically NOT a
 * join key — it repeats across every participant in the tenant — which is why
 * the participant is carried through every step.
 *
 * WHAT THE JOIN DOES NOT PROVE, AND THE FLAG THAT SAYS SO
 * ------------------------------------------------------
 * It does not prove the excerpt was SPOKEN during this session.
 * `TranscriptAssembler` builds the validation corpus from the participant's
 * whole interview on purpose — "evidence a candidate gave while answering a
 * different competency's question is still evidence" — so a COL excerpt may
 * legitimately quote what was said in the PRS session. Rendering that under a
 * session page with no qualifier would tell an operator something the data does
 * not say. `excerpts_spoken_in_this_session` answers it positionally, computed
 * with `ExcerptValidator`'s OWN matcher against this session's candidate
 * corpus, so the read surface and the scorer can never disagree about what
 * "verbatim" means.
 *
 * READ GATE
 * ---------
 * Excerpts are part of the structured evaluation, so `ParticipantReadScope::
 * Evaluation` binds: `completato` only, `errore` never. The gate is asked as a
 * PREDICATE, not asserted, because the session review itself is a Summary read
 * — an in-flight candidate's session must stay reviewable. The gate decides
 * whether the block exists, never whether the route answers.
 *
 * Cross-tenant safety is inherited twice over: the caller resolves the session
 * through an explicit `organization_id` filter, and every model touched here
 * (Evaluation, CompetencyResult, IndicatorScore) is a TenantModel read under
 * the ambient scope.
 */
final class SessionEvidenceReader
{
    public function __construct(
        private readonly AdminEvaluationSerializer $serializer,
        private readonly TranscriptAssembler $transcripts,
        private readonly ExcerptValidator $excerpts,
        private readonly LifecycleReadGate $gate = new LifecycleReadGate,
    ) {}

    /**
     * @param  string  $participantStatus  The lifecycle status of the session's participant.
     * @return array{
     *     competency_code: string,
     *     score: float|null,
     *     reliability: string,
     *     behaviors: array<int, array<string, mixed>>,
     *     unscorable_reason: string|null
     * }|null
     */
    public function forSession(InterviewSession $session, string $participantStatus): ?array
    {
        if (! $this->gate->permits($participantStatus, ParticipantReadScope::Evaluation)) {
            return null;
        }

        $competency = $this->serializer->serializeCompetency(
            $session->participant_id,
            $session->competency_code,
        );

        if ($competency === null) {
            return null;
        }

        return [
            'competency_code' => $session->competency_code,
            'score' => $competency['score'],
            'reliability' => $competency['reliability'],
            'behaviors' => $this->withProvenance($competency['behaviors'], $session),
            'unscorable_reason' => $competency['unscorable_reason'],
        ];
    }

    /**
     * Adds `excerpts_spoken_in_this_session` — a positional parallel of
     * `excerpts`, same length, same order.
     *
     * A parallel list rather than a list of objects: `excerpts` is already a
     * published shape (`AdminEvaluationSerializer`, the webhook payload, the
     * sample report), and rewrapping each string into `{text, spoken_here}`
     * here would make the session view's excerpt shape differ from every other
     * surface's for the sake of one boolean.
     *
     * The corpus is assembled ONCE per session, not per excerpt.
     *
     * @param  array<int, array<string, mixed>>  $behaviors
     * @return array<int, array<string, mixed>>
     */
    private function withProvenance(array $behaviors, InterviewSession $session): array
    {
        $corpus = $this->transcripts->candidateCorpusForSession($session);

        return array_values(array_map(function (array $behavior) use ($corpus): array {
            /** @var array<int, string> $excerpts */
            $excerpts = is_array($behavior['excerpts'] ?? null) ? $behavior['excerpts'] : [];

            $behavior['excerpts_spoken_in_this_session'] = array_values(array_map(
                fn (string $excerpt): bool => $this->excerpts->matchesCorpus($excerpt, $corpus),
                $excerpts,
            ));

            return $behavior;
        }, $behaviors));
    }
}
