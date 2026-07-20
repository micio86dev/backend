<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for Participant (C6 — Participant + SSO Ingress).
 *
 * Defaults to an in_attesa participant on a standard project.
 *
 * NOTE: organization_id is NOT fillable — it must be set via forceFill.
 * The factory uses afterCreating hook to ensure org is set from project.
 *
 * @extends Factory<Participant>
 */
class ParticipantFactory extends Factory
{
    protected $model = Participant::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_ref' => $this->faker->unique()->uuid(),
            'display_name'  => $this->faker->name(),
            'role_code'     => 'ICO',
            'language'      => 'en',
            'status'        => 'in_attesa',
            'started_at'    => null,
            'completed_at'  => null,
        ];
    }

    /**
     * Configure the factory.
     *
     * Uses afterMaking to set organization_id from the project if provided.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Participant $participant): void {
            // If project_id is set but organization_id is not, derive it from the project.
            if ($participant->project_id && ! $participant->organization_id) {
                $project = Project::find($participant->project_id);
                if ($project) {
                    $participant->forceFill(['organization_id' => $project->organization_id]);
                }
            }
        });
    }

    /**
     * Create a participant for a specific organization and project.
     */
    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attrs) => [
            'project_id' => $project->id,
        ])->afterMaking(function (Participant $participant) use ($project): void {
            $participant->forceFill(['organization_id' => $project->organization_id]);
        });
    }

    /**
     * Potential assessment type (no role_code).
     */
    public function potential(): static
    {
        return $this->state(fn (array $attrs) => [
            'role_code' => null,
        ]);
    }

    /**
     * Participant with a specific status.
     */
    public function withStatus(string $status): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => $status,
        ]);
    }
}
