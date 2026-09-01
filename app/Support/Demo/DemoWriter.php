<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\DTOs\Scoring\IndicatorScoreDTO;
use App\Enums\EvaluationStatus;
use App\Enums\WebhookEventType;
use App\Models\AiRequest;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\IntegrityEvent;
use App\Models\InterviewSession;
use App\Models\InterviewSnapshot;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Models\Utterance;
use App\Models\WebhookDelivery;
use App\Services\Scoring\AssessableFractionReliability;
use App\Services\Scoring\CompletionGate;
use App\Services\Scoring\ExcerptValidator;
use App\Services\Scoring\MeanCalculator;
use App\Services\Scoring\ThresholdValidityPredicate;
use App\Services\Scoring\TranscriptAssembler;
use App\Services\Webhooks\EvaluationPayloadAssembler;
use App\Services\Webhooks\ProgressPayloadAssembler;
use App\Support\AvatarTemplates\ConfigValidator;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
use App\Support\Observability\AiRequestCostEstimator;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Writes the demo dataset (design volume table) into an organization that
 * already exists, entirely inside one `TenantContextScope::runFor` (design
 * D7) — the wrapper `beai:demo-seed` establishes around the whole seed.
 *
 * Idempotent PER ROW, not just at the command level: every writer here
 * checks for an existing marked row before creating one. This is what makes
 * a second run on an already-complete dataset a true no-op, and what lets a
 * run interrupted between "participant row committed" and "its evaluation
 * committed" be completed by a second run without ever touching the
 * participant's already-terminal `status` column (design D3 — no UPDATE, no
 * transition; only the missing descendant rows get inserted).
 */
final class DemoWriter
{
    public function __construct(
        private readonly EvaluationPayloadAssembler $evaluationPayloadAssembler,
        private readonly ProgressPayloadAssembler $progressPayloadAssembler,
    ) {}

    public function write(Command $command, Organization $organization): int
    {
        $summary = TenantContextScope::runFor($organization->id, function () use ($command, $organization): array {
            $version = $this->writeFrameworkVersion($organization);
            $templates = $this->writeAvatarTemplates($command, $organization);
            $projects = $this->writeProjects($organization, $version);
            $participants = $this->writeParticipants($organization, $projects, $version);
            $evaluations = $this->writeEvaluations($version, $participants, $projects);
            $aiRequestCount = $this->writeAiRequests($evaluations);
            $deliveries = $this->writeWebhookDeliveries($participants, $projects, $evaluations);
            $this->writeProctoringEvents($participants);
            $snapshotCount = $this->writeSnapshots($organization, $participants);
            $apiClients = $this->writeApiClients($organization);
            // Runs LAST (design D18): every subject it can reference
            // (participants, webhook_deliveries) must already be persisted.
            $notificationLogCount = $this->writeNotificationLogs($participants, $deliveries);

            return [
                'snapshot_count' => $snapshotCount,
                'version' => $version,
                'templates' => $templates,
                'projects' => $projects,
                'participants' => $participants,
                'evaluations' => $evaluations,
                'api_clients' => $apiClients,
                'ai_request_count' => $aiRequestCount,
                'deliveries' => $deliveries,
                'notification_log_count' => $notificationLogCount,
            ];
        });

        $this->report($command, $summary);

        return Command::SUCCESS;
    }

    /**
     * `beai-demo-1.0.0`, created UNLOCKED. `is_locked` is set only by
     * `ProjectController::store` (design D8) — never by this command, and
     * never by the model.
     */
    private function writeFrameworkVersion(Organization $organization): FrameworkVersion
    {
        return FrameworkVersion::firstOrCreate(
            ['organization_id' => $organization->id, 'version' => DemoMarker::PREFIX.'1.0.0'],
            ['label' => 'Demo framework version'],
        );
    }

    /**
     * Two templates: `beai-demo-heygen-it` (active) and `beai-demo-tavus-en`
     * (inactive — only one template may be active per org,
     * `avatar_templates_one_active_per_org`). If an active template already
     * exists for this org, the demo HeyGen one is created INACTIVE instead
     * and the collision is reported — never a second active row, never an
     * index violation.
     *
     * Identifiers come from env (falling back to the committed values named
     * in the proposal); everything else is a tuning knob validated against
     * `ConfigValidator` before save, so an unknown key is caught here rather
     * than surfacing later in the backoffice.
     *
     * @return list<AvatarTemplate>
     */
    private function writeAvatarTemplates(Command $command, Organization $organization): array
    {
        $identity = DemoDataset::avatarIdentity();

        $alreadyActive = AvatarTemplate::where('is_active', true)->exists();

        if ($alreadyActive) {
            $command->warn('An avatar template is already active for this organization — the demo HeyGen template is created INACTIVE to avoid a unique-index collision.');
        }

        $definitions = [
            [
                'name' => DemoMarker::PREFIX.'heygen-it',
                'description' => 'Demo Italian interviewer (HeyGen).',
                'provider' => 'heygen',
                'is_active' => ! $alreadyActive,
                'config' => [
                    'avatarId' => $identity['heygen']['avatarId'],
                    'voiceId' => $identity['heygen']['voiceId'],
                    'interactivityType' => 'CONVERSATIONAL',
                    // The full plan allowance, not a literal: it shipped 600s and a
                    // five-question STAR probe overran it in production on 2026-08-25,
                    // disconnecting the room while the candidate was still speaking.
                    'maxSessionDurationSec' => ProviderFieldSpecs::HEYGEN_MAX_SECONDS,
                    'videoQuality' => 'high',
                    'videoEncoding' => 'H264',
                    'voiceSpeed' => 1.0,
                    'voiceStability' => 0.5,
                    'voiceSimilarityBoost' => 0.75,
                    'voiceStyle' => 0.2,
                    'voiceUseSpeakerBoost' => true,
                ],
            ],
            [
                'name' => DemoMarker::PREFIX.'tavus-en',
                'description' => 'Demo English interviewer (Tavus). Inactive: only one template may be active per organization.',
                'provider' => 'tavus',
                'is_active' => false,
                'config' => [
                    'faceId' => $identity['tavus']['faceId'],
                    'palId' => $identity['tavus']['palId'],
                    'audioOnly' => false,
                    'maxCallDurationSec' => 900,
                    'participantAbsentTimeoutSec' => 60,
                    'enableRecording' => false,
                    'enableClosedCaptions' => true,
                    // 'llmModel' removed (pluggable-conversation-llm PR P3a,
                    // design D3): the field no longer exists in
                    // ProviderFieldSpecs::tavus() — the real binding now owns
                    // the PAL path it used to write.
                    'llmTemperature' => 0.0,
                    'llmSpeculativeInference' => true,
                    'ttsEngine' => 'tavus-auto',
                    'turnTakingPatience' => 'medium',
                    'interruptibility' => 'low',
                    'voiceIsolation' => 'near',
                    'idleEngagement' => 'patient',
                ],
            ],
        ];

        $created = [];

        foreach ($definitions as $definition) {
            $errors = ConfigValidator::validate($definition['provider'], $definition['config']);

            if ($errors !== []) {
                // Fixture bug, not an operator input — fail loudly rather
                // than persist a config the product itself would reject.
                throw new RuntimeException(
                    "Demo avatar template [{$definition['name']}] config failed ConfigValidator: ".json_encode($errors)
                );
            }

            $template = AvatarTemplate::where('name', $definition['name'])->first();

            if ($template === null) {
                $template = new AvatarTemplate;
                $template->forceFill($definition)->save();
                $template->refresh();
            }

            $created[] = $template;
        }

        return $created;
    }

