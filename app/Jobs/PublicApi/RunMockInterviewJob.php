<?php

declare(strict_types=1);

namespace App\Jobs\PublicApi;

use App\Actions\Interview\SettleParticipantCompletion;
use App\Enums\ApiKeyMode;
use App\Enums\EvaluationStatus;
use App\Events\CompetencySessionEnded;
use App\Events\EvaluationCompleted;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\InterviewRecording;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Interview\SessionLiveClock;
use App\Support\PublicApi\InterviewEventRecorder;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * RunMockInterviewJob — SPEC.md §3.7 "Test mode" (public-api step 9).
 *
 * Dispatched once, from `InterviewController::handleIssuePending()`, the
 * instant a `beai_test_…` participant's FIRST `/start()` call issues a
 * `MockProvider` session — `->afterCommit()`, mirroring the
 * `FinalizeInterview::dispatch(...)->afterCommit()` precedent already used
 * by `SettleParticipantCompletion`. Because the mock provider makes no real
 * avatar call and the candidate app never drives a test-mode interview
 * competency-by-competency, THIS ONE JOB walks every remaining competency
 * for the participant, not just the one `/start()` issued a session for.
 *
 * Payload is TWO SCALARS, `int $organizationId, int $participantId` — never
 * a model, matching `App\Jobs\PublicApi\GenerateExportJob`/
 * `App\Jobs\ScoreEvaluationJob`'s own identical choice (a `TenantModel`
 * re-resolved through `SerializesModels` under a null ambient queue-context
 * resolver is the exact bug those two classes' own docblocks document
 * avoiding) — no `SerializesModels` trait.
 *
 * Tenancy: `Participant` is a plain `Model` (no global scope, per
 * `SettleParticipantCompletion`'s own comment), so its OWN read here is
 * explicitly `organization_id`-filtered (pre-commit gate, public-api step 9,
 * finding 1) rather than trusted implicitly from `$participantId` alone —
 * the same discipline `App\Listeners\SendEvaluationWebhook` already applies
 * at its own `Participant` reads. Every OTHER model this job touches
 * (`InterviewSession`, `Utterance` via `SettleParticipantCompletion`'s own
 * `Project` read, `Evaluation`, `CompetencyResult`, `IndicatorScore`,
 * `InterviewRecording`) extends `TenantModel` and is read/written entirely
 * inside ONE outer `TenantContextScope::runFor($orgId, …)` call, the same
 * "wrap the whole tenant-scoped block" idiom `GenerateExportJob::handle()`'s
 * own `buildContent()` call already uses — a queued job's ambient
 * `TenantResolver` is reset to null before `handle()` runs (see that
 * class's own docblock for the exact mechanism), so every one of these
 * queries would otherwise silently match zero rows rather than throwing.
 *
 * Reuses `SettleParticipantCompletion::settleIfFinished()` for the
 * `in_corso → in_valutazione` transition (never reimplemented) — its own
 * CAS win dispatches `FinalizeInterview`, which fires `ScoringRequested`,
 * which `App\Listeners\DispatchScoringJob` now short-circuits for a
 * `ApiKeyMode::Test` participant (SPEC.md §3.7 "never billed" — the real,
 * paid, non-deterministic `ScoreEvaluationJob` must never run for a mock
 * interview). This job fabricates the `Evaluation`/`CompetencyResult`/
 * `IndicatorScore` rows itself instead, in the exact shape
 * `App\Jobs\ScoreEvaluationJob::resolveEvaluationTerminalState()`/
 * `scoreCompetency()` persist, then fires the SAME `EvaluationCompleted`
 * event that job fires so `App\Listeners\SendEvaluationWebhook` picks it up
 * with no special-casing.
 *
 * Idempotency (pre-commit gate, public-api step 9, round 3 — R4/R3
 * "check-then-act" findings: the round-1 design note's "no real race" claim
 * was wrong. The controller dispatches a NEW job on EVERY test-mode
 * `/start()` call, not only the first; on a real multi-worker queue, two
 * overlapping runs could both see no `InterviewSession`/`Evaluation` row and
 * both create one, double-firing `EvaluationCompleted` and the evaluation
 * webhook with it):
 *
 *   Layer 0 — a `Cache::add()` NX lock, `'mock-interview:{participantId}'`,
 *     TTL `self::LOCK_TTL_SECONDS`, acquired in `handle()` BEFORE any read —
 *     the same single-winner idiom `FinalizeInterview`'s own `'finalize:
 *     {pid}'` lock already uses in this codebase. A second concurrent (or
 *     merely re-dispatched) run that finds the lock held no-ops immediately;
 *     it never reaches the check-then-act reads below, so they no longer
 *     need to be atomic themselves.
 *   Layer 1 — a terminal participant status (`completato`/`errore`) makes
 *     the WHOLE job a no-op (covers a run that starts AFTER the lock's own
 *     holder already finished and the TTL has since expired).
 *   Layer 2 — an already-ended `InterviewSession` competency is skipped
 *     inside the loop (a partial re-run resumes where it left off).
 *   Layer 3 — an already-created `Evaluation` row skips the
 *     scoring/recording fabrication step AND the `EvaluationCompleted`
 *     refire (round-1 finding 4).
 *
 * This job makes zero external HTTP calls and completes well under the
 * ≤ 30 s budget on every run, whether inline under `QUEUE_CONNECTION=sync`
 * (CI/test) or on a real queue worker in production. `failed()` (round-3
 * finding, R4-mockjob-no-recovery) still exists for the residual case a
 * lock does not cover — `fabricateScoring()` is itself one atomic
 * `DB::transaction()` (round 4, finding 3), so a DB error inside it rolls
 * back cleanly rather than leaving a partial `Evaluation`, but a mid-run
 * exception ELSEWHERE (a `Storage::put()` I/O error in
 * `writeRecordingFixture()`, or anything between a successful
 * `fabricateScoring()` and the `completato` write) after
 * `settleIfFinished()` already moved the participant to `in_valutazione`
 * would still strand it without `failed()`, since `DispatchScoringJob`
 * never dispatches the real `ScoreEvaluationJob` for a test-mode
 * participant and nothing else would ever retry scoring it.
 *
 * REQ: SPEC.md §3.7 "Test mode" (public-api step 9), T-TEST-001..006
 */
class RunMockInterviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * A scripted candidate answer long enough to slice into exactly 3
     * verbatim, non-empty substrings (T-TEST-003's excerpt-containment
     * assertion) — one per fabricated `IndicatorScore` row. Deliberately
     * generic (not competency-specific NLG — design note) but a realistic,
     * professional-register interview answer, never lorem-ipsum filler.
     */
    private const SCRIPTED_QUESTION = 'Please describe a recent professional situation where you had to take initiative, including what you did and the outcome.';

    private const SCRIPTED_ANSWER = 'In my previous role I noticed the team was missing a shared goal, so I proposed a plan and asked for everyone\'s input before we started. I made sure to check in regularly and adjusted our approach when something was not working. In the end we delivered the result on time and the stakeholders were satisfied with the outcome.';

    /**
     * Deterministic BARS scores fabricated for every competency — real
     * values from the {1,2,3,4,5} assessed domain (never -1), so the
     * `indicator_scores_unassessable_reason_check` DB CHECK
     * (`(score = -1) ⇔ (unassessable_reason IS NOT NULL)`) is never in play.
     * Mean 3.6667, mirroring `tests/Helpers/PublicApi/Step6Fixtures::
     * buildCompletedScoredParticipant()`'s own GoldenCassette precedent.
     *
     * @var list<int>
     */
    private const SCORES = [5, 3, 3];

    private const RECORDING_DURATION_SECONDS = 3;

    /**
     * NX lock TTL — see class docblock's Layer 0. Comfortably above the
     * ≤ 30 s spec budget (a stalled run still holds the lock well past its
     * own natural completion time) without leaving a genuinely dead run's
     * participant locked out of a retry indefinitely.
     */
    private const LOCK_TTL_SECONDS = 300;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        private readonly int $organizationId,
        private readonly int $participantId,
    ) {}

    public function handle(SettleParticipantCompletion $settleCompletion, SessionLiveClock $liveClock): void
    {
        // Layer 0 — see class docblock. Acquired FIRST, before any read, so
        // a losing concurrent run never reaches the check-then-act idempotency
        // layers below at all.
        $lockKey = self::lockKey($this->participantId);
        $acquired = Cache::add($lockKey, true, self::LOCK_TTL_SECONDS);
        // Not released on success: the lock's job is to keep two concurrent
        // runs from BOTH acting, not to gate future re-dispatch — the terminal
        // idempotency layers below still make a POST-completion re-run,
        // arriving after the TTL expires, a safe no-op.

        if (! $acquired) {
            Log::info('RunMockInterviewJob: no-op — another run already holds the lock', [
                'participant_id' => $this->participantId,
                'lock_key' => $lockKey,
            ]);

            return;
        }

        // org-filtered (pre-commit gate, public-api step 9, finding 1) — see
        // class docblock.
        $participant = Participant::where('organization_id', $this->organizationId)->find($this->participantId);

        if ($participant === null) {
            Log::warning('RunMockInterviewJob: participant not found', [
                'organization_id' => $this->organizationId,
                'participant_id' => $this->participantId,
            ]);

            return;
        }

        // Defensive — this job must NEVER touch a live participant (SPEC.md
        // §3.7's whole "never billed" guarantee rests on mock scoring being
        // fabricated ONLY for a beai_test_… enrolment). Unreachable through
        // the real dispatch site (`InterviewController::handleIssuePending()`
        // only dispatches this job when `$participant->mode === ApiKeyMode::
        // Test` already gated the provider name to 'mock'), kept as a
        // fail-closed guard against any future dispatch site that forgets to
        // check mode first.
        if ($participant->mode !== ApiKeyMode::Test) {
            Log::error('RunMockInterviewJob: refusing to run against a live-mode participant', [
                'participant_id' => $this->participantId,
            ]);

            return;
        }

        // Idempotency layer 1: a terminal participant is already finished —
        // see class docblock.
        if (in_array($participant->status, ['completato', 'errore'], true)) {
            Log::info('RunMockInterviewJob: no-op — participant already terminal', [
                'participant_id' => $this->participantId,
                'status' => $participant->status,
            ]);

            return;
        }

        TenantContextScope::runFor($participant->organization_id, function () use ($participant, $settleCompletion, $liveClock): void {
            $this->runScriptedInterview($participant, $settleCompletion, $liveClock);
        });
    }

    /**
     * Unrecoverable failure (pre-commit gate, public-api step 9, round 3
     * finding R4-mockjob-no-recovery) — with `$tries = 1`, a mid-run
     * exception (an object-storage error in `writeRecordingFixture()`, or
     * anything between `fabricateScoring()`'s own atomic transaction
     * committing and the `completato` write — round 4, finding 3) leaves
     * this participant stuck at whatever status the loop last wrote, most
     * dangerously `in_valutazione`: `DispatchScoringJob` never dispatches
     * the real, paid `ScoreEvaluationJob` for a test-mode participant
     * (SPEC.md §3.7 "never billed"), so nothing else would ever retry or
     * complete it. `in_attesa`/`in_corso`/`in_valutazione` → `errore` are
     * all legal
     * transitions (`Participant::$allowedTransitions`), so this is safe to
     * call regardless of exactly where the loop failed.
     */
    public function failed(Throwable $e): void
    {
        Log::error('RunMockInterviewJob: job exhausted, participant may be stranded', [
            'participant_id' => $this->participantId,
            'organization_id' => $this->organizationId,
            'error' => $e->getMessage(),
        ]);

        $participant = Participant::where('organization_id', $this->organizationId)->find($this->participantId);

        if ($participant === null || in_array($participant->status, ['completato', 'errore'], true)) {
            return;
        }

        TenantContextScope::runFor($this->organizationId, function () use ($participant): void {
            $participant->status = 'errore';
            $participant->save();

            InterviewEventRecorder::error($this->organizationId, $participant->id);
        });
    }

    private static function lockKey(int $participantId): string
    {
        return 'mock-interview:'.$participantId;
    }

    private function runScriptedInterview(Participant $participant, SettleParticipantCompletion $settleCompletion, SessionLiveClock $liveClock): void
    {
        $orgId = $participant->organization_id;
        $projectId = $participant->project_id;

        $project = Project::find($projectId);

        if ($project === null) {
            Log::error('RunMockInterviewJob: project not found', [
                'participant_id' => $participant->id,
                'project_id' => $projectId,
            ]);

            return;
        }

        $competencies = $project->competencies()->get();

        if ($competencies->isEmpty()) {
            Log::warning('RunMockInterviewJob: project has no competencies — nothing to mock', [
                'participant_id' => $participant->id,
                'project_id' => $projectId,
            ]);

            return;
        }

        foreach ($competencies as $competency) {
            $code = (string) $competency->code;

            $session = InterviewSession::where('participant_id', $participant->id)
                ->where('competency_code', $code)
                ->first();

            // Idempotency layer 2: this competency already ended — either
            // this loop already ran once (partial re-run), or (for the
            // FIRST competency only) it is impossible for it to already be
            // ended, since the controller only ever dispatches this job
            // right after issuing a fresh pending/in_corso session.
            if ($session !== null && in_array($session->status, ['completed', 'timeout', 'skipped'], true)) {
                continue;
            }

            $session = $this->startCompetencySession($participant, $project, $competency, $session, $orgId, $liveClock);

            $this->writeScriptedTranscript($session);

            // close() BEFORE the completed write (pre-commit gate,
            // public-api step 9, finding 3): every real `in_corso` exit
            // closes its live period through this same class — a session
            // this job marks `completed` without doing so would leave one
            // `interview_session_live_periods` row open forever, breaking
            // the D5 "at most one open period" invariant. `'end'` matches
            // the real `Candidate\InterviewController::end()`'s own reason
            // string for an ordinary completion.
            $liveClock->close($session, 'end');

            $session->status = 'completed';
            $session->ended_reason = 'completed';
            $session->ended_at = now()->toImmutable();
            $session->save();

            InterviewEventRecorder::sessionEnded($orgId, $participant->id);

            event(new CompetencySessionEnded($participant->id, $projectId, $code));

            // Safe to call after EVERY competency (single-winner CAS) — see
            // class docblock. Wins only after the LAST one.
            $settleCompletion->settleIfFinished($participant->id, $projectId);
        }

        $participant->refresh();

        if ($participant->status !== 'in_valutazione') {
            Log::warning('RunMockInterviewJob: participant did not reach in_valutazione after the scripted loop', [
                'participant_id' => $participant->id,
                'status' => $participant->status,
            ]);

            return;
        }

        // Idempotency layer 3: scoring already fabricated by an earlier run.
        $existingEvaluation = Evaluation::where('participant_id', $participant->id)->first();
        $evaluation = $existingEvaluation ?? $this->fabricateScoring($participant, $project, $competencies);

        if (InterviewRecording::where('participant_id', $participant->id)->doesntExist()) {
            $this->writeRecordingFixture($participant);
        }

        $participant->refresh();

        if ($participant->status === 'in_valutazione') {
            $participant->status = 'completato';
            $participant->save();

            InterviewEventRecorder::completed($orgId, $participant->id);
        }

        InterviewEventRecorder::scoringReady($orgId, $participant->id);

        // Fired ONLY on the path that actually created $evaluation
        // (pre-commit gate, public-api step 9, finding 4): a genuinely
        // concurrent second dispatch that reaches this point after the
        // first already fabricated scoring would otherwise re-send the
        // evaluation webhook for the same evaluation a second time — the
        // "no real race" design note covers the double-dispatch shape this
        // whole idempotency layer defends against, never a reason to skip
        // guarding the one side effect that reaches an external receiver.
        if ($existingEvaluation === null) {
            event(new EvaluationCompleted($evaluation->id));
        }
    }

    /**
     * Create (or reuse the controller-created) session row and advance it to
     * `in_corso`, mirroring `InterviewController::handleIssuePending()`'s own
     * `started_at`/`status` write-once idiom for the participant.
     *
     * `$liveClock->open()` fires ONLY when THIS call creates the session
     * (pre-commit gate, public-api step 9, finding 3): `$existing` is the
     * one session the controller's own `handleIssuePending()` already
     * opened a live period for, inside the SAME transaction that dispatched
     * this job — re-opening it here would insert a SECOND open period for
     * that session, rejected by the D5 partial unique index (`at most one
     * open period`). Every subsequent competency's session is one this job
     * creates itself, so it is this job's job to open it, mirroring the
     * real `open()` call sites' own "issue implies open" pairing.
     */
    private function startCompetencySession(
        Participant $participant,
        Project $project,
        Competency $competency,
        ?InterviewSession $existing,
        int $orgId,
        SessionLiveClock $liveClock,
    ): InterviewSession {
        $isNewSession = $existing === null;

        $session = $existing ?? InterviewSession::create([
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => (int) $competency->pivot->getAttribute('position'),
            'competency_code' => (string) $competency->code,
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'mock',
            'status' => 'pending',
        ]);

        $isFirstCompetency = $participant->started_at === null;
        $enteringInCorso = $participant->status !== 'in_corso';

        $session->status = 'in_corso';
        // Str::ulid() (pre-commit gate, public-api step 9, finding 5): matches
        // MockProvider::issue()'s own generator exactly — 'mock_'.uniqid('mock_',
        // true) produced 'mock_mock_…' refs, a second, drifting ID format for the
        // same concept.
        $session->provider_session_ref ??= 'mock_'.(string) Str::ulid();
        $session->started_at ??= now()->toImmutable();
        $session->save();

        if ($isNewSession) {
            $liveClock->open($session, $session->provider_session_ref);
        }

        if ($isFirstCompetency) {
            $participant->started_at = now();
        }

        if ($enteringInCorso) {
            $participant->status = 'in_corso';
        }

        if ($participant->isDirty()) {
            $participant->save();
        }

        if ($enteringInCorso) {
            InterviewEventRecorder::sessionStarted($orgId, $participant->id);
        }

        return $session;
    }

    private function writeScriptedTranscript(InterviewSession $session): void
    {
        $now = now();

        $session->utterances()->create([
            'speaker' => 'avatar',
            'text' => self::SCRIPTED_QUESTION,
            'ts' => $now,
        ]);

        $session->utterances()->create([
            'speaker' => 'candidate',
            'text' => self::SCRIPTED_ANSWER,
            'ts' => $now->copy()->addSeconds(5),
        ]);
    }

    /**
     * DB::transaction() (pre-commit gate, public-api step 9, round 4
     * finding 3): the whole Evaluation/CompetencyResult/IndicatorScore
     * fabrication is ONE atomic unit — without it, a DB error partway
     * through (e.g. on the 2nd competency's rows) would leave a `completed`
     * Evaluation with only some competencies scored, permanently, since
     * this method never runs a second time for a participant that already
     * has an Evaluation row (idempotency layer 3). Mirrors
     * `App\Jobs\ScoreEvaluationJob`'s own identical discipline for its real
     * scoring persistence.
     *
     * @param  Collection<int, Competency>  $competencies
     */
    private function fabricateScoring(Participant $participant, Project $project, Collection $competencies): Evaluation
    {
        /** @param  Collection<int, Competency>  $competencies */
        return DB::transaction(function () use ($participant, $project, $competencies): Evaluation {
            $evaluation = Evaluation::create([
                'participant_id' => $participant->id,
                'status' => EvaluationStatus::Completed->value,
                'framework_version_id' => $project->framework_version_id,
                'model_version' => (string) config('scoring.model_version'),
                'prompt_version' => (string) config('scoring.prompt_version'),
                'evaluated_at' => now(),
                'retry_attempt' => false,
            ]);

            // review-readability round-3 finding R2-dead-answer-map: every
            // competency gets the SAME scripted answer (the mock path is
            // uniform by design, never per-competency NLG) — the constant
            // used directly here, rather than through a map that always
            // resolved to it anyway.
            $excerpts = $this->splitIntoVerbatimExcerpts(self::SCRIPTED_ANSWER);

            foreach ($competencies as $competency) {
                $code = (string) $competency->code;

                $competencyResult = CompetencyResult::create([
                    'evaluation_id' => $evaluation->id,
                    'competency_code' => $code,
                    'score' => array_sum(self::SCORES) / count(self::SCORES),
                    'reliability' => 1.0,
                    'valid' => true,
                    'unscorable_reason' => null,
                ]);

                foreach (self::SCORES as $position => $score) {
                    IndicatorScore::create([
                        'competency_result_id' => $competencyResult->id,
                        'position' => $position,
                        'indicator_text' => sprintf('Mock indicator %d for %s', $position + 1, $code),
                        'score' => $score,
                        'explanation' => 'SPEC.md §3.7 test-mode fabricated score.',
                        'excerpts' => [$excerpts[$position]],
                        'unassessable_reason' => null,
                    ]);
                }
            }

            return $evaluation;
        });
    }

    /**
     * Splits `self::SCRIPTED_ANSWER` (or any answer built from it) on the
     * sentence boundary `. ` — each resulting piece is, by construction, an
     * exact substring of the original text (splitting removes only the
     * separator itself, never rewrites either side), guaranteeing the
     * verbatim-excerpt contract `indicator_scores.excerpts` requires
     * (T-TEST-003) without a second, independently-written copy of the
     * answer text to drift from the first.
     *
     * @return list<string>
     */
    private function splitIntoVerbatimExcerpts(string $text): array
    {
        return array_values(array_filter(
            explode('. ', $text),
            static fn (string $part): bool => $part !== '',
        ));
    }

    private function writeRecordingFixture(Participant $participant): void
    {
        $wav = $this->buildSilentWavFixture();

        $objectKey = sprintf('recordings/%d/%d/interview.wav', $participant->organization_id, $participant->id);

        Storage::disk()->put($objectKey, $wav);

        InterviewRecording::create([
            'participant_id' => $participant->id,
            'object_key' => $objectKey,
            'format' => 'wav',
            'duration_seconds' => self::RECORDING_DURATION_SECONDS,
            'size_bytes' => strlen($wav),
        ]);
    }

    /**
     * A minimal but genuinely valid, playable WAV file — a 44-byte
     * RIFF/WAVE/fmt/data header followed by silent 16-bit mono PCM samples
     * — so `App\Http\Controllers\PublicApi\RecordingController`'s existing
     * signed-URL read endpoint never serves a broken link for a test-mode
     * interview (design note). No audio library dependency: `composer.json`
     * carries none, and a fixed-format PCM header is a handful of `pack()`
     * calls, not a reason to add one.
     */
    private function buildSilentWavFixture(): string
    {
        $sampleRate = 8000;
        $bitsPerSample = 16;
        $channels = 1;
        $numSamples = $sampleRate * self::RECORDING_DURATION_SECONDS;
        $dataSize = $numSamples * $channels * intdiv($bitsPerSample, 8);
        $byteRate = $sampleRate * $channels * intdiv($bitsPerSample, 8);
        $blockAlign = $channels * intdiv($bitsPerSample, 8);

        $header = 'RIFF'
            .pack('V', 36 + $dataSize)
            .'WAVE'
            .'fmt '
            .pack('V', 16)
            .pack('v', 1)
            .pack('v', $channels)
            .pack('V', $sampleRate)
            .pack('V', $byteRate)
            .pack('v', $blockAlign)
            .pack('v', $bitsPerSample)
            .'data'
            .pack('V', $dataSize);

        return $header.str_repeat("\0", $dataSize);
    }
}
