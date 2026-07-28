<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Models\Participant;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory for WebhookDelivery (C10 — Webhooks Integration).
 *
 * NOTE: organization_id is NOT fillable — it is stamped by TenantScoped.creating from
 * the active TenantResolver. Callers MUST create inside
 * App\Support\Tenancy\TenantContextScope::runFor() (design.md D4).
 *
 * project_id/participant_id are intentionally absent from the base definition() —
 * mirrors ParticipantFactory::forProject(): a bare nested Participant::factory() default
 * would fail (participant.project_id is NOT NULL with no factory default). Use
 * ->forParticipant($participant) to derive both FKs consistently.
 *
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    /**
     * Define the model's default state — a legal 'pending' row.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'delivery_id' => (string) Str::uuid(),
            'event_type' => WebhookEventType::Evaluation,
            'dedupe_key' => (string) Str::uuid(),
            'status' => WebhookDeliveryStatus::Pending,
            'skip_reason' => null,
            'target_url' => 'https://example.test/hook',
            'payload' => ['foo' => 'bar'],
            'payload_version' => '1.0',
            'attempt_count' => 0,
            'max_attempts' => 6,
        ];
    }

    /**
     * Derive project_id and participant_id from an existing Participant.
     */
    public function forParticipant(Participant $participant): static
    {
        return $this->state(fn (array $attrs): array => [
            'project_id' => $participant->project_id,
            'participant_id' => $participant->id,
        ]);
    }
}