    /**
     * Projects + `project_competencies` (legacy Defect A — the pivot
     * `AdminEvaluationSerializer`/`EvaluationPayloadAssembler`/
     * `ProgressPayloadAssembler` all resolve competency order and count
     * from). P4 is created directly with `status = 'archived'` — the
     * `Project::booted()` guard only fires on `updating`, so a fresh INSERT
     * may set any status; `draft → archived` in two saves is illegal and
     * never attempted.
     *
     * @return array<string, Project>
     */
    private function writeProjects(Organization $organization, FrameworkVersion $version): array
    {
        // `projects.avatar_template_id` is NOT NULL — every project names the
        // template it runs on. `writeAvatarTemplates()` runs BEFORE this method
        // for exactly that reason, so one always exists by now; the guard is
        // here because a silent null would surface as a raw constraint
        // violation halfway through seeding, with no indication of which step
        // was actually at fault.
        $templateId = AvatarTemplate::query()->orderByDesc('is_active')->orderBy('id')->value('id');

        if ($templateId === null) {
            throw new RuntimeException(
                'Demo seeding cannot create projects: no avatar template exists for this '
                .'organization, and `projects.avatar_template_id` is required. '
                .'`writeAvatarTemplates()` must run first.'
            );
        }

        $projects = [];

        foreach (DemoDataset::projects() as $definition) {
            $project = Project::withTrashed()->where('slug', $definition['slug'])->first();

            if ($project === null) {
                $project = Project::create([
                    'framework_version_id' => $version->id,
                    'slug' => $definition['slug'],
                    'name' => $definition['name'],
                    'assessment_type' => $definition['assessment_type'],
                    'role_code' => $definition['role_code'],
                    'language' => $definition['language'],
                    'status' => $definition['status'],
                    'pause_every_n_competencies' => $definition['pause_every_n_competencies'],
                    'nudge_min_chars' => $definition['nudge_min_chars'],
                    // Active-first, so the demo's projects run on the template
                    // an operator would see selected — not an arbitrary one.
                    'avatar_template_id' => $templateId,
                ]);

                $competencyIds = Competency::whereIn('code', $definition['competencies'])
                    ->get()
                    ->keyBy('code');

                $attach = [];

                foreach ($definition['competencies'] as $position => $code) {
                    $competency = $competencyIds[$code] ?? null;

                    if ($competency === null) {
                        throw new RuntimeException("Demo fixture references unknown competency code [{$code}].");
                    }

                    $attach[$competency->id] = ['position' => $position];
                }

                $project->competencies()->attach($attach);
            }

            $this->applyWebhookConfigTopUp($project, $definition['key']);

            $projects[$definition['key']] = $project;
        }

        return $projects;
    }

    /**
     * Webhook configuration top-up (design D13). `writeProjects()` above
     * skips any project that already exists — on a production top-up
     * against an OLDER dataset, that would leave P1/P2/P4 forever
     * unconfigured while their `webhook_deliveries` rows claim otherwise.
     * This runs for BOTH branches (new and pre-existing project), and only
     * fills `webhook_*` when `webhook_url IS NULL` — a project already
     * configured (by an earlier run of this same top-up, or by an operator)
     * is never touched again.
     *
     * Verified safe against `Project::booted()`'s immutability guard
     * (`Project.php:118-159`): that guard only fires when
     * `assessment_type`/`framework_version_id`/`role_code` is dirty, and
     * none of those three is ever set here — so the archived P4 updates
     * cleanly.
     *
     * `webhook_secret` is minted from `random_bytes` and is NEVER authored
     * in `DemoDataset` (design D16's deliberate randomness exception) — P4
     * deliberately gets no secret (`has_secret = false`), the prerequisite
     * for its `no_webhook_secret` skip reason (design D13).
     */
    private function applyWebhookConfigTopUp(Project $project, string $projectKey): void
    {
        $definition = DemoDataset::webhookConfig()[$projectKey] ?? null;

        if ($definition === null) {
            // P3 carries no webhook configuration by design (no
            // participants — a draft project must not be reachable).
            return;
        }

        if ($project->webhook_url !== null) {
            // Already configured — by a prior run of this top-up, or by an
            // operator. Never overwritten.
            return;
        }

        $project->webhook_url = $definition['webhook_url'];
        $project->webhook_events = $definition['webhook_events'];

        if ($definition['has_secret']) {
            $project->webhook_secret = bin2hex(random_bytes(32));
        }

        $project->save();
    }

