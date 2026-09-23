<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ParticipantSchedulingStatus;
use App\Exceptions\Sso\EntryLinkRefused;
use App\Jobs\SendCandidateInvitationJob;
use App\Jobs\SendScheduledInterviewNoticeJob;
use App\Models\Participant;
use App\Support\Scheduling\ScheduledInterviewWindow;
use App\Support\Sso\EntryLinkMinter;
use App\Support\Sso\EntryLinkUrlComposer;
use App\Support\Tenancy\TenantContextScope;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The periodic sweep that turns a scheduled `participants` row into the two
 * emails a candidate actually receives (interview-scheduling, design AD-4).
 *
 * Modeled directly on `ReapStaleInterviews`: same `withoutGlobalScopes()`
 * platform-wide SELECT (this command carries no ambient tenant context and
 * acts for nobody), same per-row `DB::transaction()` + `lockForUpdate()` +
 * re-check-under-lock idempotency (design AD-5), same
 * `TenantContextScope::runFor()` wrapping around every write and every
 * tenant-scoped read (design AD-8) — `Participant::project()` resolves a
 * `Project`, which DOES extend `TenantModel`, so without an established
 * context that relation's global scope would silently return null for every
 * row.
 *
 * TWO INDEPENDENT SELECTIONS, not one — this is the load-bearing shape (design
 * AD-4):
 *   - notice-due:  scheduling_status = Pending    AND scheduled_at <= now() + NOTICE_LEAD_MINUTES
 *   - start-due:   scheduling_status IN (Pending, NoticeSent) AND scheduled_at <= now()
 * A participant whose notice window a slow tick skipped entirely (still
 * `Pending` once `scheduled_at` itself has passed) is caught by `IN
 * (Pending, NoticeSent)` on the start-due side regardless — the "notice never
 * fired on time" case degrades to "still gets a start email", never to
 * "gets nothing" (AD-10's residual-risk list). A participant whose delay is
 * severe enough to cross BOTH thresholds before a single tick runs is
 * therefore picked up by BOTH selections in the SAME run: the notice loop
 * sends first and advances it to `NoticeSent`, and the start loop — already
 * matching via `NoticeSent` — sends immediately after. Exactly one notice
 * email and exactly one start email, never zero of either, which is what the
 * spec's own backlog scenario requires.
 *
 * Each participant is processed inside its own try/catch: one row's refusal
 * (e.g. `EntryLinkMinter` rejecting a project whose gates closed between
 * scheduling and send time) is logged and skipped rather than aborting the
 * whole sweep — the same per-row isolation `ReapStaleInterviews` gets for
 * free from running each session in its own transaction, made explicit here
 * because a thrown `EntryLinkRefused` would otherwise end the run for every
 * OTHER organization's due participants too.
 */
final class DispatchScheduledInterviewInvitations extends Command
{
    protected $signature = 'beai:dispatch-scheduled-invitations
                            {--dry-run : Report what would be sent, without writing anything or dispatching any job}';

    protected $description = 'Send the interview-scheduling notice and start emails whose windows have arrived, advancing scheduling_status under lock';

    public function __construct(
        private readonly EntryLinkMinter $minter,
        private readonly EntryLinkUrlComposer $composer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();

        $noticeDue = $this->noticeDueParticipants($now);
        $startDue = $this->startDueParticipants($now);

        $noticesSent = 0;
        $startsSent = 0;

        foreach ($noticeDue as $participant) {
            $this->line(sprintf(
                '  notice: participant %d (%s), scheduled %s',
                $participant->id,
                $participant->candidate_ref,
                (string) $participant->scheduled_at,
            ));

            if ($dryRun) {
                continue;
            }

            if ($this->processOne($participant, fn (): bool => $this->sendNotice($participant))) {
                $noticesSent++;
            }
        }

        foreach ($startDue as $participant) {
            $this->line(sprintf(
                '  start: participant %d (%s), scheduled %s',
                $participant->id,
                $participant->candidate_ref,
                (string) $participant->scheduled_at,
            ));

            if ($dryRun) {
                continue;
            }

            if ($this->processOne($participant, fn (): bool => $this->sendStart($participant))) {
                $startsSent++;
            }
        }

        $verb = $dryRun ? 'would send' : 'sent';
        $this->info(sprintf(
            '%s %d notice(s) and %d start email(s).',
            $verb,
            $dryRun ? $noticeDue->count() : $noticesSent,
            $dryRun ? $startDue->count() : $startsSent,
        ));

        return self::SUCCESS;
    }

    /**
     * Participants whose notice window has arrived: still `Pending` and due
     * within `ScheduledInterviewWindow::NOTICE_LEAD_MINUTES` of now.
     *
     * @return Collection<int, Participant>
     */
    private function noticeDueParticipants(Carbon $now): Collection
    {
        return Participant::query()
            // Platform-wide sweep, exactly like ReapStaleInterviews::staleSessions():
            // the scheduler carries no tenant context and acts for nobody.
            ->withoutGlobalScopes()
            ->where('scheduling_status', ParticipantSchedulingStatus::Pending->value)
            ->where('scheduled_at', '<=', $now->copy()->addMinutes(ScheduledInterviewWindow::NOTICE_LEAD_MINUTES))
            ->orderBy('id')
            ->get();
    }

    /**
     * Participants whose start moment has arrived: `Pending` OR `NoticeSent`
     * (the backlog-catch-up case, design AD-4) and their `scheduled_at` is
     * now in the past.
     *
     * @return Collection<int, Participant>
     */
    private function startDueParticipants(Carbon $now): Collection
    {
        return Participant::query()
            ->withoutGlobalScopes()
            ->whereIn('scheduling_status', [
                ParticipantSchedulingStatus::Pending->value,
                ParticipantSchedulingStatus::NoticeSent->value,
            ])
            ->where('scheduled_at', '<=', $now)
            ->orderBy('id')
            ->get();
    }

    /**
     * Runs $action under this participant's own tenant context, isolating a
     * single row's failure from every other organization's due participants.
     *
     * @param  Closure(): bool  $action
     */
    private function processOne(Participant $participant, Closure $action): bool
    {
        try {
            return TenantContextScope::runFor((int) $participant->organization_id, $action);
        } catch (Throwable $e) {
            // Deliberately does NOT cancel: a generic Throwable here (a save
            // deadlock, a lock-wait timeout) is the transient case, and
            // `scheduled_at` measures how overdue the row is, not how many
            // times it has already been retried — every backlogged row
            // (worker down, deploy window) is already past any staleness
            // floor on its very FIRST attempt, so gating cancellation on
            // `scheduled_at` cancelled exactly the rows this sweep exists to
            // catch up on. Structural, never-self-resolving failures (a null
            // project relation, an `EntryLinkRefused`) are cancelled inline
            // by their own call site instead — see `cancelScheduling()`'s
            // call sites in `sendNotice()`/`sendStart()`. Leaving this row
            // untouched means it is re-selected and retried next tick,
            // accepting unbounded retry on a truly permanent-but-unrecognized
            // failure as the lesser risk.
            Log::error('interview-scheduling sweep: failed to process a due participant', [
                'participant_id' => $participant->id,
                'organization_id' => $participant->organization_id,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Terminal exit for a row that can never be dispatched: advances it to
     * `Cancelled` (excluded from both sweep selections, so it is never
     * reselected) and logs at ERROR — not WARNING — because a candidate is
     * about to silently never receive their interview link unless an
     * operator notices this line.
     */
    private function cancelScheduling(Participant $locked, string $reason): void
    {
        $locked->scheduling_status = ParticipantSchedulingStatus::Cancelled;
        $locked->save();

        Log::error('interview-scheduling sweep: cancelling a participant that can never be dispatched', [
            'participant_id' => $locked->id,
            'organization_id' => $locked->organization_id,
            'reason' => $reason,
        ]);
    }

    /**
     * Sends the advance notice and advances Pending -> NoticeSent.
     *
     * Idempotency (design AD-5): the row is locked and its status re-checked
     * UNDER the lock before anything is sent — a concurrent tick or an
     * overlapping run that already advanced this row is a silent no-op here,
     * never a second notice.
     */
    private function sendNotice(Participant $participant): bool
    {
        // The transaction ONLY locks, checks and saves; it returns the
        // dispatch payload (or null) rather than dispatching from inside the
        // closure. `SendScheduledInterviewNoticeJob::dispatch()` runs below,
        // AFTER `DB::transaction()` has returned — i.e. only once the advance
        // to NoticeSent has actually committed. A save failure (deadlock
        // victim, lock-wait timeout, connection loss) throws OUT of
        // DB::transaction(), rolls the row back, and never reaches the
        // dispatch line at all: the next tick still sees this row Pending and
        // gets exactly one more chance, never a duplicate notice.
        $noticeArgs = DB::transaction(function () use ($participant): ?array {
            // organization_id filtered explicitly even though Participant
            // carries no global scope of its own (it does NOT extend
            // TenantModel) and $participant->id here always comes from this
            // command's own trusted sweep query, never external input: this
            // re-fetch is the ONLY line of defense a Participant read gets,
            // so it is never trusted to stay narrow by construction alone —
            // same discipline M2m\ParticipantController's own reads apply.
            $locked = Participant::withoutGlobalScopes()
                ->whereKey($participant->id)
                ->where('organization_id', $participant->organization_id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->scheduling_status !== ParticipantSchedulingStatus::Pending) {
                return null;
            }

            $project = $locked->project;

            if ($project === null) {
                $this->cancelScheduling($locked, 'project relation no longer resolves');

                return null;
            }

            $locked->scheduling_status = ParticipantSchedulingStatus::NoticeSent;
            $locked->save();

            return [
                $locked->email,
                $locked->display_name,
                (string) $project->organization?->name,
                $project->name,
                $locked->language ?? $project->language ?? config('app.fallback_locale', 'en'),
                $project->organization?->primary_color,
                $project->organization?->absoluteLogoUrl(),
            ];
        });

        if ($noticeArgs === null) {
            return false;
        }

        try {
            SendScheduledInterviewNoticeJob::dispatch(...$noticeArgs);
        } catch (Throwable $e) {
            $this->logPostCommitDispatchFailure($participant, 'notice', ParticipantSchedulingStatus::NoticeSent, $e);
        }

        return true;
    }

    /**
     * Mints the entry link NOW (design AD-6 — never earlier), sends the
     * SAME `CandidateInvitationNotification` the immediate path already
     * uses, and advances Pending|NoticeSent -> Started.
     *
     * Idempotency: same lock + re-check-under-lock discipline as
     * `sendNotice()` — a row already `Started` (or `Cancelled`) by the time
     * this transaction acquires the lock is left untouched.
     */
    private function sendStart(Participant $participant): bool
    {
        // Same dispatch-after-commit shape as sendNotice() above: the
        // transaction returns the dispatch payload (or null), and
        // `SendCandidateInvitationJob::dispatch()` runs only AFTER
        // DB::transaction() has returned — never from inside the closure — so
        // a save failure rolls the advance to Started back without ever
        // having queued the entry-link email.
        $startArgs = DB::transaction(function () use ($participant): ?array {
            // organization_id filtered explicitly — same reasoning as
            // sendNotice()'s own re-fetch above.
            $locked = Participant::withoutGlobalScopes()
                ->whereKey($participant->id)
                ->where('organization_id', $participant->organization_id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! in_array($locked->scheduling_status, [
                ParticipantSchedulingStatus::Pending,
                ParticipantSchedulingStatus::NoticeSent,
            ], true)) {
                return null;
            }

            $project = $locked->project;

            if ($project === null) {
                $this->cancelScheduling($locked, 'project relation no longer resolves');

                return null;
            }

            try {
                $minted = $this->minter->mint(
                    $project,
                    $locked->candidate_ref,
                    $locked->display_name,
                    $locked->email,
                    $locked->role_code,
                    $locked->language,
                );
            } catch (EntryLinkRefused $e) {
                // A structural refusal (closed gates, a mismatched role_code,
                // a terminal duplicate on another axis) will never pass on a
                // later retry, unlike a save deadlock — cancel now, inline,
                // rather than relying on processOne()'s generic catch.
                $this->cancelScheduling($locked, sprintf('EntryLinkMinter refused: %s', $e->reason->value));

                return null;
            }

            $entryUrl = $this->composer->compose($minted->token, $minted->lang);

            // Same date-rendered-in-the-candidate's-own-language treatment as
            // EntryLinkController::store() (:244-246) — a copy, on a Carbon
            // instance, so locale() (getter/setter, static|string return) does
            // not mutate the instance the response would otherwise read from.
            $expiresAt = $minted->expiresAt->copy();
            $expiresAt->locale($minted->lang);
            $expiresLabel = $expiresAt->isoFormat('LLL');

            $locked->scheduling_status = ParticipantSchedulingStatus::Started;
            $locked->save();

            return [
                $locked->email,
                $entryUrl,
                $locked->display_name,
                (string) $project->organization?->name,
                $project->name,
                $expiresLabel,
                $minted->lang,
                $project->organization?->primary_color,
                $project->organization?->absoluteLogoUrl(),
            ];
        });

        if ($startArgs === null) {
            return false;
        }

        try {
            SendCandidateInvitationJob::dispatch(...$startArgs);
        } catch (Throwable $e) {
            $this->logPostCommitDispatchFailure($participant, 'start', ParticipantSchedulingStatus::Started, $e);
        }

        return true;
    }

    /**
     * The one failure mode this sweep cannot self-heal: `sendNotice()` /
     * `sendStart()` above already committed the status advance, and BOTH
     * sweep selections exclude `NoticeSent`-that-should-have-dispatched and
     * `Started` rows from ever being reselected. Reverting the already-saved
     * status here would only trade this bug for a double-send race against a
     * concurrent tick, so the row is deliberately left as-is; this is purely
     * an observability fix, making the failure loud and actionable instead of
     * indistinguishable from `processOne()`'s generic (retry-next-tick) catch.
     * The message and the extra fields (`project_id`, `email`, `stage`,
     * `persisted_status`) are what let an operator tell "will retry itself"
     * apart from "gone forever unless someone resends by hand".
     */
    private function logPostCommitDispatchFailure(
        Participant $participant,
        string $stage,
        ParticipantSchedulingStatus $persistedStatus,
        Throwable $e,
    ): void {
        Log::error('interview-scheduling sweep: SILENT DATA LOSS RISK — post-commit dispatch failed, status already advanced and this row will never be reselected; manual resend required', [
            'participant_id' => $participant->id,
            'organization_id' => $participant->organization_id,
            'project_id' => $participant->project_id,
            'email' => $participant->email,
            'stage' => $stage,
            'persisted_status' => $persistedStatus->value,
            'exception' => $e->getMessage(),
        ]);
    }
}
