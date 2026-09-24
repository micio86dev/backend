<?php

declare(strict_types=1);

namespace Tests\Helpers\PublicApi;

use App\Contracts\LLMProvider;
use App\Enums\ApiKeyMode;
use App\Jobs\ScoreEvaluationJob;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Evaluation;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Models\Utterance;
use App\Services\ApiKeyGenerator;
use App\Support\Tenancy\TenantContextScope;
use App\Testing\CassetteLLMProvider;

/**
 * Shared fixture builders for step 6's transcript/answers/scoring/recording/
 * events tests (T-INT-017..025) — a class of STATIC methods (never global
 * `function` declarations, which would collide across every test file
 * loaded into the same Pest process) so every new test file can build a
 * real, lifecycle-accurate participant without re-deriving the recipe
 * `tests/Feature/Jobs/GoldenCassetteTest.php` already proved works.
 */
final class Step6Fixtures
{
    /**
     * @param  list<string>  $abilities
     * @return array{org: Organization, key: string}
     */
    public static function orgWithScopedKey(array $abilities = ['interviews:write', 'interviews:read', 'recordings:read']): array
    {
        $org = Organization::factory()->create();
        $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
        ApiClient::factory()->withRawKey($rawKey)->create([
            'organization_id' => $org->id,
            'abilities' => $abilities,
        ]);

        return ['org' => $org, 'key' => $rawKey];
    }

    public static function project(Organization $org, string $roleCode = 'ICO'): Project
    {
        return TenantContextScope::runFor($org->id, function () use ($org, $roleCode): Project {
            $avatarTemplate = AvatarTemplate::create(['name' => 'Ada', 'provider' => 'heygen', 'config' => []]);

            return Project::factory()->create([
                'organization_id' => $org->id,
                'avatar_template_id' => $avatarTemplate->id,
                'status' => 'active',
                'role_code' => $roleCode,
            ]);
        });
    }

    /**
     * A participant with ONE InterviewSession/competency and a small,
     * realistic transcript: two primary questions (each preceded by an
     * avatar `primary` turn and followed by candidate turns) plus one
     * avatar follow-up between them — enough to exercise the G-37 ordinal
     * derivation (`App\Support\PublicApi\SessionTurnReplay`) without the
     * full multi-competency GoldenCassette scenario.
     *
     * `$status` is the STORED (Italian) participant status this fixture
     * ends in — `in_valutazione` (transcript/answers readable) or
     * `in_corso`/`in_attesa`/`errore` (not readable).
     */
    public static function participantWithTranscript(
        Organization $org,
        Project $project,
        string $status,
        string $competencyCode = 'COL',
    ): Participant {
        return TenantContextScope::runFor($org->id, function () use ($org, $project, $status, $competencyCode): Participant {
            $competency = Competency::query()->where('code', $competencyCode)->first()
                ?? Competency::factory()->create(['code' => $competencyCode]);
            $project->competencies()->syncWithoutDetaching([$competency->id => ['position' => 0]]);

            $participant = new Participant;
            $participant->forceFill([
                'organization_id' => $org->id,
                'project_id' => $project->id,
                'candidate_ref' => 'fixture-'.uniqid(),
                'display_name' => 'Fixture Candidate',
                'email' => uniqid('fixture-').'@example.test',
                'status' => $status,
                'language' => 'en',
                'started_at' => now()->subMinutes(10),
            ]);
            $participant->save();
            $participant = $participant->fresh();

            $primaryQuestions = [
                'Describe a time you collaborated with a colleague.',
                'How do you handle disagreement within a team?',
            ];

            $session = InterviewSession::create([
                'participant_id' => $participant->id,
                'project_id' => $project->id,
                'question_index' => 0,
                'competency_code' => $competencyCode,
                'framework_version_id' => $project->framework_version_id,
                'provider' => 'fake',
                'status' => 'completed',
                'primary_questions' => $primaryQuestions,
                'started_at' => now()->subMinutes(9),
                'ended_at' => now()->subMinutes(1),
            ]);

            $t0 = now()->subMinutes(9);

            $turns = [
                ['avatar', $primaryQuestions[0], 0],
                ['candidate', 'I worked closely with a colleague on a cross-team project.', 20],
                ['candidate', 'We agreed on shared goals early, which made the collaboration smooth.', 35],
                ['avatar', 'Can you tell me more about the outcome?', 50],
                ['candidate', 'The project shipped on time and both teams were satisfied.', 65],
                ['avatar', $primaryQuestions[1], 90],
                ['candidate', 'I try to understand the other perspective before responding.', 110],
                ['candidate', 'Usually we find a compromise that keeps the project moving.', 125],
            ];

            foreach ($turns as [$speaker, $text, $offsetSeconds]) {
                // turn_kind is not in Utterance::$fillable (the real write
                // path always derives it from TurnClassifier at insert
                // time) — forceFill() bypasses the guard for this fixture,
                // matching what the real insert path would have persisted.
                $turnKind = $speaker === 'avatar'
                    ? (in_array($text, $primaryQuestions, true) ? 'primary' : 'follow_up')
                    : 'follow_up';

                $utterance = new Utterance;
                $utterance->forceFill([
                    'organization_id' => $org->id,
                    'interview_session_id' => $session->id,
                    'speaker' => $speaker,
                    'text' => $text,
                    'ts' => $t0->copy()->addSeconds($offsetSeconds),
                    'turn_kind' => $turnKind,
                ]);
                $utterance->save();
            }

            return $participant;
        });
    }