    /**
     * `Participant` is a plain `Model`, not `TenantModel` (design D7):
     * `organization_id` is excluded from `$fillable` as a C2 security
     * invariant, so it is set via `forceFill()`, never `create()` — mass
     * assignment would silently drop it and the INSERT would die on a
     * NOT NULL violation.
     *
     * Each participant is written at its INTENDED FINAL status in one
     * INSERT. `Participant::booted()` guards `updating` only, so this never
     * attempts a transition — the terminal-state guard never fires because
     * no transition is ever asked for.
     *
     * Idempotent by candidate_ref: if the participant row already exists
     * (a prior run committed it), it is left untouched and only its
     * sessions/evaluation are completed if missing — see `writeEvaluations`.
     *
     * @param  array<string, Project>  $projects
     * @return array<string, Participant>
     */
    private function writeParticipants(Organization $organization, array $projects, FrameworkVersion $version): array
    {
        $participants = [];

        foreach (DemoDataset::participants() as $definition) {
            $project = $projects[$definition['project_key']] ?? null;

            if ($project === null) {
                throw new RuntimeException("Demo fixture references unknown project [{$definition['project_key']}].");
            }

            $participant = Participant::where('organization_id', $organization->id)
                ->where('candidate_ref', $definition['ref'])
                ->first();

            $isNew = $participant === null;

            if ($isNew) {
                $participant = new Participant;
                $participant->forceFill([
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'candidate_ref' => $definition['ref'],
                    'display_name' => $definition['name'],
                    'role_code' => $project->role_code,
                    'language' => $project->language,
                    'status' => $definition['status'],
                    'started_at' => $definition['status'] === 'in_attesa' ? null : now()->subHours(3),
                    'completed_at' => in_array($definition['status'], ['completato', 'errore'], true) ? now()->subHour() : null,
                ])->save();
                $participant->refresh();
            }

            $this->writeSessions($organization, $participant, $project, $definition, $isNew);

            $participants[$definition['key']] = $participant;
        }

        return $participants;
    }

    /**
     * @param  array{sessions: list<array{competency: string, status: string, ended_reason: ?string, live: bool}>}  $definition
     */
    private function writeSessions(Organization $organization, Participant $participant, Project $project, array $definition, bool $participantIsNew): void
    {
        if (! $participantIsNew && InterviewSession::where('participant_id', $participant->id)->exists()) {
            // Sessions for this participant were already written by a prior
            // run — nothing to complete here (evaluation completion, if
            // still missing, is handled separately in writeEvaluations()).
            return;
        }

        $positionByCode = array_flip($project->competencies()->pluck('code')->all());
        $content = DemoDataset::competencyContent()[$project->language] ?? [];
        $baseTime = now()->subHours(3);

        foreach ($definition['sessions'] as $sessionDefinition) {
            $competencyCode = $sessionDefinition['competency'];
            $position = $positionByCode[$competencyCode] ?? 0;

            // (interview-session-started-at, D9) A DETERMINISTIC per-competency
            // length derived from `position` — never the flat 20-minute
            // HeyGen ceiling that made every demo interview read as a
            // measurement. Seeders are re-run and DemoDatasetValidator
            // asserts invariants, so randomness is not available; comfortably
            // below the 1200s ceiling.
            $durationSeconds = 300 + ($position % 5) * 96;

            $session = new InterviewSession;
            $session->forceFill([
                'participant_id' => $participant->id,
                'project_id' => $project->id,
                'question_index' => $position,
                'competency_code' => $competencyCode,
                'framework_version_id' => $project->framework_version_id,
                'provider' => 'heygen',
                // The marker half — the reliable discriminator between demo
                // and real rows — stays unchanged (D9).
                'provider_session_ref' => DemoMarker::PREFIX.'s-'.$participant->id.'-'.$competencyCode,
                'status' => $sessionDefinition['status'],
                'ended_reason' => $sessionDefinition['ended_reason'],
                'started_at' => $baseTime,
                'ended_at' => $sessionDefinition['status'] === 'in_corso' ? null : $baseTime->copy()->addSeconds($durationSeconds),
            ])->save();
            $session->refresh();

            $this->writeSessionLivePeriods($session, $sessionDefinition['status'], $baseTime, $durationSeconds);

            $this->writeUtterances($session, $content[$competencyCode] ?? [], $project->language, $sessionDefinition, $baseTime);

            $baseTime = $baseTime->copy()->addHour();
        }
    }

    /**
     * `interview_session_live_periods` for one demo session
     * (interview-session-started-at, D9). Mirrors the session's own
     * `started_at`/`ended_at` (or leaves it open for `in_corso`) with ONE
     * exception: the FIRST `completed` session this writer encounters
     * across the whole dataset is deliberately given TWO closed periods
     * with a gap between them, so the demo exercises the resumed-interview
     * shape the product now supports — an operator can see what a resumed
     * interview looks like without needing a real one. Periods cascade on
     * session delete (FK cascade, Phase 1); demo teardown needs no edit.
     */
    private function writeSessionLivePeriods(InterviewSession $session, string $status, Carbon $baseTime, int $durationSeconds): void
    {
        if ($status === 'in_corso') {
            $session->livePeriods()->create([
                'provider_session_ref' => $session->provider_session_ref,
                'started_at' => $baseTime,
                'ended_at' => null,
                'closed_reason' => null,
            ]);

            return;
        }

        if ($status !== 'completed' && $status !== 'timeout' && $status !== 'skipped') {
            // `error` (and any other terminal-without-a-real-stretch status)
            // never went live long enough to warrant a period — matches the
            // real path's markSessionError, which closes a period only when
            // one was actually open.
            return;
        }

        if (! $this->resumedDemoWritten && $status === 'completed') {
            $this->resumedDemoWritten = true;

            $firstEnd = $baseTime->copy()->addSeconds((int) ($durationSeconds / 2));
            $gapEnd = $firstEnd->copy()->addHours(3);

            $session->livePeriods()->create([
                'provider_session_ref' => DemoMarker::PREFIX.'s-'.$session->participant_id.'-'.$session->competency_code.'-r1',
                'started_at' => $baseTime,
                'ended_at' => $firstEnd,
                'closed_reason' => 'resume',
            ]);
            $session->livePeriods()->create([
                'provider_session_ref' => $session->provider_session_ref,
                'started_at' => $gapEnd,
                'ended_at' => $session->ended_at,
                'closed_reason' => 'end',
            ]);

            return;
        }

        $session->livePeriods()->create([
            'provider_session_ref' => $session->provider_session_ref,
            'started_at' => $baseTime,
            'ended_at' => $session->ended_at,
            'closed_reason' => $status === 'completed' ? 'end' : $status,
        ]);
    }

