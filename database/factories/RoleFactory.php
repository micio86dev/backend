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