    /**
     * A `completato` participant with a REAL, gate-passing Evaluation —
     * `tests/Feature/Jobs/GoldenCassetteTest.php`'s own recipe (one
     * competency, `col_slf_golden.php`'s COL cassette, {5,3,3} → 3.67),
     * run through the real `ScoreEvaluationJob` rather than fabricated
     * rows, so the resulting `CompetencyResult`/`IndicatorScore` data is
     * exactly what the scoring pipeline itself produces.
     *
     * Never sets the ambient `TenantResolver` directly, and never calls
     * `ScoreEvaluationJob::handle()` directly (gga finding 3) — `Role`/
     * `BarsIndicator` are catalogue-global models (not `TenantModel`), so
     * they need no tenant context at all, and every genuinely tenant-scoped
     * write below already runs inside its own `TenantContextScope::
     * runFor()`. The job itself is DISPATCHED — `QUEUE_CONNECTION=sync` in
     * tests runs it synchronously, but through the REAL queue pipeline, so
     * `App\Providers\TenancyServiceProvider`'s `Queue::before`/restore hook
     * resets the resolver before `handle()` runs (matching what the job's
     * own docblock already assumes: "re-derived from the participant's own
     * DB record — NEVER from ambient TenantResolver state") and restores
     * whatever the resolver held BEFORE this call once it returns — the
     * same guarantee a real worker gives every job, never leaking this
     * fixture's organization into whatever the calling test does next.
     */
    public static function buildCompletedScoredParticipant(Organization $org, Project $project): Participant
    {
        $role = TenantContextScope::runFor($org->id, fn () => Role::where('code', $project->role_code)->first()
            ?? Role::factory()->create(['code' => $project->role_code]));

        $participant = TenantContextScope::runFor($org->id, function () use ($org, $project): Participant {
            $p = new Participant;
            $p->forceFill([
                'organization_id' => $org->id,
                'project_id' => $project->id,
                'candidate_ref' => 'fixture-scoring-'.uniqid(),
                'display_name' => 'Scoring Fixture Candidate',
                'email' => uniqid('fixture-scoring-').'@example.test',
                'status' => 'in_valutazione',
                'language' => 'en',
                'started_at' => now()->subMinutes(10),
            ]);
            $p->save();

            return $p->fresh();
        });

        TenantContextScope::runFor($org->id, function () use ($org, $project, $participant, $role): void {
            $competency = Competency::query()->where('code', 'COL')->first()
                ?? Competency::factory()->create(['code' => 'COL']);
            $project->competencies()->syncWithoutDetaching([$competency->id => ['position' => 0]]);

            $indicatorSpecs = [
                ['text' => 'Work effectively with others', 'score' => 5],
                ['text' => 'Willingly help colleagues in trouble', 'score' => 3],
                ['text' => 'Demonstrate commitment to team goals', 'score' => 3],
            ];

            foreach ($indicatorSpecs as $i => $spec) {
                $ind = new BarsIndicator;
                $ind->forceFill([
                    'role_id' => $role->id,
                    'competency_id' => $competency->id,
                    'text' => ['en' => $spec['text']],
                    'anchor_5' => ['en' => 'Score 5 anchor for '.$spec['text']],
                    'anchor_3' => ['en' => 'Score 3 anchor for '.$spec['text']],
                    'anchor_1' => ['en' => 'Score 1 anchor for '.$spec['text']],
                    'position' => $i,
                ]);
                $ind->save();
            }

            $session = InterviewSession::create([
                'participant_id' => $participant->id,
                'project_id' => $project->id,
                'question_index' => 0,
                'competency_code' => 'COL',
                'framework_version_id' => $project->framework_version_id,
                'provider' => 'fake',
                'status' => 'completed',
            ]);

            $lines = [
                'Candidate: I worked collaboratively on multiple projects.',
                "Candidate: Quello che abbiamo fatto è stato di cambiare le nostre abitudini e quindi di interfacciarci direttamente l'uno con l'altro.",
                "Candidate: è stato un esempio di collaborazione fuori dagli schemi che ha funzionato molto bene e ha arricchito sia l'uno che l'altro.",
                'Candidate: è stato sicuramente anche un metodo molto efficace per raggiungere gli obiettivi che avevamo in quel momento.',
            ];

            foreach ($lines as $idx => $line) {
                [$speaker, $text] = explode(': ', $line, 2);
                $utt = new Utterance;
                $utt->forceFill([
                    'organization_id' => $org->id,
                    'interview_session_id' => $session->id,
                    'speaker' => $speaker,
                    'text' => $text,
                    'ts' => now()->addSeconds($idx),
                ]);
                $utt->save();
            }
        });

        $cassette = require base_path('tests/Fixtures/cassettes/col_slf_golden.php');
        $cassetteLlm = new CassetteLLMProvider(['COL' => $cassette['COL']]);
        app()->instance(LLMProvider::class, $cassetteLlm);

        // Dispatched, never `(new ScoreEvaluationJob(...))->handle()` — see
        // this method's own docblock.
        ScoreEvaluationJob::dispatch($participant->id);

        return $participant->fresh();
    }
}