    /**
     * Set once this writer produces the demo's single resumed-interview
     * session (D9). Per-instance, not static: one `DemoWriter` is
     * constructed per `write()` call, so a fresh false start is guaranteed
     * on every re-run.
     */
    private bool $resumedDemoWritten = false;

    /**
     * 3 avatar + 3 candidate turns for a completed session; a live
     * (`in_corso`) session gets one turn of each (the interview is still in
     * progress); an errored session gets only the opening avatar question
     * (it failed before the candidate could answer).
     *
     * @param  list<array{text: string, excerpt_sentence: int}>  $answers
     * @param  array{status: string}  $sessionDefinition
     */
    private function writeUtterances(InterviewSession $session, array $answers, string $language, array $sessionDefinition, Carbon $baseTime): void
    {
        $avatarPrompt = DemoDataset::avatarPrompt($language);

        // The interview failed before the candidate could respond — only
        // the opening avatar question was ever sent.
        if ($sessionDefinition['status'] === 'error') {
            Utterance::create([
                'interview_session_id' => $session->id,
                'speaker' => 'avatar',
                'text' => $avatarPrompt,
                'ts' => $baseTime,
            ]);

            return;
        }

        // A live (`in_corso`) session is mid-conversation: one turn of each.
        // A completed session gets the full authored exchange.
        $turns = $sessionDefinition['status'] === 'in_corso' ? 1 : min(3, count($answers));

        for ($turn = 0; $turn < $turns; $turn++) {
            $ts = $baseTime->copy()->addMinutes($turn * 4);

            Utterance::create([
                'interview_session_id' => $session->id,
                'speaker' => 'avatar',
                'text' => $avatarPrompt,
                'ts' => $ts,
            ]);

            $answer = $answers[$turn] ?? null;

            if ($answer !== null) {
                Utterance::create([
                    'interview_session_id' => $session->id,
                    'speaker' => 'candidate',
                    'text' => $answer['text'],
                    'ts' => $ts->copy()->addMinutes(1),
                ]);
            }
        }
    }

