<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

use App\Enums\ParticipantSchedulingStatus;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\ReusableInterviewLink;
use App\Models\User;
use App\Models\Utterance;
use App\Support\Tenancy\TenantContextScope;

/**
 * The target resources of a matrix case, ALL owned by `orgA`.
 *
 * Built lazily and memoised, so a route that needs a participant does not pay
 * for a session and an evaluation it never touches. Everything carries the
 * world's marker in a human-readable field, which is how the runner proves a
 * denied response leaked nothing about a foreign resource.
 */
final class AuthMatrixResources
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly AuthMatrixWorld $world) {}

    public function world(): AuthMatrixWorld
    {
        return $this->world;
    }

    /**
     * The platform-level and avatar-template targets (T5).
     */
    public function platform(): AuthMatrixPlatformResources
    {
        return $this->world->platform();
    }

    public function project(): Project
    {
        return $this->memo['project'] ??= TenantContextScope::runFor($this->world->orgA->id, function (): Project {
            $fv = FrameworkVersion::factory()->create(['organization_id' => $this->world->orgA->id]);

            return Project::factory()->create([
                'framework_version_id' => $fv->id,
                'name' => "{$this->world->marker} project",
            ]);
        });
    }

    /**
     * The project made INTERVIEWABLE and open (`active`): the only kind an
     * entry link may be minted for. Mutates the memoised project, so a case
     * that needs the default `draft` one must not call it.
     */
    public function openProject(): Project
    {
        $this->question();

        return TenantContextScope::runFor($this->world->orgA->id, function (): Project {
            $project = $this->project();
            $project->forceFill(['status' => 'active'])->save();

            return $project;
        });
    }

    /**
     * The project's one selected competency (PRS), on the project's own
     * pinned revision — the only kind a question may be authored against.
     */
    public function competency(): Competency
    {
        $this->question();

        return $this->memo['competency'];
    }

    /**
     * A second selected competency (STG) that has NO question yet, so a
     * `standard` project (one question per competency) still has room for one
     * authored by a POST.
     */
    public function spareCompetency(): Competency
    {
        return $this->memo['spare'] ??= TenantContextScope::runFor(
            $this->world->orgA->id,
            function (): Competency {
                $project = $this->project();
                $this->question();

                $competency = Competency::firstOrCreate(
                    ['code' => 'STG', 'revision_id' => $project->frameworkVersion?->revision_id],
                    ['name' => ['en' => 'x'], 'definition' => ['en' => 'x'], 'type' => 'standard'],
                );
                $project->competencies()->syncWithoutDetaching([$competency->id => ['position' => 1]]);

                return $competency;
            },
        );
    }

    /**
     * A live question of the project. Making the project interviewable is
     * what gives it a selected competency AND a question in one go.
     */
    public function question(): ProjectQuestion
    {
        if (! isset($this->memo['question'])) {
            $project = $this->project();

            $this->memo['competency'] = TenantContextScope::runFor(
                $this->world->orgA->id,
                fn (): Competency => makeProjectInterviewable($project),
            );
            $this->memo['question'] = TenantContextScope::runFor(
                $this->world->orgA->id,
                fn (): ProjectQuestion => ProjectQuestion::query()->where('project_id', $project->id)->firstOrFail(),
            );
        }

        return $this->memo['question'];
    }

    /**
     * An active reusable link of the project, the target of the disable route.
     * Disabling an already-disabled link is an idempotent 204, so the one link
     * serves every actor the matrix sends at it.
     */
    public function reusableLink(): ReusableInterviewLink
    {
        return $this->memo['reusable_link'] ??= TenantContextScope::runFor(
            $this->world->orgA->id,
            fn (): ReusableInterviewLink => ReusableInterviewLink::factory()->forProject($this->project())->create(),
        );
    }

    /**
     * The participant every READ route targets: a finished (`completato`)
     * candidate with a session and utterance, so the transcript, the
     * evaluation and both downloads are past their lifecycle gates.
     */
    public function participant(): Participant
    {
        return $this->memo['participant'] ??= TenantContextScope::runFor(
            $this->world->orgA->id,
            function (): Participant {
                $participant = $this->newParticipant('completato', 'participant');
                $this->newSession($participant, 'completed');
                $this->newEvaluation($participant);

                return $participant;
            },
        );
    }

    /**
     * A participant an operator may recover: `errore`, with one errored session.
     */
    public function erroredParticipant(): Participant
    {
        return $this->memo['errored'] ??= TenantContextScope::runFor(
            $this->world->orgA->id,
            function (): Participant {
                $participant = $this->newParticipant('errore', 'errored participant');
                $this->newSession($participant, 'error');

                return $participant;
            },
        );
    }

    /**
     * A participant whose evaluation retry may be authorized: `completato`,
     * a `pending` evaluation, on an open project (the retry mints a link).
     */
    public function retryableParticipant(): Participant
    {
        return $this->memo['retryable'] ??= TenantContextScope::runFor(
            $this->world->orgA->id,
            function (): Participant {
                $this->openProject();
                $participant = $this->newParticipant('completato', 'retryable participant');
                Evaluation::factory()->pending()->create(['participant_id' => $participant->id]);

                return $participant;
            },
        );
    }

    /**
     * A participant with a pending scheduled start, which may be rescheduled or cancelled.
     */
    public function scheduledParticipant(): Participant
    {
        return $this->memo['scheduled'] ??= TenantContextScope::runFor(
            $this->world->orgA->id,
            function (): Participant {
                $participant = $this->newParticipant('in_attesa', 'scheduled participant');
                $participant->forceFill([
                    'scheduled_at' => now('UTC')->addHours(3),
                    'scheduling_status' => ParticipantSchedulingStatus::Pending,
                ])->save();

                return $participant->refresh();
            },
        );
    }

    /**
     * An ACTIVE viewer of orgA: the user an admin edits or deactivates.
     */
    public function member(): User
    {
        return $this->memo['member'] ??= $this->newMember('member');
    }

    /**
     * A DEACTIVATED viewer of orgA: the user an admin may bring back.
     */
    public function deactivatedMember(): User
    {
        return $this->memo['deactivated'] ??= (function (): User {
            $user = $this->newMember('deactivated member');
            $user->forceFill(['deactivated_at' => now()])->save();

            return $user->refresh();
        })();
    }

    private function newMember(string $label): User
    {
        $user = authUserAndTokenForRole($this->world->orgA, 'viewer')['user'];
        $user->forceFill(['name' => "{$this->world->marker} {$label}"])->save();

        return $user->refresh();
    }

    public function session(): InterviewSession
    {
        $this->participant();

        return $this->memo['session'];
    }

    public function evaluation(): Evaluation
    {
        $this->participant();

        return $this->memo['evaluation'];
    }

    private function newParticipant(string $status, string $label): Participant
    {
        return Participant::factory()
            ->forProject($this->project())
            ->withStatus($status)
            ->create(['display_name' => "{$this->world->marker} {$label}"]);
    }

    private function newSession(Participant $participant, string $status): InterviewSession
    {
        $project = $this->project();

        $session = InterviewSession::create([
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => 0,
            'competency_code' => 'COL',
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'fake',
            'status' => $status,
        ]);

        Utterance::create([
            'interview_session_id' => $session->id,
            'speaker' => 'Candidate',
            'text' => "{$this->world->marker} spoken words",
            'ts' => '2024-01-01 10:00:00',
        ]);

        return $this->memo['session'] ??= $session;
    }

    private function newEvaluation(Participant $participant): Evaluation
    {
        $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);
        $result = CompetencyResult::factory()->create([
            'evaluation_id' => $evaluation->id,
            'competency_code' => 'COL',
            'score' => 4.0,
            'reliability' => 0.67,
        ]);
        IndicatorScore::factory()->create(['competency_result_id' => $result->id, 'position' => 0]);

        return $this->memo['evaluation'] = $evaluation;
    }
}
