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
        $rawKey = ApiKeyGenerator::generate();

        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->words(3, true).' client',
            'key_hash' => ApiKeyGenerator::hash($rawKey),
            'key_prefix' => ApiKeyGenerator::prefixOf($rawKey),
            'mode' => 'live',
            'abilities' => ['participants:read'],
            'is_active' => true,
            'expires_at' => null,
            'last_used_at' => null,
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
     * Make a client with a specific raw key (stores its hash + prefix).
     * Returns the instance so the raw key is accessible.
     *
     * `key_prefix`/`mode` are derived from the raw key itself whenever it
     * carries a recognised `beai_live_`/`beai_test_` marker — a malformed
     * raw key (used by a handful of guard-resolution tests) leaves
     * `key_prefix` null, mirroring a pre-migration row.
     */
    public function withRawKey(string $rawKey): static
    {
        $mode = ApiKeyGenerator::modeOf($rawKey);

        return $this->state([
            'key_hash' => ApiKeyGenerator::hash($rawKey),
            'key_prefix' => $mode !== null ? ApiKeyGenerator::prefixOf($rawKey) : null,
            'mode' => $mode ?? 'live',
        ]);
    }

    /**
     * Make a `test`-mode client (public-api step 2, SPEC.md §3.7).
     */
    public function testMode(): static
    {
        return $this->state(['mode' => 'test']);
    }

    /**
     * A client with no `key_prefix` — the shape of a row created before this
     * migration, whose raw key is unrecoverable. Used by the legacy-fallback
     * coverage in `AuthenticatePublicApiTest`/`GuardResolutionTest`.
     */
    public function preMigrationRow(): static
    {
        return $this->state(['key_prefix' => null]);
    }
}