    /**
     * Evaluation / CompetencyResult / IndicatorScore writer (design D5).
     *
     * The fixture authors ONLY the score vector. `score`, `reliability` and
     * `valid` are computed with the same production classes a real scoring
     * run uses — `MeanCalculator`, `AssessableFractionReliability`,
     * `ThresholdValidityPredicate` — and the evaluation's terminal status
     * with `CompletionGate`. Nothing here is a hardcoded number.
     *
     * `unscorable_reason` is always NULL (never the legacy, out-of-domain
     * `'no_assessable_evidence'` — Defect D; the real engine writes NULL for
     * the all-`-1` case, `ScoreEvaluationJob.php:715-722`).
     *
     * Excerpts are a sentence-index SLICE of the exact answer text already
     * persisted as the candidate's `Utterance` (writeSessions ran first, in
     * the same request), then checked with the production `ExcerptValidator`
     * against `TranscriptAssembler::assembleForParticipant()->validation` (the
     * candidate-only corpus) before the row is written —
     * so a fixture bug throws here, loudly, rather than persisting a
     * fabricated quotation.
     *
     * A participant whose evaluation is `processing` (transcript exists,
     * scoring not finished) gets an Evaluation row with zero
     * CompetencyResult rows — deliberately: it models a real in-flight
     * evaluation, not an oversight.
     *
     * Idempotent by participant: an Evaluation that already exists is left
     * alone; if it exists but has zero CompetencyResult rows and the fixture
     * says this participant SHOULD be scored, scoring is completed now
     * (design D3 — the interrupted-mid-run repair path). No transition is
     * ever attempted on the Participant row itself.
     *
     * @param  array<string, Participant>  $participants
     * @param  array<string, Project>  $projects
     * @return array<string, Evaluation>
     */
    private function writeEvaluations(FrameworkVersion $version, array $participants, array $projects): array
    {
        $meanCalculator = new MeanCalculator;
        $reliabilityStrategy = new AssessableFractionReliability;
        $validityPredicate = new ThresholdValidityPredicate;
        $completionGate = new CompletionGate;
        $excerptValidator = new ExcerptValidator;
        $transcriptAssembler = new TranscriptAssembler;
        $roleIdByCode = [];

        $evaluations = [];

        foreach (DemoDataset::participants() as $definition) {
            if ($definition['evaluation'] === null) {
                continue;
            }

            $participant = $participants[$definition['key']];
            $project = $projects[$definition['project_key']];

            $evaluation = Evaluation::where('participant_id', $participant->id)->first();

            if ($evaluation === null) {
                $evaluation = new Evaluation;
                $evaluation->forceFill([
                    'participant_id' => $participant->id,
                    'framework_version_id' => $version->id,
                    'status' => EvaluationStatus::Processing,
                    'model_version' => 'beai-demo-fixture',
                    'prompt_version' => 'beai-demo-fixture-v1',
                    'evaluated_at' => null,
                    'retry_attempt' => false,
                ])->save();
                $evaluation->refresh();
            }

            $evaluations[$definition['key']] = $evaluation;

            if (! $definition['evaluation']['scored']) {
                // processing, zero results by design — the transcript exists
                // but scoring has not finished. Nothing more to write.
                continue;
            }

            if (CompetencyResult::where('evaluation_id', $evaluation->id)->exists()) {
                // Already scored by a prior run.
                continue;
            }

            $roleCode = $project->role_code;

            if ($roleCode === null) {
                // Every demo project is `standard` (design D10) and standard
                // projects always carry a role_code — a null here means the
                // fixture itself is broken, not a state to score around.
                throw new RuntimeException("Demo project [{$project->slug}] has no role_code — cannot score its participants.");
            }

            $roleIdByCode[$roleCode] ??= Role::where('code', $roleCode)->value('id');
            $roleId = $roleIdByCode[$roleCode];

            $validCount = 0;
            $totalCount = count($definition['scores']);

            foreach ($definition['scores'] as $competencyCode => $scores) {
                // Kept as the "this competency really has a session" assertion
                // on the fixture, even though the corpora are participant-wide.
                InterviewSession::where('participant_id', $participant->id)
                    ->where('competency_code', $competencyCode)
                    ->firstOrFail();

                // Candidate-only corpus (evaluator-evidence-and-rigor D-1/D-7):
                // demo excerpts are sentence slices of the candidate's own
                // Utterance, so they validate under the stricter rule — but the
                // seed ships to PRODUCTION, so it is checked, never assumed.
                $transcript = $transcriptAssembler
                    ->assembleForParticipant($participant->id, $competencyCode)
                    ->validation;

                $meanScore = $meanCalculator->compute($scores);
                $reliabilityValue = $reliabilityStrategy->compute($scores);
                $isValid = $validityPredicate->isValid($reliabilityValue);

                if ($isValid) {
                    $validCount++;
                }

                $result = new CompetencyResult;
                $result->forceFill([
                    'evaluation_id' => $evaluation->id,
                    'competency_code' => $competencyCode,
                    'score' => $meanScore,
                    'reliability' => round($reliabilityValue, 4),
                    'valid' => $isValid,
                    'unscorable_reason' => null,
                ])->save();
                $result->refresh();

                $answers = DemoDataset::competencyContent()[$project->language][$competencyCode] ?? [];

                $indicators = BarsIndicator::where('role_id', $roleId)
                    ->whereHas('competency', fn ($q) => $q->where('code', $competencyCode))
                    ->orderBy('position')
                    ->get();

                foreach ($indicators as $position => $indicator) {
                    $score = $scores[$position];
                    $answer = $answers[$position] ?? null;

                    $excerpts = [];

                    if ($score !== -1 && $answer !== null) {
                        $sentences = DemoDatasetValidator::splitSentences($answer['text']);
                        $excerpts = [$sentences[$answer['excerpt_sentence']]];
                    }

                    // The PROJECT's language, not the fallback.
                    //
                    // `$indicator->text` resolves through the translatable
                    // fallback, which is English — so an Italian demo project
                    // produced a report whose indicators read in English while
                    // everything around them read in Italian. The real scoring
                    // path never had this bug: `PromptBuilder` takes the
                    // project locale and THROWS when a translation is missing,
                    // and the explanation comes back from the model in the
                    // language it was prompted in. Only this seeder ignored it.
                    //
                    // The same two lines already read `$project->language` for
                    // the competency content a few lines above, which is what
                    // made the mismatch visible: half the report was localized.
                    $locale = $project->language;
                    $indicatorText = $indicator->hasTranslation('text', $locale)
                        ? $indicator->getTranslation('text', $locale)
                        : $indicator->text;

                    $dto = new IndicatorScoreDTO(
                        position: $position,
                        indicatorText: $indicatorText,
                        score: $score,
                        explanation: '',
                        excerpts: $excerpts,
                    );

                    // Throws ExcerptNotVerbatimException on any mismatch —
                    // fails loudly, before a single IndicatorScore row for
                    // this competency is persisted.
                    $excerptValidator->validate($dto, $transcript);

                    $indicatorScore = new IndicatorScore;
                    $indicatorScore->forceFill([
                        'competency_result_id' => $result->id,
                        'position' => $position,
                        'indicator_text' => $indicatorText,
                        'score' => $score,
                        // Localized for the same reason. These stand in for
                        // text a model writes in the project's language, so
                        // leaving them English made the demo misrepresent what
                        // a real report looks like — which is the one thing a
                        // demo must not do.
                        'explanation' => self::demoExplanation($locale, $score),
                        'excerpts' => $excerpts,
                        // Demo data has no validation-failure path — every -1 here is
                        // the fixture DECLARING no episode, i.e. model_declared, never
                        // score_illegal/excerpt_unverifiable. Required by
                        // indicator_scores_unassessable_reason_check (D7).
                        'unassessable_reason' => $score === -1 ? 'model_declared' : null,
                    ])->save();
                }
            }

            $evaluation->status = $completionGate->evaluate($validCount, $totalCount);
            $evaluation->evaluated_at = now();
            $evaluation->save();
        }

        return $evaluations;
    }

    /**
     * `IntegrityEvent` rows per the design's proctoring event tables — one
     * representative session per participant, chosen so
     * `IntegritySummarizer::summarize` lands in each of the three risk
     * bands across the seeded dataset (low/medium/high). Server-side-only
     * (design D3 of C11): the backoffice renders this score, it never
     * recomputes it.
     *
     * @param  array<string, Participant>  $participants
     */
    private function writeProctoringEvents(array $participants): void
    {
        foreach (DemoDataset::participants() as $definition) {
            if ($definition['proctoring'] === null) {
                continue;
            }

            $participant = $participants[$definition['key']];
            $competencyCode = $definition['proctoring']['competency'];

            $session = InterviewSession::where('participant_id', $participant->id)
                ->where('competency_code', $competencyCode)
                ->firstOrFail();

            if (IntegrityEvent::where('interview_session_id', $session->id)->exists()) {
                // Already written by a prior run.
                continue;
            }

            $ts = $session->started_at ?? now();

            foreach ($definition['proctoring']['events'] as $event) {
                IntegrityEvent::create([
                    'interview_session_id' => $session->id,
                    'kind' => $event['kind'],
                    'payload' => $event['payload'],
                    'ts' => $ts,
                ]);

                $ts = $ts->addSeconds(5);
            }
        }
    }

