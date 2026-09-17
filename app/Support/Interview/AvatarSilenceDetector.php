<?php

declare(strict_types=1);

namespace App\Support\Interview;

use App\Models\InterviewSession;
use App\Models\Utterance;
use Illuminate\Support\Facades\Log;

/**
 * Warns when one provider session stretch holds at least
 * `CANDIDATE_TURN_THRESHOLD` candidate turns and no avatar turn after the
 * opening.
 *
 * That shape is a provider whose conversation LLM stopped answering while
 * the candidate kept talking: the opening is spoken from the provider's own
 * greeting field, so it survives a broken LLM binding, and nothing after it
 * does. The warning carries the binding status because that is the first
 * thing to check. Laravel log records become Sentry breadcrumbs
 * (`sentry.breadcrumbs.logs`), so the warning also reaches any Sentry event
 * the request raises.
 *
 * Observation only: it never changes the transcript or the session.
 */
final class AvatarSilenceDetector
{
    public const int CANDIDATE_TURN_THRESHOLD = 4;

    /**
     * Inspect the persisted turns of one provider session stretch. A null ref
     * names no stretch and is ignored.
     */
    public function inspect(InterviewSession $session, ?string $ref): void
    {
        if ($ref === null) {
            return;
        }

        $speakers = Utterance::where('interview_session_id', $session->id)
            ->where('provider_session_ref', $ref)
            ->orderBy('ts')
            ->orderBy('id')
            ->pluck('speaker')
            ->values()
            ->all();

        $firstAvatar = array_search('avatar', $speakers, true);
        $afterOpening = $firstAvatar === false ? $speakers : array_slice($speakers, $firstAvatar + 1);
        $turns = array_count_values($afterOpening);

        $candidateTurns = $turns['candidate'] ?? 0;
        $avatarTurns = $turns['avatar'] ?? 0;

        if ($candidateTurns < self::CANDIDATE_TURN_THRESHOLD || $avatarTurns > 0) {
            return;
        }

        Log::warning('provider_avatar_silent', [
            'session_id' => $session->id,
            'provider' => $session->provider,
            'llm_binding_status' => $session->llm_binding_status,
            'provider_session_ref' => $ref,
            'candidate_turns' => $candidateTurns,
        ]);
    }
}
