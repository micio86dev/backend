<?php

declare(strict_types=1);

namespace App\Actions\Interview;

use App\Jobs\ReleaseEndedProviderSessionJob;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Interview\ProviderRefLifetime;
use App\Support\Interview\SessionLiveClock;
use App\Support\Interview\SharedProviderRefGuard;
use App\Support\Interview\SingleSessionGate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Grants the next competency a continuation on a Tavus conversation that is already live
 * (tavus-single-session-interview, design N6/A9).
 *
 * The client asserts the conversation id it is still joined to; this action verifies it. A
 * continuation is granted only when ALL hold: the single-session gate applies; the participant is
 * in progress; the id is the ref of a Tavus row of THIS participant and organization whose stored
 * plan covers the next code; no row of the conversation was released (`provider_released_at`) and
 * none is live on it; and the next competency has no row at all (not a resume, a pending row, a
 * re-offer or an evaluation-retry reset). Any other input returns null and writes nothing, so the
 * caller runs the ordinary issue path: a refusal is never an error.
 *
 * On a grant nothing is created at the provider and nothing is composed: one short transaction
 * inserts the `in_corso` row on the SAME ref, with the plan entry's primary questions and budget
 * and a COPY of the owning row's LLM snapshot (five columns; `stamp()` would re-resolve the
 * template and could drift from what the conversation was created with), and opens its live
 * period. The previous row's period closed at its `/end`, which the partial unique index needs.
 * A failure inside the transaction propagates; the caller answers its usual 500, and no ref leaks
 * because none was created.
 *
 * Rule 5 (N6/N7): a conversation near its ceiling is refused, and its release is DEFERRED through the
 * existing job (never inline: the client's crossfade still shows it). The refs travel as captured;
 * the issue path that follows creates a conversation with a DIFFERENT ref, so the job's sibling
 * guard and the `provider_released_at` marker concern the old ref only.
 */
final class AdvanceOnLiveConversation
{
    public function __construct(
        private readonly SingleSessionGate $gate,
        private readonly SharedProviderRefGuard $siblings,
        private readonly SessionLiveClock $liveClock,
        private readonly ProviderRefLifetime $lifetime,
    ) {}

    /**
     * @param  array{competency_code: string, question_index: int}  $next  The resolved next competency.
     */
    public function handle(
        Participant $participant,
        Project $project,
        string $providerName,
        ?string $liveConversationId,
        array $next,
    ): ?InterviewSession {
        if ($liveConversationId === null || $liveConversationId === ''
            || $participant->status !== 'in_corso'
            || ! $this->gate->applies($project, $providerName)) {
            return null;
        }

        $organizationId = (int) $participant->organization_id;

        $owner = InterviewSession::query()
            ->where('organization_id', $organizationId)
            ->where('participant_id', $participant->id)
            ->where('provider', 'tavus')
            ->where('provider_session_ref', $liveConversationId)
            ->whereNotNull('conversation_plan')
            ->orderBy('id')
            ->first();

        if ($owner === null) {
            return null;
        }

        $entry = collect($owner->conversation_plan['competencies'] ?? [])
            ->first(fn (array $candidate): bool => $candidate['code'] === $next['competency_code']);

        if ($entry === null || $this->siblings->hasLiveSibling($organizationId, 'tavus', $liveConversationId, $owner->id)) {
            return null;
        }

        // Not re-checked under the lock below: a stale answer only yields the ordinary issue (a refusal),
        // never a continuation granted on an expired conversation.
        if (! $this->released($organizationId, $liveConversationId) && $this->lifetime->isNearCeiling($owner, $liveConversationId)) {
            $this->deferRelease($owner, $liveConversationId);

            return null;
        }

        try {
            return DB::transaction(function () use ($participant, $project, $owner, $entry, $next, $liveConversationId, $organizationId): ?InterviewSession {
                // Serialise with a release and a concurrent /start on the owner row, then decide on
                // what is true after the lock: a release or a next row that landed since the first
                // read must refuse the grant.
                InterviewSession::query()->whereKey($owner->id)->lockForUpdate()->first();

                if ($this->released($organizationId, $liveConversationId)
                    || InterviewSession::query()->where('participant_id', $participant->id)->where('competency_code', $next['competency_code'])->exists()) {
                    return null;
                }

                $row = new InterviewSession;
                $row->forceFill([
                    'participant_id' => $participant->id,
                    'project_id' => $project->id,
                    'question_index' => $next['question_index'],
                    'competency_code' => $next['competency_code'],
                    'framework_version_id' => $project->framework_version_id,
                    'provider' => 'tavus',
                    'status' => 'in_corso',
                    'provider_session_ref' => $liveConversationId,
                    'primary_questions' => $entry['primary_questions'],
                    'follow_up_budget' => $entry['follow_up_budget'],
                    'started_at' => now()->toImmutable(),
                    'avatar_template_id' => $owner->avatar_template_id,
                    'llm_model_key' => $owner->llm_model_key,
                    'llm_binding_status' => $owner->llm_binding_status,
                    'system_prompt_chars' => $owner->system_prompt_chars,
                    'conversation_prompt_version' => $owner->conversation_prompt_version,
                ])->save();

                $this->liveClock->open($row, $liveConversationId);

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent /start created the row or opened the period first: the ordinary path
            // resumes what exists.
            return null;
        }
    }

    /** Was the conversation behind this ref already released by the deferred job or any other release? */
    private function released(int $organizationId, string $ref): bool
    {
        return InterviewSession::query()
            ->where('organization_id', $organizationId)
            ->where('provider', 'tavus')
            ->where('provider_session_ref', $ref)
            ->whereNotNull('provider_released_at')
            ->exists();
    }

    private function deferRelease(InterviewSession $owner, string $ref): void
    {
        try {
            ReleaseEndedProviderSessionJob::dispatch(
                $owner->id,
                (int) $owner->organization_id,
                'tavus',
                $ref,
                $owner->provider_context_ref,
            )->delay((int) config('interview.provider_release_delay_seconds'))->afterCommit();
        } catch (\Throwable $e) {
            // The conversation is at its ceiling: the provider ends it itself. Never fail the start.
            Log::warning('interview.provider_release.defer_failed', ['session_id' => $owner->id, 'exception' => $e::class]);
        }
    }
}
