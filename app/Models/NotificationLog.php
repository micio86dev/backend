<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationStatus;
use App\Enums\NotificationSubjectType;
use App\Enums\NotificationSuppressionReason;
use App\Enums\NotificationType;
use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An operator notification, recorded before it is sent (C12, D3).
 *
 * Extends TenantModel so the TenantScoped global scope applies and
 * tests/Arch/C2/TenantModelArchTest.php can see it.
 *
 * `organization_id` is deliberately NOT in $fillable. Mass-assigning the tenant
 * key is the whole hazard that arch test exists to prevent; the dispatcher job
 * sets it from the subject it loaded, inside a TenantContextScope boundary.
 *
 * @property int $id
 * @property int $organization_id
 * @property NotificationType $notification_type
 * @property NotificationSubjectType $subject_type
 * @property int $subject_id
 * @property NotificationStatus $status
 * @property NotificationSuppressionReason|null $suppression_reason
 * @property int $recipient_count
 * @property int $suppressed_carried_count
 * @property string|null $last_error
 * @property Carbon|null $sent_at
 */
class NotificationLog extends TenantModel
{
    /** @use HasFactory<NotificationLogFactory> */
    use HasFactory;

    protected $fillable = [
        'notification_type',
        'subject_type',
        'subject_id',
        'status',
        'suppression_reason',
        'recipient_count',
        'suppressed_carried_count',
        'last_error',
        'sent_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'notification_type' => NotificationType::class,
            'subject_type' => NotificationSubjectType::class,
            'status' => NotificationStatus::class,
            'suppression_reason' => NotificationSuppressionReason::class,
            'sent_at' => 'datetime',
            'recipient_count' => 'integer',
            'suppressed_carried_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
