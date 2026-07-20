<?php

declare(strict_types=1);

namespace App\Http\Controllers\Candidate;

use App\Http\Controllers\Candidate\Concerns\ResolvesOwnedSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * UtteranceController (C7a — Interview Session Mechanics).
 *
 * Handles POST /api/candidate/interview/utterance
 *
 * Best-effort live transcript ingestion. Accepts a single utterance (candidate or
 * avatar turn) and persists it linked to the specified session.
 *
 * TOCTOU ATOMICITY (FIX-2 / WARNING-5):
 * A plain SELECT+INSERT is not safe — a concurrent /end can commit 'completed'
 * between the check and the INSERT. This implementation uses a conditional INSERT:
 *
 *   INSERT INTO utterances (...) SELECT ... FROM (VALUES (...)) v
 *   WHERE EXISTS (
 *       SELECT 1 FROM interview_sessions WHERE id = ? AND status = 'in_corso'
 *   )
 *
 * DB::affectingStatement() returns the number of rows inserted. If 0, the session
 * was not in_corso at insertion time → 409 Conflict. This eliminates the TOCTOU window.
 *
 * Response contract:
 * - 202 Accepted  → utterance persisted (session was in_corso at INSERT time)
 * - 404 Not Found → session not owned by authenticated candidate (resolveOwnedSession)
 * - 409 Conflict  → session no longer in_corso at the atomic INSERT moment
 * - 422 Unprocessable → validation failed (missing required fields)
 *
 * REQ: POST /utterance — best-effort live transcript ingestion (C7a)
 */
class UtteranceController extends Controller
{
    use ResolvesOwnedSession;

    /**
     * Ingest a live transcript utterance (best-effort).
     *
     * resolveOwnedSession MUST be called FIRST — it enforces participant_id + org isolation
     * and returns 404 for any non-owned, cross-org, or nonexistent session.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'integer'],
            'speaker'    => ['required', 'string', 'in:candidate,avatar'],
            'text'       => ['required', 'string'],
            'ts'         => ['required', 'string'],
        ]);

        // resolveOwnedSession: enforces participant_id + org isolation → 404 if not owned.
        // MUST be invoked FIRST, before any DB mutation (design WARNING-4).
        $session = $this->resolveOwnedSession((int) $validated['session_id']);

        // ATOMIC conditional INSERT (FIX-2 TOCTOU guard).
        // DB::affectingStatement() returns the number of rows actually inserted.
        // The WHERE EXISTS guarantees the status check and INSERT are one atomic operation.
        // If 0 rows → session was not in_corso when the INSERT ran → 409 (not 202 or 500).
        $rowsInserted = DB::affectingStatement(
            'INSERT INTO utterances (interview_session_id, organization_id, speaker, text, ts)
             SELECT ?, ?, ?, ?, ?::timestamptz
             WHERE EXISTS (
                 SELECT 1 FROM interview_sessions WHERE id = ? AND status = ?
             )',
            [
                $session->id,
                $session->organization_id,
                $validated['speaker'],
                $validated['text'],
                $validated['ts'],
                $session->id,
                'in_corso',
            ]
        );

        if ($rowsInserted === 0) {
            // Session was no longer in_corso at INSERT time (atomic rejection).
            // 409 Conflict is the canonical signal for "session no longer in_corso".
            // The client MUST treat 409 as a no-op (the interview has ended).
            return response()->json(
                ['message' => 'Session is no longer in_corso.'],
                Response::HTTP_CONFLICT
            );
        }

        return response()->json(null, Response::HTTP_ACCEPTED);
    }
}
