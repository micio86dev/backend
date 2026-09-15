<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FrameworkCatalogRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for FrameworkCatalogRevision (framework-catalogue-authoring PR1, D1).
 *
 * The default is `published` because that is what a factory-made revision is
 * almost always standing in for: settled catalogue content something else
 * points at. `draft()` exists for the tests that need the mutable one — and
 * still only ONE at a time, because
 * `framework_catalog_revisions_one_draft` allows at most one open draft on
 * the whole platform. That index has its own test now.
 *
 * @extends Factory<FrameworkCatalogRevision>
 */
class FrameworkCatalogRevisionFactory extends Factory
{
    protected $model = FrameworkCatalogRevision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'state' => 'published',
            'is_baseline' => false,
            'label' => $this->faker->optional()->sentence(3),
            'published_at' => now(),
        ];
    }

    /**
     * Named state: an open draft revision. Callers MUST ensure no other
     * draft exists (including the baseline) — the partial unique index
     * refuses a second one.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attrs) => [
            'state' => 'draft',
            'published_at' => null,
        ]);
    }
}
