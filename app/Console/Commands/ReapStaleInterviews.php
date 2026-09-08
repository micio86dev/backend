<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ConversationLlm\RecordConversationLlmUsage;
use App\Actions\Interview\SettleParticipantCompletion;
use App\Models\InterviewSession;
use App\Support\Interview\SessionLiveClock;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * End interviews the browser never ended (stale-interview-reaper).
 *
 * `POST /api/candidate/interview/end` is the ONLY thing that ends a session,
 * so the whole chain — status, `ended_at`, the live-period close, the cost
 * row, and the participant's advance to `in_valutazione` — rests on the
 * candidate's tab living long enough to make one more HTTP call.
 *
 * Observed in production on 2026-09-08: a participant started at 10:52,
 * thirty-two utterances of a real conversation were captured, and two hours
 * later the session was still `in_corso` with a null `ended_at`. Nothing would
 * ever have scored it. The ways this happens are ordinary rather than exotic —
 * the avatar never speaks its closing phrase, the tab is closed, the laptop
 * sleeps — and in every one of them the transcript is already safe on the
 * server. Only the sentence saying "this is over" is missing.
 *
 * This command supplies that sentence, through the same writes `/end` makes,
 * and then lets the participant progress so what they DID say gets scored.
 */
final class ReapStaleInterviews extends Command
{
    protected $signature = 'beai:reap-stale-interviews
                            {--dry-run : Report what would be ended and changed, without writing anything or dispatching any job}';

    protected $description = 'End interview sessions abandoned mid-conversation, and settle their participants so the captured transcript is scored';

    public function __construct(
        private readonly SessionLiveClock $liveClock,
        private readonly RecordConversationLlmUsage $recordLlmUsage,
        private readonly SettleParticipantCompletion $settle,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Refused rather than defaulted. `(int) 'abc'` is 0, and a zero
        // threshold means `now()` — which would end EVERY live interview on
        // the platform on the next tick, mid-conversation, and settle every
        // candidate on a partial transcript. A hand-typed env var is exactly
        // where that typo lives, and a sweep that destructive must not be one
        // silent cast away.
        $staleAfterMinutes = (int) config('interview.stale_after_minutes');

        if ($staleAfterMinutes < 1) {
            $this->error(sprintf(
                'interview.stale_after_minutes resolved to %d. Refusing to run: a threshold below one minute would end every live interview on the platform.',
                $staleAfterMinutes,
            ));

            return self::FAILURE;
        }

        $threshold = now()->subMinutes($staleAfterMinutes);

        $stale = $this->staleSessions($threshold);

        if ($stale->isEmpty()) {
            $this->info('No stale interview sessions.');

            return self::SUCCESS;
        }

        // Grouped by participant so the settle runs ONCE per person, after all
        // of their sessions are closed. Settling per session would spend a
        // scoring job on the first and find nothing to do on the rest —
        // harmless because of the compare-and-set, but it would also score a
        // participant whose remaining sessions had not been closed yet.
        $byParticipant = $stale->groupBy('participant_id');

        foreach ($stale as $session) {
            $this->line(sprintf(
                '  session %d (participant %d, %s) — last activity %s',
                $session->id,
                $session->participant_id,
                $session->competency_code,
                // `getAttribute`, not `->last_activity_at`: this is a select
                // alias the query adds, not a column on the model, and reading
                // it as a declared property would be claiming otherwise.
                (string) $session->getAttribute('last_activity_at'),
            ));

            if (! $dryRun) {
                // Tenant context per session, exactly as the sibling scheduled
                // command ReconcileLlmUsage does for the SAME recorder.
                //
                // Without it this command silently did nothing useful: the
                // scheduler establishes no context, `TenantResolver` defaults
                // to orgId=null with bypass off, and `organization_id = NULL`
                // is never true in SQL — so the live-period close found no row
                // and no-opped, and `RecordConversationLlmUsage`'s create threw
                // MissingTenantContextException and aborted the whole sweep.
                TenantContextScope::runFor(
                    (int) $session->organization_id,
                    fn () => $this->endSession($session),
                );
            }
        }

        foreach ($byParticipant as $participantId => $sessions) {
            /** @var InterviewSession $first */
            $first = $sessions->first();

            if ($dryRun) {
                $this->line(sprintf('  participant %d would be settled', $participantId));

                continue;
            }

            // Same reason: `settleAbandoned()` reads `Project` and `Utterance`,
            // both tenant-scoped. Unscoped, the project lookup returned null
            // and settled nobody at all, and the "did the candidate speak?"
            // query answered false for someone who had spoken thirty-two times
            // — which would have filed a real interview as `errore`.
            $settled = TenantContextScope::runFor(
                (int) $first->organization_id,
                fn () => $this->settle->settleAbandoned((int) $participantId, (int) $first->project_id),
            );

            if ($settled !== null) {
                $this->line(sprintf('  participant %d -> %s', $participantId, $settled));
            }
        }

        $verb = $dryRun ? 'would end' : 'ended';
        $this->info(sprintf('%s %d stale session(s) across %d participant(s).', $verb, $stale->count(), $byParticipant->count()));

        return self::SUCCESS;
    }

