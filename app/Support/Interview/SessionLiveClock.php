<?php

declare(strict_types=1);

namespace App\Support\Interview;

use App\Models\InterviewSession;
use App\Models\InterviewSessionLivePeriod;
use App\Support\AvatarTemplates\ActiveTemplateResolver;
use App\Support\AvatarTemplates\ProviderFieldSpecs;

/**
 * The only writer of `interview_session_live_periods` rows
 * (interview-session-started-at, D1/D4/D4a).
 *
 * Three seams call this — the two `in_corso` sites (`open()`) and the three
 * exits from `in_corso` (`close()`) — never inline arithmetic against the
 * table anywhere else in the codebase.
 *
 * `close()` is a no-op when nothing is open, which is what makes
 * `pending -> error` free: `markSessionError()` can call it unconditionally
 * without first checking whether a period was ever opened.
 *
 * The D5 invariant — at most one open period per session — is enforced by a
 * PostgreSQL partial unique index, not by this class alone: discipline in
 * one class is not an invariant, and the next writer of a status transition
 * does not necessarily read this file.
 *
 * REQ: interview-session "A live session records when it became live, at
 *      both sites that grant it"; "Recorded duration is accumulated live
 *      time, not wall-clock span"
 */
final class SessionLiveClock
{
    public function __construct(
        private readonly ActiveTemplateResolver $templates = new ActiveTemplateResolver,
    ) {}

    /**
     * Open a new live period for this session.
     *
     * Called at both `in_corso` sites. The plain path (`handleIssuePending`)
     * always arrives here with no open period (D5's invariant — a `pending`
     * row holds none). The resume path (`handleResumeInCorso`) MUST close
     * the outgoing period first (see `close()`) before calling this, or the
     * partial unique index rejects the INSERT.
     */
    public function open(InterviewSession $session, ?string $providerSessionRef): void
    {
        InterviewSessionLivePeriod::create([
            'interview_session_id' => $session->id,
            'provider_session_ref' => $providerSessionRef,
            'started_at' => now(),
            'ended_at' => null,
            'closed_reason' => null,
        ]);
    }

    /**
     * Close this session's currently open period, if one exists.
     *
     * A no-op when nothing is open — the reason `pending -> error`
     * (a session that never reached the provider) costs nothing: callers
     * never need to check first.
     *
     * `$reason` ∈ {resume, end, error} — which of the three exits from
     * `in_corso` closed this stretch.
     *
     * D4a — THE CLOSE BOUNDARY IS CAP-BOUNDED, NEVER RAW `now()`.
     *
     * Production evidence: a candidate's browser emitted
     * `[SDK:SESSION_STOPPED] Server stopped session, reason: MAX_DURATION_REACHED`.
     * Nothing tears the outgoing provider session down on abandonment —
     * `handleResumeInCorso` only tears it down when the candidate reconnects,
     * and `/end` only runs when they finish — so an abandoned session stays
     * live and BILLABLE, provider-side, until the provider's own ceiling. The
     * true stop time is unknowable from anything BEAI holds (no heartbeat, no
     * provider status read-back exists in this codebase — see the class
     * docblock's REQ), so stamping raw `now()` at discovery would record
     * hours of billing that could not physically have occurred (the exact
     * "leave-alone, gap counted" failure mode `liveSeconds()` exists to
     * prevent). The provider's contractual ceiling is the one number BEAI
     * CAN name honestly: a live period cannot legitimately exceed it. This is
     * symmetric with `markSessionError`'s already-accepted bounded
     * UNDER-count on the error exit (a resume whose `issue()` throws is
     * recorded only up to detection, never invented forward) — D4a is the
     * mirror-image bounded OVER-count on the ordinary exits.
     *
     * Rejected alternative: stamping raw `now()` (the original D4 text).
     * Mathematically indistinguishable from the leave-alone span this table
     * exists to replace whenever the closing request itself arrives late —
     * proven by direct execution, not assumed.
     *
     * DISCLOSED RESIDUAL, not a task here: BEAI could learn the TRUE stop
     * time if the client reported its `session.disconnected` event (the
     * handler already exists in `frontend/app/providers/heygen.ts`). That is
     * a separate change. Until it ships, a stretch that hits the cap is a
     * disclosed UPPER ESTIMATE of an abandoned stretch, never a claim of
     * observed candidate activity.
     */
    public function close(InterviewSession $session, string $reason): void
    {
        $open = InterviewSessionLivePeriod::where('interview_session_id', $session->id)
            ->whereNull('ended_at')
            ->first();

        if ($open === null) {
            return;
        }

        $cappedAt = $open->started_at->addSeconds($this->resolveMaxSeconds($session));
        $now = now();

        // min(now(), started_at + max_seconds) — the cap is a CEILING, never
        // a FLOOR: a stretch that closes normally, well inside the ceiling
        // (the ordinary `/end` case), records its real, uncapped duration.
        $open->ended_at = $now->lessThan($cappedAt) ? $now : $cappedAt;
        $open->closed_reason = $reason;
        $open->save();
    }

    /**
     * The provider's real ceiling for this session, in seconds (D4a).
     *
     * An org's active avatar template can configure a LOWER value
     * (`maxSessionDurationSec` for HeyGen, `maxCallDurationSec` for Tavus) —
     * that value wins, because it is what was actually sent to the provider
     * on `issue()`, and is therefore the real ceiling THIS session's
     * provider call was bound by. Falls back to `ProviderFieldSpecs`'
     * platform ceiling when no template applies (no active template on this
     * session's provider — `resolve()` (pluggable-conversation-llm PR P0)
     * already filters by provider, so a different-provider active template
     * is never returned here in the first place).
     */
    private function resolveMaxSeconds(InterviewSession $session): int
    {
        $default = match ($session->provider) {
            'tavus' => ProviderFieldSpecs::TAVUS_MAX_SECONDS,
            default => ProviderFieldSpecs::HEYGEN_MAX_SECONDS,
        };

        $template = $this->templates->resolve($session->provider);

        if ($template === null) {
            return $default;
        }

        $key = match ($session->provider) {
            'tavus' => 'maxCallDurationSec',
            default => 'maxSessionDurationSec',
        };

        $configured = $template->config[$key] ?? null;

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : $default;
    }
}
