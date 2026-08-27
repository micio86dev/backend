<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ConversationLlm\RecordConversationLlmUsage;
use App\Enums\LlmBindingStatus;
use App\Models\InterviewSession;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily sweep for a conversation-LLM cost row on a session that never
 * reached `/end` (pluggable-conversation-llm PR P6b, design D10 W4).
 *
 * Two holes behind "cost capture is tied to the client calling `POST /end`",
 * and they do NOT get the same answer:
 *
 * (1) TERMINAL BUT UNSWEPT — reconciled HERE. `markSessionError()`
 *     (`InterviewController.php:1039-1072`) sets `status = 'error'` /
 *     `ended_reason = 'error'` on the SERVER, outside any client action,
 *     and never stamps `ended_at` — so `updated_at`, not `ended_at`, is the
 *     grace-window clock this sweep filters on. Same for a session ended by
 *     timeout/skip whose `/end` request was lost.
 *
 *     NOT an approximation of the `/end` computation — the IDENTICAL one,
 *     run later, through the SAME `RecordConversationLlmUsage` action `/end`
 *     itself uses, over inputs already persisted
 *     (`utterances`, `interview_session_live_periods`, `system_prompt_chars`,
 *     `llm_models`). `firstOrCreate()` inside that action is what makes a
 *     late `/end` racing this sweep — in either order — a no-op.
 *
 * (2) PURE ABANDONMENT (a candidate closes the tab; the session sits at
 *     `in_corso` forever, `SessionLiveClock.php:73-76`) — ACCEPTED,
 *     DOCUMENTED GAP, deliberately NOT handled here. This sweep selects only
 *     TERMINAL statuses; it must never force-terminate an `in_corso` session
 *     to make it reconcilable — that is a candidate-visible state change (the
 *     session is still resumable), not a cost feature, and it belongs to the
 *     residual `SessionLiveClock.php:94-99` already discloses. BEAI's LLM
 *     cost figure is a FLOOR, bounded by the provider's own MAX_DURATION
 *     ceiling — never an invoice.
 *
 * Runs with NO HTTP tenant context (`TenantScoped::creating` would throw
 * `MissingTenantContextException` on the insert otherwise), so each
 * session's write is wrapped in `TenantContextScope::runFor()` for that
 * session's own `organization_id` — the mechanism that trait's own docblock
 * names for exactly this: queued/scheduled work.
 *
 * MUST chain `->onOneServer()` wherever registered
 * (`tests/Arch/Queue/SchedulerOnOneServerArchTest.php`).
 */
final class ReconcileLlmUsage extends Command
{
    protected $signature = 'beai:reconcile-llm-usage';

    protected $description = 'Record the LLM cost of sessions that ended without a client /end call';

    /**
     * The grace window: a session must have been quiet for at least this
     * long before the sweep touches it, so it never races a slow-but-real
     * client `/end` request that is still in flight.
     */
    private const GRACE_HOURS = 1;

    private const TERMINAL_STATUSES = ['completed', 'timeout', 'skipped', 'error'];

    public function handle(RecordConversationLlmUsage $recorder): int
    {
        $sessions = InterviewSession::withoutGlobalScopes()
            ->whereIn('status', self::TERMINAL_STATUSES)
            ->where('llm_binding_status', LlmBindingStatus::Applied->value)
            ->where('updated_at', '<', now()->subHours(self::GRACE_HOURS))
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('interview_session_llm_usage')
                    ->whereColumn('interview_session_llm_usage.interview_session_id', 'interview_sessions.id');
            })
            ->get();

        $reconciled = 0;

        foreach ($sessions as $session) {
            TenantContextScope::runFor($session->organization_id, function () use ($session, $recorder, &$reconciled): void {
                if ($recorder($session) !== null) {
                    $reconciled++;
                }
            });
        }

        $this->info(sprintf('Reconciled %d session(s).', $reconciled));

        return self::SUCCESS;
    }
}