    /**
     * Snapshot writer (design D6). Key scheme is byte-identical to
     * `SnapshotController.php:106-111`:
     * `{organization_id}/{participant_id}/{interview_session_id}/{uuid}.jpg`,
     * written with `Storage::put()` — no disk argument, resolving through
     * the single storage configuration point the arch guard enforces
     * (`tests/Arch/Storage/SingleStorageDiskArchTest.php`).
     *
     * Object write ALWAYS precedes the `InterviewSnapshot` row write, inside
     * the same aggregate transaction as everything else this writer does: a
     * storage failure propagates as a loud exception before any row for it
     * is persisted — never a dangling `s3_key`.
     *
     * 2 snapshots per COMPLETED session, for the 4 participants named in
     * `DemoDataset::snapshotParticipantKeys()` only.
     *
     * @param  array<string, Participant>  $participants
     * @return int total snapshots written
     */
    private function writeSnapshots(Organization $organization, array $participants): int
    {
        $keys = DemoDataset::snapshotParticipantKeys();
        $written = 0;

        foreach (DemoDataset::participants() as $definition) {
            if (! in_array($definition['key'], $keys, true)) {
                continue;
            }

            $participant = $participants[$definition['key']];

            foreach ($definition['sessions'] as $sessionDefinition) {
                if ($sessionDefinition['status'] !== 'completed') {
                    continue;
                }

                $session = InterviewSession::where('participant_id', $participant->id)
                    ->where('competency_code', $sessionDefinition['competency'])
                    ->firstOrFail();

                if (InterviewSnapshot::where('interview_session_id', $session->id)->exists()) {
                    // Already written by a prior run.
                    continue;
                }

                for ($i = 0; $i < 2; $i++) {
                    $s3Key = implode('/', [
                        $organization->id,
                        $participant->id,
                        $session->id,
                        Str::uuid()->toString().'.jpg',
                    ]);

                    try {
                        Storage::put($s3Key, PlaceholderJpeg::decode());
                    } catch (Throwable $e) {
                        throw new RuntimeException(
                            "Failed to write demo snapshot object [{$s3Key}]: {$e->getMessage()}",
                            previous: $e,
                        );
                    }

                    $snapshot = new InterviewSnapshot;
                    $snapshot->forceFill([
                        'interview_session_id' => $session->id,
                        's3_key' => $s3Key,
                        'taken_at' => now(),
                    ]);
                    $snapshot->save();

                    $written++;
                }
            }
        }

        return $written;
    }

    /**
     * `ai_requests` writer (design D14). 26 hand-authored rows across the
     * dataset's 5 evaluations, drawn from `DemoDataset::aiRequestCalls()`.
     * Every row's `evaluation_id` is NOT NULL by construction — attached to
     * the participant's already-written Evaluation, never a bare INSERT
     * (design D12: this is what makes the automatic `cascadeOnDelete` on
     * teardown sound).
     *
     * `estimated_cost_usd` is NEVER authored — computed by
     * `AiRequestCostEstimator` from `(model, input_tokens, output_tokens)`
     * against `config('scoring.cost_rates_usd_per_million')` at write time
     * (design D14, mirrors D5's "computed, never hardcoded").
     *
     * Idempotent PER PARENT EVALUATION (design D14/D12 seam, matching
     * `writeProctoringEvents`/`writeSnapshots`): if any `ai_requests` row
     * already exists for this evaluation, the whole batch for it is skipped
     * — never a partial re-write.
     *
     * @param  array<string, Evaluation>  $evaluations
     * @return int total rows written
     */
    private function writeAiRequests(array $evaluations): int
    {
        $estimator = new AiRequestCostEstimator;
        $provider = (string) config('scoring.provider', 'anthropic');
        $written = 0;

        foreach (DemoDataset::aiRequestCalls() as $participantKey => $rows) {
            $evaluation = $evaluations[$participantKey] ?? null;

            if ($evaluation === null) {
                throw new RuntimeException("Demo ai_requests fixture references participant [{$participantKey}] with no Evaluation row.");
            }

            // `ai_requests` is append-only by architecture guard
            // (`AiRequestAppendOnlyArchTest`) — a source-text scan that bans
            // the Eloquent model's own query methods anywhere outside its
            // model file (C13 design D4). A raw query-builder existence
            // check is a pure read, never a mutation, and satisfies the
            // guard without inventing a reader class this change's design
            // never called for.
            if (DB::table('ai_requests')->where('evaluation_id', $evaluation->id)->exists()) {
                // Already written by a prior run.
                continue;
            }

            foreach ($rows as $row) {
                AiRequest::create([
                    'evaluation_id' => $evaluation->id,
                    'provider' => $provider,
                    'estimated_cost_usd' => $estimator->estimate($row['model'], $row['input_tokens'], $row['output_tokens']),
                    'success' => $row['success'],
                    'failure_reason' => $row['failure_reason'],
                    'competency_code' => $row['competency_code'],
                    'model' => $row['model'],
                    'prompt_version' => 'beai-demo-fixture-v1',
                    'input_tokens' => $row['input_tokens'],
                    'output_tokens' => $row['output_tokens'],
                    'finish_reason' => $row['success'] ? 'stop' : null,
                    'latency_ms' => $row['latency_ms'],
                ]);

                $written++;
            }
        }

        return $written;
    }

