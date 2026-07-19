<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApiClient;
use App\Models\Organization;
use App\Services\ApiKeyGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiClient>
 */
class ApiClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name'            => fake()->words(3, true) . ' client',
            'key_hash'        => ApiKeyGenerator::hash(ApiKeyGenerator::generate()),
            'abilities'       => ['participants:read'],
            'is_active'       => true,
            'expires_at'      => null,
            'last_used_at'    => null,
        ];
    }

    /**
     * Make an inactive client.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Make an expired client (expires_at in the past).
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    /**
     * Make a client with a specific raw key (stores its hash).
     * Returns the instance so the raw key is accessible.
     */
    public function withRawKey(string $rawKey): static
    {
        return $this->state(['key_hash' => ApiKeyGenerator::hash($rawKey)]);
    }
}
