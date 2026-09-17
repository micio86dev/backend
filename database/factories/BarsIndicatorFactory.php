<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for BarsIndicator (framework-catalogue-authoring, G3.2).
 *
 * Before this factory existed, the suite constructed `BarsIndicator` rows
 * via many independent per-file helpers (`new BarsIndicator; ->forceFill([...])`
 * or a raw `DB::table('framework_bars_indicators')->insert(...)`) — see the
 * docblock on `2026_09_15_201435_drop_catalogue_revision_defaults.php` for
 * the measured blast radius (223 tests) that made dropping this table's own
 * `revision_id` DEFAULT unsafe to do blindly. This factory is the single,
 * shared construction path callers can migrate onto instead: it creates its
 * own `Role`/`Competency` when neither is supplied, and `position` defaults
 * to a fresh value `faker->unique()` has not produced before anywhere in
 * this run (R2-factory-docblock-position-claim: NOT scoped to the
 * (revision, role, competency) group it targets — a run-wide guarantee is
 * strictly stronger than a per-group one, so this still never collides with
 * the `unique(role_id, competency_id, position)` index, it just does not
 * reuse a low position the way an explicitly-scoped counter would).
 *
 * `revision_id` is deliberately NOT defaulted here, mirroring `RoleFactory`/
 * `CompetencyFactory`'s own documented choice: this table still carries a
 * DB-level `DEFAULT <baseline id>` (the drop was reverted for this table
 * specifically), so an omitted `revision_id` resolves to the baseline the
 * same way every other pre-existing direct-construction call site already
 * does. A caller that cares about the revision still overrides it exactly as
 * before (`BarsIndicator::factory()->create(['revision_id' => $x])`).
 *
 * @extends Factory<BarsIndicator>
 */
class BarsIndicatorFactory extends Factory
{
    protected $model = BarsIndicator::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role_id' => fn (array $attrs) => Role::factory()->create(['revision_id' => $attrs['revision_id'] ?? null])->id,
            'competency_id' => fn (array $attrs) => Competency::factory()->create(['revision_id' => $attrs['revision_id'] ?? null])->id,
            'text' => ['en' => $this->faker->sentence()],
            'anchor_5' => ['en' => $this->faker->sentence()],
            'anchor_3' => ['en' => $this->faker->sentence()],
            'anchor_1' => ['en' => $this->faker->sentence()],
            'position' => $this->faker->unique()->numberBetween(0, 100000),
        ];
    }

    /**
     * Role-less state — MTG/LAT-shaped (`potential`), the same shape
     * `BarsIndicator.role_id: NULL` documents on the model.
     */
    public function roleLess(): static
    {
        return $this->state(fn (array $attrs) => [
            'role_id' => null,
        ]);
    }
}
