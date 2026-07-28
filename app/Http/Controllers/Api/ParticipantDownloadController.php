<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminEvaluationSerializer;
use App\Services\Admin\AdminTranscriptSerializer;
use App\Support\Admin\AdminParticipantReader;
use App\Support\Admin\ParticipantReadScope;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Admin ParticipantDownloadController (C11 — Admin Dashboards, PR A3, D9).
 *
 * Every action reaches a Participant exclusively through
 * AdminParticipantReader::read() — the gate on each download is IDENTICAL to
 * the corresponding read endpoint's gate, so a pre-threshold request returns
 * the same 409 lifecycle_not_ready body instead of a file (D9).
 *
 * Buffered, not streamed (D9): a transcript is bounded (a participant has at
 * most ~18 sessions, a few KB each) and JSON must be complete to be valid.
 * Revisit only if a payload approaches ~1 MB.
 *
 * Content-Disposition safety: `candidate_ref` is externally supplied and
 * opaque (Participant.php:46). It is Str::slug()-ed before it ever reaches
 * the filename — slug() strips quotes, CRLF, and non-ASCII by construction —
 * and BOTH an RFC 5987 `filename*=UTF-8''…` form and a plain ASCII
 * `filename=` fallback are emitted (D9), never a raw interpolation of the
 * caller-controlled value.
 */
final class ParticipantDownloadController extends Controller
{
    public function __construct(
        private readonly AdminParticipantReader $reader,
        private readonly AdminTranscriptSerializer $transcriptSerializer,
        private readonly AdminEvaluationSerializer $evaluationSerializer,
    ) {}

    /**
     * GET /api/participants/{id}/transcript/download
     */
    public function transcript(int $id): Response
    {
        $participant = $this->reader->read($id, ParticipantReadScope::Transcript);

        $body = $this->renderTranscriptText($this->transcriptSerializer->serialize($participant));

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => $this->contentDisposition($participant->candidate_ref, 'transcript', 'txt'),
        ]);
    }

    /**
     * GET /api/participants/{id}/evaluation/download
     */
    public function evaluation(int $id): Response
    {
        $participant = $this->reader->read($id, ParticipantReadScope::Evaluation);

        // JSON_PRESERVE_ZERO_FRACTION: without it, json_encode(4.0) === "4",
        // silently dropping the fractional part of a whole-number float
        // score and diverging from the reference shape
        // (esempio-report-valutazione.json shows "score": 4.0 literally).
        $body = json_encode(
            $this->evaluationSerializer->serialize($participant),
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
        );

        return response($body, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => $this->contentDisposition($participant->candidate_ref, 'evaluation', 'json'),
        ]);
    }

    /**
     * Plain-text rendering of the transcript sessions produced by
     * AdminTranscriptSerializer::serialize(), grouped by competency in the
     * already-established question_index/id order.
     *
     * @param  array<int, array{session_id: int, competency_code: string, question_index: int, utterances: array<int, array{speaker: string, text: string, ts: string|null}>}>  $sessions
     */
    private function renderTranscriptText(array $sessions): string
    {
        $lines = [];

        foreach ($sessions as $session) {
            $lines[] = sprintf('=== %s (question %d) ===', $session['competency_code'], $session['question_index'] + 1);

            foreach ($session['utterances'] as $utterance) {
                $lines[] = sprintf('[%s] %s: %s', $utterance['ts'] ?? '', $utterance['speaker'], $utterance['text']);
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Build a safe Content-Disposition header value.
     *
     * $candidateRef is externally supplied (Participant.php:46) — Str::slug()
     * reduces it to [a-z0-9-] before it is ever placed in a header, which by
     * construction cannot carry a quote, CRLF, or non-ASCII byte. Both the
     * plain filename= and the RFC 5987 filename*= forms are still emitted
     * (D9's explicit discipline), never a raw interpolation of the input.
     */
    private function contentDisposition(string $candidateRef, string $type, string $extension): string
    {
        $slug = Str::slug($candidateRef);
        if ($slug === '') {
            $slug = 'candidate';
        }

        $filename = sprintf('beai-%s-%s-%s.%s', $type, $slug, now()->format('Ymd'), $extension);

        return sprintf("attachment; filename=\"%s\"; filename*=UTF-8''%s", $filename, rawurlencode($filename));
    }
}
