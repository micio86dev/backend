<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for Role (C3 Framework Catalog global model).
 *
 * Used in scoring tests (PR2) to create BARS indicator relationships.
 *
 * `revision_id` is deliberately NOT defaulted here (framework-catalogue-
 * authoring PR3b, H10): `Role::booted()`'s own `creating` listener already
 * defaults it to the baseline whenever it is still null, for EVERY creation
 * path — factory or a bare `Role::create([...])` alike — because it fires on
 * the model, not on this factory. A second, independent default here was a
 * duplicate of that same fallback, doing nothing this factory's callers
 * could observe (the model listener runs regardless, and only when the
 * attribute is still unset). A caller that DOES care about the revision
 * still overrides it exactly as before
 * (`Role::factory()->create(['revision_id' => $x])`).
 *
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = strtoupper($this->faker->unique()->lexify('???'));

        return [
            'code' => $code,
            'name' => ['en' => $this->faker->words(3, true)],
            'responsibilities' => ['en' => $this->faker->sentence()],
        ];
    }
}
