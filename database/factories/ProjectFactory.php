<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AvatarTemplate;
use App\Models\FrameworkVersion;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory for Project (C4 Project Configuration).
 *
 * Defaults to a 'standard' type draft project with role_code 'ICO'.
 * Named states: standard(), potential().
 *
 * NOTE: organization_id is NOT fillable — it is stamped by TenantScoped.creating
 * from the active TenantResolver. In tests, set the resolver before creating projects.
 *
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'framework_version_id' => FrameworkVersion::factory(),
            'slug' => Str::slug($this->faker->unique()->words(3, true)),
            'name' => $this->faker->sentence(4),
            'assessment_type' => 'standard',
            'role_code' => 'ICO',
            'language' => 'en',
            'status' => 'draft',
            'webhook_url' => null,
            'webhook_secret' => null,
            // `avatar_template_id` is NOT NULL — a project must name the
            // template it runs on. A closure, not an eager
            // `AvatarTemplate::factory()`, so it resolves at CREATE time inside
            // whatever tenant context the test has already set: the template
            // gets its `organization_id` from the same TenantResolver stamping
            // the project, and the two cannot land in different tenants.
            //
            // Reuses an existing template for the current tenant when there is
            // one. Without that, a test creating three projects would silently
            // create three templates, and any assertion counting an
            // organization's templates would drift with the number of projects
            // its setup happens to make.
            // Created inline rather than through a factory: `AvatarTemplate`
            // has none, because its rows are shaped by provider-specific config
            // and every existing test builds them explicitly.
            // ACTIVE FIRST, and that ordering is load-bearing rather than
            // tidiness. A test that builds an active template and then a
            // project is describing "the project runs on this template" —
            // before the column existed it got that for free from the
            // organization-wide fallback. Picking an arbitrary row here would
            // pin a bare one instead and silently change what the test
            // measures, which is exactly how the live-clock ceiling test
            // started reading the platform default instead of the 300s the
            // template configured.
            'avatar_template_id' => fn (): int => AvatarTemplate::query()
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->value('id')
                ?? AvatarTemplate::create([
                    'name' => 'Factory template '.Str::random(10),
                    'provider' => 'heygen',
                    'config' => [],
                ])->id,
        ];
    }

    /**
     * Standard assessment type (ICO role, standard competencies).
     */
    public function standard(): static
    {
        return $this->state(fn (array $attrs) => [
            'assessment_type' => 'standard',
            'role_code' => 'ICO',
        ]);
    }

    /**
     * Potential assessment type (no role_code).
     */
    public function potential(): static
    {
        return $this->state(fn (array $attrs) => [
            'assessment_type' => 'potential',
            'role_code' => null,
        ]);
    }
}
