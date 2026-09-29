<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ProjectQuestion;
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

    public function participant(): Participant
    {
        return $this->memo['participant'] ??= TenantContextScope::runFor(
            $this->world->orgA->id,
            fn (): Participant => Participant::factory()
                ->forProject($this->project())
                ->create(['display_name' => "{$this->world->marker} participant"]),
        );
    }
}
