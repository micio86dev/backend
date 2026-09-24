<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InterviewEvent;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for InterviewEvent (public-api step 5, G-34).
 *
 * NOTE: organization_id is stamped by `App\Models\Concerns\TenantScoped`'s
 * creating listener from the ambient tenant resolver — the same discipline
 * every other `TenantModel` factory in this codebase relies on. A caller
 * outside an org-scoped context must wrap creation in
 * `App\Support\Tenancy\TenantContextScope::runFor()`.
 *
 * @extends Factory<InterviewEvent>
 */
class InterviewEventFactory extends Factory
{
    protected $model = InterviewEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'participant_id' => Participant::factory(),
            'type' => 'created',
            'occurred_at' => now(),
            'data' => null,
        ];
    }
}