    /**
     * `webhook_deliveries` writer (design D15). 8 hand-authored rows from
     * `DemoDataset::webhookDeliveries()`. `payload` is built by the SAME
     * production assemblers a real delivery uses —
     * `EvaluationPayloadAssembler::assembleForEvaluation()` /
     * `ProgressPayloadAssembler::assemble()` — never hand-authored (design
     * D5's "computed, never hardcoded" reason: a frozen literal stops
     * matching the product the first time the payload shape changes).
     *
     * `delivery_id` is a v5 UUID over a stable name
     * (`beai-demo/{orgId}/{eventType}/{dedupeKey}`) — deterministic,
     * collision-free, no randomness (design D16); the `uuid` column cannot
     * carry a text prefix marker, so this is the row's identification
     * mechanism.
     *
     * No dispatch ever happens — rows are inserted directly, never through
     * `WebhookDeliveryRecorder`/`DeliverWebhookJob`, so no HTTP call is ever
     * made (proposal correction 3).
     *
     * Idempotent by `key` (design's per-row natural identity — the unique
     * `(organization_id, project_id, event_type, dedupe_key)` constraint):
     * a row is looked up by that exact tuple and left untouched if found.
     *
     * @param  array<string, Participant>  $participants
     * @param  array<string, Project>  $projects
     * @param  array<string, Evaluation>  $evaluations
     * @return array<string, WebhookDelivery>
     */
    private function writeWebhookDeliveries(array $participants, array $projects, array $evaluations): array
    {
        $projectKeyByParticipantKey = collect(DemoDataset::participants())->pluck('project_key', 'key');
        $backoffSeconds = config('webhooks.delivery.backoff_seconds');
        $created = [];

        foreach (DemoDataset::webhookDeliveries() as $row) {
            $participant = $participants[$row['participant_key']] ?? null;
            $projectKey = $projectKeyByParticipantKey[$row['participant_key']] ?? null;
            $project = $projectKey !== null ? ($projects[$projectKey] ?? null) : null;

            if ($participant === null || $project === null) {
                throw new RuntimeException("Demo webhook_deliveries fixture [{$row['key']}] references an unknown participant/project.");
            }

            if ($row['event_type'] === 'evaluation') {
                $evaluation = $evaluations[$row['participant_key']] ?? null;

                if ($evaluation === null) {
                    throw new RuntimeException("Demo webhook_deliveries fixture [{$row['key']}] references participant [{$row['participant_key']}] with no Evaluation row.");
                }

                $dedupeKey = (string) $evaluation->id;
                $eventType = WebhookEventType::Evaluation;
                $payload = $this->evaluationPayloadAssembler->assembleForEvaluation($evaluation->id, (string) Str::uuid());
            } else {
                $progressKind = $row['progress_kind'] ?? null;

                if ($progressKind === 'created') {
                    $dedupeKey = 'participant-created:'.$participant->id;
                } else {
                    $competencyCode = $row['competency_code'] ?? null;

                    if ($competencyCode === null) {
                        throw new RuntimeException("Demo webhook_deliveries fixture [{$row['key']}] has event_type=progress/ended with no competency_code.");
                    }

                    $dedupeKey = 'competency-ended:'.$participant->id.':'.$competencyCode;
                }

                $eventType = WebhookEventType::Progress;
                $payload = $this->progressPayloadAssembler->assemble($participant->id, (string) Str::uuid());
            }

            $existing = WebhookDelivery::where('project_id', $project->id)
                ->where('event_type', $eventType->value)
                ->where('dedupe_key', $dedupeKey)
                ->first();

            if ($existing !== null) {
                $created[$row['key']] = $existing;

                continue;
            }

            $deliveryId = Uuid::uuid5(Uuid::NAMESPACE_URL, "beai-demo/{$project->organization_id}/{$eventType->value}/{$dedupeKey}")->toString();

            // Persisted-anchor + integer-offset timestamps (design D16) — no
            // now() in this writer. `evaluated_at` for evaluation-type
            // deliveries, `completed_at`/`started_at` (already persisted by
            // writeParticipants) for progress-type deliveries, falling back
            // to the participant's own `created_at` for a participant with
            // neither (e.g. c-006, `in_attesa`, no sessions yet).
            $anchor = $row['event_type'] === 'evaluation'
                ? ($evaluations[$row['participant_key']]->evaluated_at ?? $participant->completed_at ?? $participant->started_at ?? $participant->created_at)
                : ($participant->completed_at ?? $participant->started_at ?? $participant->created_at);

            $isSkipped = $row['status'] === 'skipped';
            $attemptCount = $row['attempt_count'] ?? 0;
            $lastResponseStatus = $row['last_response_status'] ?? null;

            $lastAttemptAt = null;
            $deliveredAt = null;
            $nextAttemptAt = null;
            $lastError = null;

            if (! $isSkipped) {
                $lastAttemptAt = $anchor->copy()->addMinutes(30);

                if ($row['status'] === 'delivered') {
                    $deliveredAt = $lastAttemptAt;
                } else {
                    $lastError = "HTTP {$lastResponseStatus}";
                }

                if ($row['status'] === 'pending') {
                    $gapSeconds = $backoffSeconds[$attemptCount - 1] ?? end($backoffSeconds);
                    $nextAttemptAt = $lastAttemptAt->copy()->addSeconds($gapSeconds);
                }
            }

            // decide()'s own rule (WebhookDeliveryRecorder.php:145-161):
            // target_url is null ONLY on no_webhook_url — never our case here
            // (every row's project carries a webhook_url) — set on every
            // other branch, skipped or not.
            $delivery = WebhookDelivery::create([
                'project_id' => $project->id,
                'participant_id' => $participant->id,
                'delivery_id' => $deliveryId,
                'event_type' => $eventType,
                'dedupe_key' => $dedupeKey,
                'status' => $row['status'],
                'skip_reason' => $row['skip_reason'] ?? null,
                'target_url' => $project->webhook_url,
                'payload' => $payload,
                'payload_version' => (string) config('webhooks.payload.version'),
                'attempt_count' => $attemptCount,
                'max_attempts' => (int) config('webhooks.delivery.max_attempts'),
                'last_attempt_at' => $lastAttemptAt,
                'next_attempt_at' => $nextAttemptAt,
                'delivered_at' => $deliveredAt,
                'last_response_status' => $lastResponseStatus,
                'last_error' => $lastError,
            ]);

            $created[$row['key']] = $delivery;
        }

        return $created;
    }

