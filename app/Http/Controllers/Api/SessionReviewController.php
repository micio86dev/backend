<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\SessionReviewResource;
use App\Http\Resources\Admin\SessionSummaryResource;
use App\Models\IntegrityEvent;
use App\Models\InterviewSession;
use App\Models\InterviewSnapshot;
use App\Services\Proctoring\IntegritySummarizer;
use App\Services\Proctoring\SessionCostEstimator;
use App\Support\Admin\AdminParticipantReader;
use App\Support\Admin\ParticipantReadScope;
use App\Support\Admin\SessionEvidenceReader;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

/**
 * Admin review of interview sessions (C11 review surface).
 *
 * BEAI has written integrity events and webcam snapshots on every interview
 * since C7a and never exposed them. This is the read side, and it is
 * BACKOFFICE-ONLY by design (D2): the integrity taxonomy is a list of the
 * behaviours being counted and the thresholds at which they count, so
 * disclosing it to the person being measured defeats the measurement.
 * `tests/Arch/C11/CandidateCannotReadProctoringArchTest.php` keeps that true.
 *
 * Tenancy: sessions are resolved through an explicit org filter, never a bare
 * `InterviewSession::find()`. A cross-org id 404s rather than 403s — a 403
 * would confirm the id exists somewhere.
 *
 * REQ: Admin session review
 */
final class SessionReviewController extends Controller
{
    public function __construct(
        private readonly AdminParticipantReader $reader,
        private readonly TenantResolver $resolver,
        private readonly SessionCostEstimator $cost,
        private readonly SessionEvidenceReader $evidence,
    ) {}

    /**
     * The sessions of one participant, newest first.
     *
     * GET /api/participants/{participant}/sessions
     */
    public function index(int $participant): AnonymousResourceCollection
    {
        // Authorizes the PARTICIPANT first: a session list is a view of that
        // participant, so it must not be reachable for one the caller cannot
        // read. `read()` applies the org filter, then RBAC.
        $this->reader->read($participant, ParticipantReadScope::Summary);

        $sessions = InterviewSession::query()
            ->where('organization_id', $this->resolver->getOrgId())
            ->where('participant_id', $participant)
            ->withCount('integrityEvents')
            // (interview-session-started-at, D3) eager-loaded so
            // SessionSummaryResource's liveSeconds() never issues an N+1.
            // llmUsage: same reasoning, for the LLM cost line (P6b).
            ->with(['livePeriods', 'llmUsage'])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();

        return SessionSummaryResource::collection($sessions);
    }

    /**
     * One session, with its evidence.
     *
     * GET /api/interview-sessions/{session}/review
     */
    public function show(int $session): JsonResponse
    {
        $interviewSession = InterviewSession::query()
            ->where('organization_id', $this->resolver->getOrgId())
            ->with(['integrityEvents', 'interviewSnapshots', 'livePeriods', 'llmUsage'])
            ->findOrFail($session);

        // Authorization rides on the participant, so the session review can
        // never be readable by someone who cannot read the candidate it belongs
        // to. Roles are checked AFTER the org filter, so a cross-org id 404s
        // before any role is evaluated.
        //
        // Deliberately still `Summary`: the BARS evidence attached below is
        // Evaluation-scoped, but it is gated as a BLOCK inside
        // SessionEvidenceReader, not by raising the whole route. Raising it
        // would 409 the session review of an in-flight candidate — the review's
        // primary use — to protect a block that simply would not be there yet.
        $participant = $this->reader->read($interviewSession->participant_id, ParticipantReadScope::Summary);

        $events = $interviewSession->integrityEvents
            ->sortBy([['ts', 'asc'], ['id', 'asc']])
            ->values();

        $summary = IntegritySummarizer::summarize(
            array_values($events->map(fn (IntegrityEvent $e): array => [
                'kind' => $e->kind,
                'payload' => $e->payload,
            ])->all())
        );

        // The events travel WITH the score, never instead of it (D3/spec): a
        // band an operator cannot check against its evidence is a verdict.
        $summary['events'] = array_values($events->map(fn (IntegrityEvent $e): array => [
            'kind' => $e->kind,
            'ts' => $e->ts->toIso8601String(),
            'payload' => $e->payload,
        ])->all());

        return (new SessionReviewResource(
            $interviewSession,
            $summary,
            $this->signedSnapshots($interviewSession),
            $this->cost->estimate($interviewSession),
            $interviewSession->llmUsage,
            $this->evidence->forSession($interviewSession, $participant->status),
        ))->response();
    }

    /**
     * Snapshots as short-lived signed URLs (D4).
     *
     * `s3_key` never leaves the server. A raw key implies either a public
     * bucket holding identifiable webcam frames or a disclosed storage layout;
     * the expiry keeps a copied URL from outliving the operator's session.
     *
     * @return list<array{url: string, taken_at: string}>
     */
    private function signedSnapshots(InterviewSession $session): array
    {
        // No argument: resolves the same disk the writer and purge use (D1).
        $disk = Storage::disk();

        return array_values($session->interviewSnapshots
            ->sortBy([['taken_at', 'asc'], ['id', 'asc']])
            ->map(fn (InterviewSnapshot $snapshot): array => [
                'url' => $disk->temporaryUrl($snapshot->s3_key, now()->addMinutes(15)),
                'taken_at' => $snapshot->taken_at->toIso8601String(),
            ])
            ->all());
    }
}
