<?php

namespace Database\Seeders;

use App\Models\AvatarTemplate;
use App\Models\BarsIndicator;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role as FrameworkRole;
use App\Models\User;
use App\Models\Utterance;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * A working tenant to click through locally.
 *
 * NOT part of DatabaseSeeder, and never run automatically. It creates an
 * account with a known password, which belongs in a developer's laptop and
 * absolutely nowhere else:
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * `scripts/dev.sh --seed` runs it for you.
 *
 * Idempotent: re-running updates nothing and creates nothing twice, so it is
 * safe to leave in a boot script.
 *
 * Every organization_id here is written with forceFill or an explicit property
 * assignment rather than through a fillable array. That is not a style
 * preference: the column is excluded from $fillable as a C2 security invariant,
 * so mass assignment drops it silently and the insert dies on a not-null
 * violation. A seeder is trusted code and may set it — but it has to say so.
 */
class DemoSeeder extends Seeder
{
    public const DEMO_EMAIL = 'admin@beai.local';

    public const DEMO_PASSWORD = 'password';

    public const DEMO_CANDIDATE_REF = 'demo-candidate-001';

    /**
     * Reference anchors are scored on the discrete set {1,3,5} — the single
     * closest anchor, never an in-between value. -1 means "no assessable
     * evidence" and is excluded from the competency mean entirely.
     *
     * @var list<array{code: string, scores: list<int>}>
     */
    private const DEMO_COMPETENCY_SCORES = [
        ['code' => 'PRS', 'scores' => [5, 3, 3]],
        ['code' => 'STG', 'scores' => [3, 3, 1]],
        ['code' => 'DRV', 'scores' => [5, 5, 3]],
        ['code' => 'COM', 'scores' => [3, 5, 5]],
        // One competency carrying an unassessable indicator, because a demo
        // dataset in which reliability is always 1.0 never exercises the
        // partial-evidence rendering that the whole reliability concept exists
        // for.
        ['code' => 'COL', 'scores' => [5, 3, -1]],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('DemoSeeder refuses to run in production: it creates an account with a published password.');

            return;
        }