    /**
     * `api_clients` writer (design D17). Three rows, one per
     * `ApiClient::state()` badge — `active`, `expired` (`expires_at` in the
     * past), `revoked` (`is_active = false`).
     *
     * `key_hash` is minted from a raw key generated and discarded INSIDE ONE
     * EXPRESSION (`hash('sha256', bin2hex(random_bytes(32)))`) — no PHP
     * variable ever holds the raw value, not even transiently. Nobody,
     * including this command's own output, ever holds the preimage, so the
     * `active` row still cannot authenticate — the badge reports credential
     * state, never possession (design D16/D17, non-negotiable #5).
     *
     * `key_hash` is NOT in `ApiClient::$fillable` — `forceFill`, mirroring
     * `ApiClientController::store()`. `ApiClient` is NOT a `TenantModel` —
     * `organization_id` is set explicitly, the one writer in this class
     * where the ambient `TenantContextScope` wrapper does not stamp it.
     * `abilities` is drawn only from `config('m2m_abilities.allowed')`,
     * never a literal.
     *
     * Idempotent by `name`: a client already created by a prior run is left
     * untouched — never re-minted, which would silently invalidate whatever
     * credential state a prior run's row already represented.
     *
     * @return array<string, ApiClient>
     */
    private function writeApiClients(Organization $organization): array
    {
        $created = [];

        foreach (DemoDataset::apiClients() as $definition) {
            $client = ApiClient::where('organization_id', $organization->id)
                ->where('name', $definition['name'])
                ->first();

            if ($client === null) {
                $client = new ApiClient;
                $client->forceFill([
                    'organization_id' => $organization->id,
                    'name' => $definition['name'],
                    'abilities' => config('m2m_abilities.allowed'),
                    'is_active' => $definition['is_active'],
                    'expires_at' => $definition['expires_offset_days'] === null
                        ? null
                        : now()->addDays($definition['expires_offset_days']),
                    // Discarded in the same expression — no variable ever
                    // holds the raw key (design D16/D17).
                    'key_hash' => hash('sha256', bin2hex(random_bytes(32))),
                ]);
                $client->save();
                $client->refresh();
            }

            $created[$definition['key']] = $client;
        }

        return $created;
    }

    /**
     * `notification_logs` writer (design D18). 3 hand-authored rows from
     * `DemoDataset::notificationLogs()`. MUST run LAST — every subject it
     * can reference (a demo participant or a demo webhook delivery) has
     * already been persisted by the time this runs.
     *
     * `organization_id` is stamped automatically by `TenantScoped::creating`
     * (`NotificationLog` IS a `TenantModel`, unlike `ApiClient`) — plain
     * `create()`, never `forceFill()`, mirrors
     * `SendOperatorNotificationJob`'s own write path.
     *
     * Idempotent by `(notification_type, subject_type, subject_id)` — the
     * same tuple the raw-DDL unique index arbitrates.
     *
     * @param  array<string, Participant>  $participants
     * @param  array<string, WebhookDelivery>  $deliveries
     * @return int total rows written
     */
    private function writeNotificationLogs(array $participants, array $deliveries): int
    {
        $written = 0;

        foreach (DemoDataset::notificationLogs() as $row) {
            $subjectType = $row['subject_kind'] === 'delivery' ? 'webhook_delivery' : 'participant';
            $subject = $row['subject_kind'] === 'delivery'
                ? ($deliveries[$row['subject_key']] ?? null)
                : ($participants[$row['subject_key']] ?? null);

            if ($subject === null) {
                throw new RuntimeException("Demo notification_logs fixture references unknown {$row['subject_kind']} [{$row['subject_key']}].");
            }

            $anchor = $subject instanceof WebhookDelivery
                ? $subject->last_attempt_at
                : $subject->completed_at ?? $subject->created_at;

            $exists = NotificationLog::where('notification_type', $row['notification_type'])
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subject->id)
                ->exists();

            if ($exists) {
                // Already written by a prior run.
                continue;
            }

            NotificationLog::create([
                'notification_type' => $row['notification_type'],
                'subject_type' => $subjectType,
                'subject_id' => $subject->id,
                'status' => $row['status'],
                'suppression_reason' => $row['suppression_reason'] ?? null,
                'recipient_count' => $row['recipient_count'] ?? 0,
                'suppressed_carried_count' => $row['suppressed_carried_count'] ?? 0,
                'last_error' => $row['last_error'] ?? null,
                // Persisted-anchor + integer-offset (design D16) — no now().
                'sent_at' => $row['status'] === 'sent' && $anchor !== null ? $anchor->copy()->addMinutes(5) : null,
            ]);

            $written++;
        }

        return $written;
    }

    /**
     * @param  array{version: FrameworkVersion, templates: list<AvatarTemplate>, projects: array<string, Project>, participants: array<string, Participant>, evaluations: array<string, Evaluation>, snapshot_count: int, api_clients: array<string, ApiClient>, ai_request_count: int, deliveries: array<string, WebhookDelivery>, notification_log_count: int}  $summary
     */
    private function report(Command $command, array $summary): void
    {
        $command->newLine();
        $command->info('Demo dataset provisioned.');
        $command->table(
            ['What', 'Value'],
            [
                ['FrameworkVersion', $summary['version']->version.' (locked='.($summary['version']->is_locked ? 'true' : 'false').')'],
                ['Avatar templates', count($summary['templates']).' ('.collect($summary['templates'])->where('is_active', true)->count().' active)'],
                ['Projects', count($summary['projects'])],
                ['Participants', count($summary['participants']).' across every lifecycle status'],
                ['Evaluations', count($summary['evaluations']).' with computed competency results and indicator scores'],
                ['Snapshots', $summary['snapshot_count'].' objects written to the configured disk'],
                ['API clients', count($summary['api_clients']).' (active/expired/revoked)'],
                ['AI requests', $summary['ai_request_count'].' (dashboard token usage + latency percentiles)'],
                ['Webhook deliveries', count($summary['deliveries']).' (status spread: delivered/dead/pending/failed_permanent/skipped)'],
                ['Notification logs', $summary['notification_log_count'].' (sent/suppressed/failed)'],
            ],
        );
    }

    /**
     * Stand-in explanation text, in the project's language.
     *
     * Two locales only, matching the mandatory `it`/`en` pair. An unexpected
     * locale falls back to English rather than throwing: this is demo data, and
     * failing a seed over a missing sample sentence would be a worse outcome
     * than a sentence in the wrong language.
     */
    private static function demoExplanation(string $locale, int $score): string
    {
        if ($score === -1) {
            return $locale === 'it'
                ? 'Il candidato non ha fornito alcun episodio riferibile a questo indicatore.'
                : 'The candidate provided no episode addressing this indicator.';
        }

        return $locale === 'it'
            ? 'Confrontato con l\'ancora di riferimento per il livello '.$score.'.'
            : 'Matched against the reference anchor for level '.$score.'.';
    }
}