    /**
     * Sessions still `in_corso` whose LAST ACTIVITY predates the threshold.
     *
     * Activity is the later of the session's start and its most recent
     * utterance — never `started_at` alone, which would end a long interview
     * that is still going. A candidate who thinks before answering is not an
     * abandoned session.
     *
     * The comparison is a correlated subquery in WHERE, not HAVING. Postgres
     * treats a HAVING clause as making the query aggregated and then demands
     * every selected column appear in a GROUP BY — which is not what this asks
     * at all: there is no grouping here, only a per-row predicate.
     *
     * @return Collection<int, InterviewSession>
     */
    private function staleSessions(Carbon $threshold): Collection
    {
        // No bindings of its own — `whereColumn` compiles to bare identifiers —
        // so the only binding this query carries is the threshold below.
        $lastActivity = 'greatest('
            .'coalesce((select max(ts) from utterances '
            .'where utterances.interview_session_id = interview_sessions.id), interview_sessions.started_at), '
            .'interview_sessions.started_at)';

        return InterviewSession::query()
            // Unscoped by tenant on purpose: this is a platform-wide sweep run
            // by the scheduler, which carries no tenant context and is not
            // acting for anyone. Every write below is keyed by the session's
            // own ids, so nothing crosses a boundary a request had not already
            // decided.
            ->withoutGlobalScopes()
            ->where('status', 'in_corso')
            ->selectRaw("interview_sessions.*, {$lastActivity} as last_activity_at")
            ->whereRaw("{$lastActivity} < ?", [$threshold])
            ->orderBy('interview_sessions.id')
            ->get();
    }

    /**
     * The same three writes `/end` makes, in the same order and for the same
     * reasons the controller records: skipping the live-period close leaves an
     * open stretch that inflates every later duration, and skipping the cost
     * row loses spend this session actually incurred.
     *
     * Each session in its OWN transaction with the row locked, so a candidate
     * who returns mid-sweep either loses the race cleanly or is already past it.
     */
    private function endSession(InterviewSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $locked = InterviewSession::withoutGlobalScopes()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();

            // Re-checked under the lock, never trusted from the sweep's own
            // read: the candidate's `/end` may have landed in between, and
            // stamping `timeout` over a genuine `completed` would rewrite the
            // truth about how their interview finished.
            if ($locked === null || $locked->status !== 'in_corso') {
                return;
            }

            $locked->status = 'timeout';
            $locked->ended_reason = 'timeout';
            $locked->ended_at = now()->toImmutable();
            $locked->save();

            $this->liveClock->close($locked, 'end');
            ($this->recordLlmUsage)($locked);
        });

        Log::info('reaped an abandoned interview session', [
            'session_id' => $session->id,
            'participant_id' => $session->participant_id,
        ]);
    }
}
