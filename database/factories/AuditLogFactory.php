<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'actor_id' => null,
            'action' => 'api_client.revoked',
            'subject_type' => 'api_client',
            'subject_id' => $this->faker->numberBetween(1, 100000),
            'before' => ['is_active' => true],
            'after' => ['is_active' => false],
        ];
    }
}
