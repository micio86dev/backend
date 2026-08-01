<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationStatus;
use App\Enums\NotificationSubjectType;
use App\Enums\NotificationSuppressionReason;
use App\Enums\NotificationType;
use App\Models\NotificationLog;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    protected $model = NotificationLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'notification_type' => NotificationType::WebhookDeliveryDead,
            'subject_type' => NotificationSubjectType::WebhookDelivery,
            'subject_id' => $this->faker->unique()->numberBetween(1, 100000),
            'status' => NotificationStatus::Pending,
            'suppression_reason' => null,
            'recipient_count' => 0,
            'suppressed_carried_count' => 0,
            'last_error' => null,
            'sent_at' => null,
        ];
    }

    /**
     * A delivered notification. `sent_at` is not optional decoration — a CHECK
     * constraint ties it to the `sent` status in both directions.
     */
    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationStatus::Sent,
            'sent_at' => now(),
            'recipient_count' => 1,
        ]);
    }

    /**
     * Suppressed by the storm window. The reason is likewise CHECK-bound to the
     * status, so it cannot be omitted here.
     */
    public function suppressed(NotificationSuppressionReason $reason = NotificationSuppressionReason::Window): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationStatus::Suppressed,
            'suppression_reason' => $reason,
        ]);
    }

    public function failed(string $error = 'transport unavailable'): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationStatus::Failed,
            'last_error' => $error,
        ]);
    }
}
