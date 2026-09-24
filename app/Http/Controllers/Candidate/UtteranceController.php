<?php

declare(strict_types=1);

namespace App\Http\Controllers\Candidate;

use App\Http\Controllers\Candidate\Concerns\ResolvesOwnedSession;
use App\Http\Controllers\Controller;
use App\Models\InterviewSession;
use App\Support\Interview\TurnClassifier;
use App\Support\Logging\SafeDbContext;
use App\Support\PublicApi\InterviewEventRecorder;
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
 * The classify-then-insert sequence (framework-catalogue-authoring PR7, D8)
 * additionally runs inside a transaction holding `SELECT ... FOR UPDATE` on
 * the session row, so two avatar turns for the SAME session can never both
 * classify against the same pre-write count of matched primaries — see
 * `store()`.
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
     * Z16 (framework-catalogue-authoring, REQUIRED BEFORE ARCHIVE): the cap
     * on how long a request waits for another request's `SELECT ... FOR
     * UPDATE` on the SAME session row before giving up — bounded below the
     * product's own voice-latency NFR (CLAUDE.md: "< 2-3 s") so a candidate
     * never perceives a stall longer than a single turn is already allowed
     * to take.
     */
    private const LOCK_TIMEOUT_MS = 2000;

    public function __construct(
        private readonly TurnClassifier $turnClassifier,
    ) {}

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
        $request->validate([
            'session_id' => ['required', 'integer'],
            'speaker' => ['required', 'string', 'in:candidate,avatar'],
            'text' => ['required', 'string'],
            'ts' => ['required', 'date'],
        ]);

        // `Request::validate()` itself returns a bare, unindexed `array`
        // (step 6 review follow-up) — PHPStan cannot narrow it to this
        // method's own `array{session_id: int, speaker: string, text:
        // string, ts: string}` shape, which every consumer below
        // (`resolveOwnedSession()`, `TurnClassifier::classify()`,
        // `insertUtteranceAtomically()`, `recordUtteranceEvent()`) needs.
        // Read directly from `$request->input()` with explicit narrowing
        // instead — the SAME "never trust a validated array's inferred
        // type, narrow explicitly" discipline `App\Http\Controllers\
        // PublicApi\InterviewController::stringMap()` already applies; the
        // validation rules above already guarantee these are genuinely
        // present and correctly typed, so this is a type PROOF, not a
        // second round of business validation.
        $validated = [
            'session_id' => $request->integer('session_id'),
            'speaker' => $request->string('speaker')->toString(),
            'text' => $request->string('text')->toString(),
            'ts' => $request->string('ts')->toString(),
        ];

        // resolveOwnedSession: enforces participant_id + org isolation → 404 if not owned.
        // MUST be invoked FIRST, before any DB mutation (design WARNING-4).
        $session = $this->resolveOwnedSession((int) $validated['session_id']);

        // The AMBIENT tenant — what the global scope itself reads, and what
        // `DB::affectingStatement()` below bypasses. Resolved once, reused by
        // both the lock and the INSERT.
        $orgId = app(TenantResolver::class)->getOrgId();

        // Primary-vs-follow-up classification (framework-catalogue-authoring
        // PR7, D8) — only ever computed for an AVATAR turn; a candidate's own
        // speech is not part of what "no hidden questions" audits.
        //
        // LOCKED, not merely read-before-insert. `classify()` counts this
        // session's already-persisted `primary`-marked avatar turns to find
        // the next unmatched one — two avatar turns landing concurrently for
        // the SAME session (a live turn racing the provider harvest, or two
        // retried client requests) could otherwise both read the same count
        // and both match the same primary. `SELECT ... FOR UPDATE` on the
        // session row serialises classify-then-insert across concurrent
        // requests for this session without touching the conditional
        // INSERT's own atomicity (FIX-2) or its 409 semantics — a session no
        // longer `in_corso` still 409s exactly as before, now simply after
        // waiting for the lock rather than racing for it. Requests for
        // DIFFERENT sessions never contend: the lock is row-scoped.
        //
        // Z16 (R4-utterance-lock-latency, framework-catalogue-authoring,
        // REQUIRED BEFORE ARCHIVE): the critical section between acquiring
        // the lock and releasing it (COMMIT) is kept to exactly the three
        // statements it needs — the lock, `classify()`'s own single COUNT
        // query, and the INSERT — never widened by anything else. BOUNDED,
        // not merely minimal: `SET LOCAL lock_timeout` caps how long a
        // request will wait for another request's (or a stuck connection's)
        // lock on this SAME session row, so a slow or wedged writer can
        // never stall the live turn loop indefinitely — every OTHER session
        // is already unaffected (the lock is row-scoped), this bounds the
        // SAME-session queuing case specifically. `55P03` (`lock_not_available`,
        // Postgres's own code for a `lock_timeout` expiry) is mapped to a
        // distinct, RETRIABLE 503 — never the generic `utterance_insert_failed`
        // 500, which would tell a client to give up on a turn that a
        // heartbeat later would likely have accepted.
        // Captured by reference from inside the transaction closure below
        // (public-api step 6, G-38) — `question_asked`/`answer_recorded` are
        // recorded AFTER this method's own transaction commits, deliberately
        // OUTSIDE the `SELECT ... FOR UPDATE` lock: Z16 (this class's own
        // docblock) requires the locked critical section to stay exactly
        // the lock, the classify() count and the INSERT — never widened by
        // anything else, and `InterviewEventRecorder` is best-effort
        // observability, not part of that atomicity boundary.
        $turnKind = null;

        try {
            $rowsInserted = DB::transaction(function () use ($session, $orgId, $validated, &$turnKind): int {
                // `SET LOCAL` does not accept a bound parameter in Postgres
                // (it is not a value position the extended query protocol
                // supports) — safe to inline directly, since the value is
                // this class's own internal constant, never request input.
                DB::statement('SET LOCAL lock_timeout = '.self::LOCK_TIMEOUT_MS);

                InterviewSession::where('id', $session->id)
                    ->where('organization_id', $orgId)
                    ->lockForUpdate()
                    ->value('id');

                $turnKind = $validated['speaker'] === 'avatar'
                    ? $this->turnClassifier->classify($session, $validated['text'])
                    : null;

                return $this->insertUtteranceAtomically($session, $orgId, $validated, $turnKind);
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '55P03') {
                Log::warning('C7a: live utterance insert timed out waiting for the session lock', SafeDbContext::for($e));

                return response()->json(['error' => 'utterance_lock_timeout'], Response::HTTP_SERVICE_UNAVAILABLE);
            }

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

        $this->recordUtteranceEvent($session, $validated, $turnKind);

        return response()->json(null, Response::HTTP_ACCEPTED);
    }

    /**
     * `question_asked` (an avatar turn classified `primary` — a `follow_up`
     * turn records nothing new here, per G-38's own list) / `answer_recorded`
     * (a candidate turn) — public-api step 6, recorded AFTER the atomic
     * insert above has committed (never inside its lock — see `store()`'s
     * own comment).
     *
     * `question_index` is the G-37 ordinal: the 0-based position of the
     * primary question within this competency, re-derived from the
     * FRESHLY-persisted `matchedCount()` (which now includes the turn this
     * request just inserted) rather than trusted from a value computed
     * before the insert. `max(0, ... - 1)`: `matchedCount()` counts primaries
     * asked SO FAR, so the ordinal of the one just asked/answered is one
     * less than that count; a candidate turn preceding any primary at all
     * (should not happen in practice — the avatar always asks first) floors
     * at `0` rather than going negative.
     *
     * @param  array{session_id: int, speaker: string, text: string, ts: string}  $validated
     */
    private function recordUtteranceEvent(InterviewSession $session, array $validated, ?string $turnKind): void
    {
        if ($validated['speaker'] === 'avatar' && $turnKind !== 'primary') {
            return;
        }

        $questionIndex = max(0, $this->turnClassifier->matchedCount($session) - 1);

        if ($validated['speaker'] === 'avatar') {
            InterviewEventRecorder::questionAsked($session->organization_id, $session->participant_id, $session->competency_code, $questionIndex);

            return;
        }

        InterviewEventRecorder::answerRecorded($session->organization_id, $session->participant_id, $session->competency_code, $questionIndex);
    }

    /**
     * The atomic conditional INSERT (FIX-2 TOCTOU guard), extracted so the
     * classify-then-insert sequence in `store()` can wrap both under one
     * transaction and row lock without duplicating this statement.
     *
     * Returns the number of rows actually inserted. The WHERE EXISTS-style
     * `AND s.status = 'in_corso'` guarantees the status check and INSERT are
     * one atomic operation — 0 rows means the session was not `in_corso`
     * when the INSERT ran, which `store()` turns into 409 (not 202 or 500).
     *
     * `provider_session_ref` is read FROM the session row inside this same
     * statement rather than passed in, so the stamp is atomic with the
     * status check: the row is tagged with the stretch that was current at
     * the instant it was accepted, not with one read a moment earlier. That
     * tag is what lets a resume replace exactly the stretch it fetched.
     *
     * `$orgId` — the AMBIENT tenant, what the global scope itself reads and
     * what this raw statement bypasses — is passed in rather than re-read,
     * so it stays the SAME value `store()`'s row lock above used.
     *
     * Binding `$session->organization_id` here instead would be a TAUTOLOGY:
     * match row X by id, then assert row X's org equals row X's own org,
     * read off the model just loaded. It cannot fail, so it defends nothing.
     * This value comes from a different source, which is the whole point.
     *
     * Be precise about what that buys, because there is no test here and the
     * reason matters: today the two can never disagree. `TenantContextCandidate`
     * derives the ambient tenant from the same token that owns the session,
     * and `resolveOwnedSession()` 404s on anything else, so no request can
     * reach this line with them differing — which is exactly why no mutation
     * test can distinguish the two bindings. This is defence in depth against
     * a future ingress that resolves the session some other way, not a
     * behaviour change, and it is not evidence of one.
     *
     * Left UNGUARDED by its own try/catch: `store()`'s caller already wraps
     * the whole classify-then-insert transaction in one, so a QueryException
     * here is caught and logged via `SafeDbContext` exactly as before —
     * this statement binds the candidate's verbatim speech, and an
     * unguarded QueryException reaching Laravel's default handler would log
     * `getMessage()` in full, putting the transcript in plaintext in the
     * application log on the highest-volume write in the product.
     *
     * @param  array{session_id: int, speaker: string, text: string, ts: string}  $validated
     */
    private function insertUtteranceAtomically(
        InterviewSession $session,
        ?int $orgId,
        array $validated,
        ?string $turnKind,
    ): int {
        return DB::affectingStatement(
            'INSERT INTO utterances (interview_session_id, organization_id, speaker, text, ts, provider_session_ref, turn_kind)
             SELECT ?, ?, ?, ?, ?::timestamptz, s.provider_session_ref, ?
             FROM interview_sessions s
             WHERE s.id = ? AND s.organization_id = ? AND s.status = ?',
            [
                $session->id,
                $orgId,
                $validated['speaker'],
                $validated['text'],
                $validated['ts'],
                $turnKind,
                $session->id,
                $orgId,
                'in_corso',
            ]
        );
    }
}
