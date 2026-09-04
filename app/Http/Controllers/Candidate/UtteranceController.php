<?php

declare(strict_types=1);

namespace App\Http\Controllers\Candidate;

use App\Http\Controllers\Candidate\Concerns\ResolvesOwnedSession;
use App\Http\Controllers\Controller;
use App\Support\Logging\SafeDbContext;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     *
     * `ts` is validated as a DATE, not a string. It is bound into a
     * `?::timestamptz` cast below, so an unparseable value used to reach
     * Postgres and come back as a QueryException — a 500 and an error-level log
     * line for what is a validation failure, on the highest-volume write in the
     * product. This method promises 422 for exactly that.
     *
     * The reasoning lives here rather than beside the rule: Scramble publishes
     * comments inside the validation array into `openapi.json`, and from there
     * into the generated TS clients of both Nuxt apps. Notes about our own 500s
     * are not part of a contract a candidate app consumes.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'integer'],
            'speaker' => ['required', 'string', 'in:candidate,avatar'],
            'text' => ['required', 'string'],
            'ts' => ['required', 'date'],
        ]);

        // resolveOwnedSession: enforces participant_id + org isolation → 404 if not owned.
        // MUST be invoked FIRST, before any DB mutation (design WARNING-4).
        $session = $this->resolveOwnedSession((int) $validated['session_id']);

        // ATOMIC conditional INSERT (FIX-2 TOCTOU guard).
        // DB::affectingStatement() returns the number of rows actually inserted.
        // The WHERE EXISTS guarantees the status check and INSERT are one atomic operation.
        // If 0 rows → session was not in_corso when the INSERT ran → 409 (not 202 or 500).
        //
        // `provider_session_ref` is read FROM the session row inside this same
        // statement rather than passed in, so the stamp is atomic with the
        // status check: the row is tagged with the stretch that was current at
        // the instant it was accepted, not with one read a moment earlier. That
        // tag is what lets a resume replace exactly the stretch it fetched.
        // The AMBIENT tenant — what the global scope itself reads, and what
        // `DB::affectingStatement()` bypasses.
        //
        // Binding `$session->organization_id` here instead would be a TAUTOLOGY:
        // match row X by id, then assert row X's org equals row X's own org,
        // read off the model just loaded. It cannot fail, so it defends nothing.
        // This value comes from a different source, which is the whole point.
        //
        // Be precise about what that buys, because there is no test here and the
        // reason matters: today the two can never disagree. `TenantContextCandidate`
        // derives the ambient tenant from the same token that owns the session,
        // and `resolveOwnedSession()` 404s on anything else, so no request can
        // reach this line with them differing — which is exactly why no mutation
        // test can distinguish the two bindings. This is defence in depth against
        // a future ingress that resolves the session some other way, not a
        // behaviour change, and it is not evidence of one.
        $orgId = app(TenantResolver::class)->getOrgId();

        // GUARDED, for the same reason insertUtterances() is in
        // InterviewController: this statement binds the candidate's verbatim
        // speech, and a QueryException escaping to Laravel's default handler is
        // logged via getMessage(), which formatMessage() builds by interpolating
        // every binding into the SQL. That would put the transcript in plaintext
        // in the application log — and this is the highest-volume utterance write
        // in the product, once per turn of every interview, where the batch paths
        // run twice per competency.
        //
        // The rethrow carries no previous exception on purpose: chaining it would
        // hand the handler the same interpolated string this catch withholds.
        try {
            $rowsInserted = DB::affectingStatement(
                'INSERT INTO utterances (interview_session_id, organization_id, speaker, text, ts, provider_session_ref)
                 SELECT ?, ?, ?, ?, ?::timestamptz, s.provider_session_ref
                 FROM interview_sessions s
                 WHERE s.id = ? AND s.organization_id = ? AND s.status = ?',
                [
                    $session->id,
                    $orgId,
                    $validated['speaker'],
                    $validated['text'],
                    $validated['ts'],
                    $session->id,
                    $orgId,
                    'in_corso',
                ]
            );
        } catch (QueryException $e) {
            Log::error('C7a: live utterance insert failed', SafeDbContext::for($e));

            throw new \RuntimeException('utterance_insert_failed');
        }

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