        $org = Organization::firstOrCreate(
            ['slug' => 'dev-org'],
            ['name' => 'Dev Organization'],
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

        $admin = User::firstOrCreate(
            ['email' => self::DEMO_EMAIL],
            ['name' => 'Demo Admin', 'password' => Hash::make(self::DEMO_PASSWORD)],
        );

        // Converged, not just created. firstOrCreate leaves an existing row's
        // password alone, so an account previously minted at this address by
        // `beai:provision-organization` (which generates a random password and
        // prints it exactly once) kept a secret nobody could look up — while
        // every document, including this file, advertised DEMO_PASSWORD. The
        // demo password is published on purpose; the only thing worse than a
        // known password here is a known password that does not work.
        if (! Hash::check(self::DEMO_PASSWORD, $admin->password)) {
            $admin->password = Hash::make(self::DEMO_PASSWORD);
            $admin->save();
        }

        // organization_id is deliberately NOT fillable — it is set only by
        // trusted server code, never by a payload. A seeder is trusted code, so
        // it assigns the property directly rather than smuggling it through
        // firstOrCreate's attribute array, which would silently drop it.
        if ($admin->organization_id !== $org->id) {
            $admin->organization_id = $org->id;
            $admin->save();
        }

        $admin->assignRole(SpatieRole::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'api',
            'team_id' => $org->id,
        ]));

        $summary = TenantContextScope::runFor($org->id, function () use ($org): array {
            // The framework CATALOG (competencies, indicators, anchors) is
            // global and seeded by FrameworkCatalogSeeder. A FrameworkVersion is
            // the per-tenant pin a project points at, and nothing creates one
            // for you — a project cannot exist without it.
            $version = FrameworkVersion::firstOrCreate(
                ['organization_id' => $org->id, 'version' => '1.0.0'],
                ['label' => 'Demo framework version'],
            );

            $templates = $this->seedAvatarTemplates($org);
            $projects = $this->seedProjects($org, $version);
            $participants = $this->seedParticipants($org, $projects);
            $evaluations = $this->seedEvaluations($org, $version, $participants);

            return [
                'templates' => $templates,
                'projects' => $projects,
                'participants' => $participants,
                'evaluations' => $evaluations,
            ];
        });

        $this->report($org, $summary);
    }

    /**
     * Avatar/voice templates (C14). Exactly one may be active at a time per
     * organization, so the Tavus one ships inactive — seeding two active rows
     * would produce a state the application forbids and the UI cannot render.
     *
     * @return list<AvatarTemplate>
     */
    private function seedAvatarTemplates(Organization $org): array
    {
        $definitions = [
            [
                'name' => 'HeyGen — Italian interviewer',
                'description' => 'Conversational Italian avatar used by the demo project.',
                'provider' => 'heygen',
                'is_active' => true,
                'config' => [
                    'avatarId' => 'demo_avatar_it',
                    'voiceId' => 'demo_voice_it',
                    'language' => 'it',
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
                'name' => 'Tavus — English interviewer',
                'description' => 'English CVI avatar. Inactive: only one template may be active per organization.',
                'provider' => 'tavus',
                'is_active' => false,
                'config' => [
                    'faceId' => 'demo_face_en',
                    'palId' => 'demo_pal_en',
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
            $template = AvatarTemplate::where('organization_id', $org->id)
                ->where('name', $definition['name'])
                ->first();

            if ($template === null) {
                $template = new AvatarTemplate;
                $template->forceFill([
                    'organization_id' => $org->id,
                    ...$definition,
                ])->save();
                $template->refresh();
            }

            $created[] = $template;
        }

        return $created;
    }

    /**
     * Three projects rather than one, because the interesting behaviour is in
     * the differences: `standard` runs adaptive role competencies while
     * `potential` runs only MTG/LAT, and a draft project must not be
     * reachable by a candidate at all.
     *
     * @return array<string, Project>
     */
    private function seedProjects(Organization $org, FrameworkVersion $version): array
    {
        $definitions = [
            'standard' => [
                'slug' => 'demo-project',
                'name' => 'Demo Project',
                // 'standard' rather than 'potential' so the interview runs
                // the adaptive role competencies — the flow worth looking at.
                'assessment_type' => 'standard',
                'role_code' => 'ICO',
                'language' => 'it',
                'status' => 'active',
                'pause_every_n_competencies' => 3,
                'nudge_min_chars' => 120,
            ],
            'potential' => [
                'slug' => 'demo-potential',
                'name' => 'Demo — Potential Assessment',
                'assessment_type' => 'potential',
                'role_code' => 'FLL',
                'language' => 'en',
                'status' => 'active',
                'pause_every_n_competencies' => 2,
                'nudge_min_chars' => 120,
            ],
            'draft' => [
                'slug' => 'demo-draft',
                'name' => 'Demo — Draft (not yet live)',
                'assessment_type' => 'standard',
                'role_code' => 'MLL',
                'language' => 'it',
                'status' => 'draft',
                'pause_every_n_competencies' => 3,
                'nudge_min_chars' => 120,
            ],
        ];

        $projects = [];

        foreach ($definitions as $key => $definition) {
            $projects[$key] = Project::firstOrCreate(
                ['slug' => $definition['slug']],
                ['framework_version_id' => $version->id, ...$definition],
            );
        }

        return $projects;
    }

    /**
     * One participant per lifecycle state, so every status badge and every read
     * gate has something behind it. The gates are real: a transcript is
     * readable from `in_valutazione` onward, a structured evaluation only at
     * `completato`.
     *
     * @param  array<string, Project>  $projects
     * @return array<string, Participant>
     */
    private function seedParticipants(Organization $org, array $projects): array
    {
        $definitions = [
            'waiting' => [self::DEMO_CANDIDATE_REF, 'Demo Candidate', 'standard', 'in_attesa'],
            'running' => ['demo-candidate-002', 'Giulia Ferrari', 'standard', 'in_corso'],
            'scoring' => ['demo-candidate-003', 'Marco Bianchi', 'standard', 'in_valutazione'],
            'done' => ['demo-candidate-004', 'Sara Colombo', 'standard', 'completato'],
            'done_partial' => ['demo-candidate-005', 'Luca Moretti', 'standard', 'completato'],
            'failed' => ['demo-candidate-006', 'Elena Ricci', 'standard', 'errore'],
            'potential' => ['demo-candidate-007', 'Tom Bright', 'potential', 'completato'],
        ];

        $participants = [];

        foreach ($definitions as $key => [$ref, $name, $projectKey, $status]) {
            $project = $projects[$projectKey];

            $participant = Participant::where('project_id', $project->id)
                ->where('candidate_ref', $ref)
                ->first();

            if ($participant === null) {
                $participant = new Participant;
                // Built by hand rather than via firstOrCreate, because a
                // Participant's organization_id is a named security invariant: it
                // is NOT fillable, and it is set server-side from the project it
                // belongs to — never from a payload and never inferred from the
                // ambient tenant. Nothing stamps it on this path, so passing it
                // through the attribute array silently drops it and the insert dies
                // on a not-null violation.
                $participant->forceFill([
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'candidate_ref' => $ref,
                    'display_name' => $name,
                    'role_code' => $project->role_code,
                    'language' => $project->language,
                    'status' => $status,
                    'started_at' => $status === 'in_attesa' ? null : now()->subHours(3),
                    'completed_at' => in_array($status, ['completato', 'errore'], true) ? now()->subHour() : null,
                ])->save();

                $participant->refresh();
            }

            $participants[$key] = $participant;
        }

        return $participants;
    }

    /**
     * Evaluations for the participants that reached `completato`, each with its
     * competency results and per-indicator scores.
     *
     * A transcript is seeded first and the excerpts are then taken verbatim
     * from it. That ordering is the point: `excerpts` must be substrings of
     * what the candidate actually said, and demo data that quotes a transcript
     * which does not contain those words models the one failure the scoring
     * contract exists to prevent.
     *
     * @param  array<string, Participant>  $participants
     * @return list<Evaluation>
     */
    private function seedEvaluations(Organization $org, FrameworkVersion $version, array $participants): array
    {
        $role = FrameworkRole::where('code', 'ICO')->first();

        if ($role === null) {
            $this->command->warn('DemoSeeder: role ICO not found — run FrameworkCatalogSeeder first. Skipping evaluations.');

            return [];
        }

        $evaluations = [];

        foreach (['done', 'done_partial'] as $key) {
            $participant = $participants[$key] ?? null;

            if ($participant === null) {
                continue;
            }

            if (Evaluation::where('participant_id', $participant->id)->exists()) {
                $evaluations[] = Evaluation::where('participant_id', $participant->id)->first();

                continue;
            }

            $transcript = $this->seedTranscript($org, $participant);

            $evaluation = new Evaluation;
            $evaluation->forceFill([
                'organization_id' => $org->id,
                'participant_id' => $participant->id,
                'framework_version_id' => $version->id,
                'status' => 'completed',
                // Versioned for traceability: an evaluation that cannot say
                // which model and which prompt produced it is not reproducible,
                // and a score you cannot reproduce is an opinion.
                'model_version' => 'claude-opus-5',
                'prompt_version' => 'bars-v1',
                'evaluated_at' => now()->subMinutes(30),
                'retry_attempt' => $key === 'done_partial' ? 1 : 0,
            ])->save();
            $evaluation->refresh();

            // The partial case drops the last two competencies, so its
            // completion sits below the 90% gate that separates `completed`
            // from `pending`.
            $definitions = $key === 'done_partial'
                ? array_slice(self::DEMO_COMPETENCY_SCORES, 0, 3)
                : self::DEMO_COMPETENCY_SCORES;

            foreach ($definitions as $definition) {
                $this->seedCompetencyResult($org, $evaluation, $role, $definition, $transcript);
            }

            $evaluations[] = $evaluation;
        }

        return $evaluations;
    }

    /**
     * @return list<string> the candidate's lines, verbatim
     */
    private function seedTranscript(Organization $org, Participant $participant): array
    {
        $lines = [
            'On my last project I spotted the early symptoms of a delivery problem and flagged them to the team.',
            'I formed three solution hypotheses and tested them one at a time against the data we already had.',
            'I took on the riskiest part of the work myself and delivered it by the agreed deadline.',
            'I explained the decision to stakeholders in a short written brief so everyone could follow it.',
            'When a colleague was struggling I reshuffled my own priorities to help them close their task.',
        ];

        $session = InterviewSession::where('participant_id', $participant->id)->first();

        if ($session === null) {
            $session = new InterviewSession;
            $session->forceFill([
                'organization_id' => $org->id,
                'participant_id' => $participant->id,
                'project_id' => $participant->project_id,
                'question_index' => count($lines),
                'competency_code' => 'PRS',
                'framework_version_id' => $participant->project->framework_version_id,
                'provider' => 'heygen',
                'provider_session_ref' => 'demo-session-'.$participant->id,
                'status' => 'completed',
                'ended_reason' => 'completed',
                'started_at' => now()->subHours(3),
                'ended_at' => now()->subHours(2),
            ])->save();
            $session->refresh();

            foreach ($lines as $index => $line) {
                foreach ([['avatar', 'Can you walk me through a concrete episode?'], ['candidate', $line]] as [$speaker, $text]) {
                    $utterance = new Utterance;
                    $utterance->forceFill([
                        'organization_id' => $org->id,
                        'interview_session_id' => $session->id,
                        'speaker' => $speaker,
                        'text' => $text,
                        'ts' => now()->subHours(3)->addMinutes($index * 4),
                    ])->save();
                }
            }
        }

        return $lines;
    }

    /**
     * @param  array{code: string, scores: list<int>}  $definition
     * @param  list<string>  $transcript
     */
    private function seedCompetencyResult(
        Organization $org,
        Evaluation $evaluation,
        FrameworkRole $role,
        array $definition,
        array $transcript,
    ): void {
        $scores = $definition['scores'];

        $indicators = BarsIndicator::where('role_id', $role->id)
            ->whereHas('competency', fn ($q) => $q->where('code', $definition['code']))
            ->orderBy('position')
            ->take(count($scores))
            ->get();

        if ($indicators->isEmpty()) {
            return;
        }

        // Trim the scores to the indicators that actually exist for this
        // (role, competency) pair. The catalog is the source of truth for how
        // many indicators a competency has; a hardcoded count here would
        // invent scores for indicators nobody defined.
        $scores = array_slice($scores, 0, $indicators->count());

        $assessed = array_values(array_filter($scores, static fn (int $s): bool => $s !== -1));

        // The mean of the ASSESSED indicators only. A -1 that reached the
        // numerator would drag every partially-evidenced competency toward
        // zero and quietly turn "we could not tell" into "they were bad".
        $mean = $assessed === [] ? null : array_sum($assessed) / count($assessed);
        $reliability = count($scores) === 0 ? 0.0 : count($assessed) / count($scores);

        $result = new CompetencyResult;
        $result->forceFill([
            'organization_id' => $org->id,
            'evaluation_id' => $evaluation->id,
            'competency_code' => $definition['code'],
            'score' => $mean === null ? null : round($mean, 2),
            'reliability' => round($reliability, 4),
            // T = 0.5, env-overridable. Below it the competency carries too
            // little evidence to be reported as a score.
            'valid' => $reliability >= 0.5,
            'unscorable_reason' => $mean === null ? 'no_assessable_evidence' : null,
        ])->save();
        $result->refresh();

        foreach ($indicators as $position => $indicator) {
            $score = $scores[$position];

            $indicatorScore = new IndicatorScore;
            $indicatorScore->forceFill([
                'organization_id' => $org->id,
                'competency_result_id' => $result->id,
                'position' => $position,
                'indicator_text' => $indicator->text,
                'score' => $score,
                'explanation' => $score === -1
                    ? 'The candidate provided no episode addressing this indicator.'
                    : 'Matched against the reference anchor for level '.$score.'.',
                // Verbatim, or empty. Never invented: an excerpt that is not a
                // substring of the transcript is a fabricated quotation
                // attributed to a real person.
                'excerpts' => $score === -1 ? [] : [$transcript[$position % count($transcript)]],
            ])->save();
        }
    }

    /**
     * @param  array{templates: list<AvatarTemplate>, projects: array<string, Project>, participants: array<string, Participant>, evaluations: list<Evaluation>}  $summary
     */
    private function report(Organization $org, array $summary): void
    {
        $this->command->newLine();
        $this->command->info('Demo tenant ready.');
        $this->command->table(
            ['What', 'Value'],
            [
                ['Organization', "{$org->name} (id={$org->id})"],
                ['Backoffice login', self::DEMO_EMAIL.' / '.self::DEMO_PASSWORD],
                ['Avatar templates', count($summary['templates']).' (1 active — HeyGen)'],
                ['Projects', count($summary['projects']).' (standard, potential, draft)'],
                ['Participants', count($summary['participants']).' across every lifecycle state'],
                ['Evaluations', count($summary['evaluations']).' with competency results and indicator scores'],
            ],
        );
        $this->command->newLine();
        $this->command->warn('The password above is public knowledge. Local use only.');
    }
}
