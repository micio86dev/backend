<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\DTOs\Scoring\IndicatorScoreDTO;
use App\Enums\EvaluationStatus;
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
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Models\Utterance;
use App\Services\Scoring\AssessableFractionReliability;
use App\Services\Scoring\CompletionGate;
use App\Services\Scoring\ExcerptValidator;
use App\Services\Scoring\MeanCalculator;
use App\Services\Scoring\ThresholdValidityPredicate;
use App\Services\Scoring\TranscriptAssembler;
use App\Support\AvatarTemplates\ConfigValidator;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
    public function write(Command $command, Organization $organization): int
    {
        $summary = TenantContextScope::runFor($organization->id, function () use ($command, $organization): array {
            $version = $this->writeFrameworkVersion($organization);
            $templates = $this->writeAvatarTemplates($command, $organization);
            $projects = $this->writeProjects($organization, $version);
            $participants = $this->writeParticipants($organization, $projects, $version);
            $evaluations = $this->writeEvaluations($version, $participants, $projects);
            $this->writeProctoringEvents($participants);
            $snapshotCount = $this->writeSnapshots($organization, $participants);

            return [
                'snapshot_count' => $snapshotCount,
                'version' => $version,
                'templates' => $templates,
                'projects' => $projects,
                'participants' => $participants,
                'evaluations' => $evaluations,
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
                    'language' => $identity['heygen']['language'],
                    'interactivityType' => 'CONVERSATIONAL',
                    'maxSessionDurationSec' => 600,
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
                    'language' => 'en',
                    'audioOnly' => false,
                    'maxCallDurationSec' => 900,
                    'participantAbsentTimeoutSec' => 60,
                    'enableRecording' => false,
                    'enableClosedCaptions' => true,
                    'llmModel' => 'tavus-gemini-2.5-flash',
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

            $projects[$definition['key']] = $project;
        }

        return $projects;
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

            $session = new InterviewSession;
            $session->forceFill([
                'participant_id' => $participant->id,
                'project_id' => $project->id,
                'question_index' => $position,
                'competency_code' => $competencyCode,
                'framework_version_id' => $project->framework_version_id,
                'provider' => 'heygen',
                'provider_session_ref' => DemoMarker::PREFIX.'s-'.$participant->id.'-'.$competencyCode,
                'status' => $sessionDefinition['status'],
                'ended_reason' => $sessionDefinition['ended_reason'],
                'started_at' => $baseTime,
                'ended_at' => $sessionDefinition['status'] === 'in_corso' ? null : $baseTime->copy()->addMinutes(20),
            ])->save();
            $session->refresh();

            $this->writeUtterances($session, $content[$competencyCode] ?? [], $project->language, $sessionDefinition, $baseTime);

            $baseTime = $baseTime->copy()->addHour();
        }
    }

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
     * against `TranscriptAssembler::assemble()` before the row is written —
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
                $session = InterviewSession::where('participant_id', $participant->id)
                    ->where('competency_code', $competencyCode)
                    ->firstOrFail();

                $transcript = $transcriptAssembler->assemble($session);

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

                    $dto = new IndicatorScoreDTO(
                        position: $position,
                        indicatorText: $indicator->text,
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
                        'indicator_text' => $indicator->text,
                        'score' => $score,
                        'explanation' => $score === -1
                            ? 'The candidate provided no episode addressing this indicator.'
                            : 'Matched against the reference anchor for level '.$score.'.',
                        'excerpts' => $excerpts,
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
     * @param  array{version: FrameworkVersion, templates: list<AvatarTemplate>, projects: array<string, Project>, participants: array<string, Participant>, evaluations: array<string, Evaluation>, snapshot_count: int}  $summary
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
            ],
        );
    }
}
