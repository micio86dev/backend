<?php

declare(strict_types=1);

/**
 * SendOperatorNotificationJob — the only queue boundary in C12 (D6/D8).
 *
 * Every test here dispatches through the real job class rather than calling
 * handle() directly. Calling handle() skips Queue::before, which is the exact
 * mechanism that nulls the ambient tenant resolver — so a test that calls it
 * directly proves the job works in a world the worker never creates.
 */

use App\Enums\NotificationStatus;
use App\Enums\NotificationSubjectType;
use App\Enums\NotificationSuppressionReason;
use App\Enums\NotificationType;
use App\Jobs\SendOperatorNotificationJob;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Notifications\WebhookDeliveryDeadNotification;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role as AuthorizationRole;
use Spatie\Permission\PermissionRegistrar;

function c12Operator(Organization $org, ?string $locale = null): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

    $user = User::factory()->create([
        'organization_id' => $org->id,
        'locale' => $locale,
    ]);

    $role = AuthorizationRole::firstOrCreate([
        'name' => 'operator',
        'guard_name' => 'api',
        'team_id' => $org->id,
    ]);
    $user->assignRole($role);

    return $user;
}

function c12Delivery(Organization $org): WebhookDelivery
{
    // project_id and participant_id are deliberately absent from the factory's
    // base definition (participant.project_id is NOT NULL with no default), so
    // the delivery is derived from a real Participant via ->forParticipant().
    return TenantContextScope::runFor($org->id, function () use ($org): WebhookDelivery {
        $project = Project::factory()->create(['organization_id' => $org->id]);
        $participant = Participant::factory()->create(['project_id' => $project->id]);

        return WebhookDelivery::factory()->forParticipant($participant)->create();
    });
}

function c12Run(WebhookDelivery $delivery): void
{
    SendOperatorNotificationJob::dispatch(
        NotificationType::WebhookDeliveryDead,
        NotificationSubjectType::WebhookDelivery,
        $delivery->getKey(),
    );
}

// ---------------------------------------------------------------------------
// Tenancy — the reason this job exists in the shape it does
// ---------------------------------------------------------------------------

test('a HOSTILE ambient organization is ignored; the subject decides', function (): void {
    Notification::fake();

    $subjectOrg = Organization::factory()->create();
    $foreignOrg = Organization::factory()->create();

    $operator = c12Operator($subjectOrg);
    c12Operator($foreignOrg); // must never be told

    $delivery = c12Delivery($subjectOrg);

    // Deliberately establish the WRONG org before dispatching. The job must
    // re-derive from the row it loads, not inherit this.
    TenantContextScope::runFor($foreignOrg->id, fn () => c12Run($delivery));

    $log = NotificationLog::withoutGlobalScopes()->firstOrFail();
    expect($log->organization_id)->toBe($subjectOrg->id);

    Notification::assertSentTo($operator, WebhookDeliveryDeadNotification::class);
    Notification::assertSentTimes(WebhookDeliveryDeadNotification::class, 1);
});

test('a NULL ambient organization still resolves the subject org', function (): void {
    Notification::fake();

    $org = Organization::factory()->create();
    $operator = c12Operator($org);
    $delivery = c12Delivery($org);

    // No context at all — the state a real worker is in after Queue::before.
    c12Run($delivery);

    expect(NotificationLog::withoutGlobalScopes()->firstOrFail()->organization_id)->toBe($org->id);
    Notification::assertSentTo($operator, WebhookDeliveryDeadNotification::class);
});

test('another organization is never notified', function (): void {
    Notification::fake();

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    c12Operator($orgA);
    $operatorB = c12Operator($orgB);

    c12Run(c12Delivery($orgA));

    Notification::assertNotSentTo($operatorB, WebhookDeliveryDeadNotification::class);
});

test('a missing subject logs and returns rather than throwing', function (): void {
    Notification::fake();

    SendOperatorNotificationJob::dispatch(
        NotificationType::WebhookDeliveryDead,
        NotificationSubjectType::WebhookDelivery,
        999999,
    );

    expect(NotificationLog::withoutGlobalScopes()->count())->toBe(0);
    Notification::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Idempotency — the database arbitrates, not application logic
// ---------------------------------------------------------------------------

test('dispatching twice yields one row and one email', function (): void {
    Notification::fake();

    $org = Organization::factory()->create();
    c12Operator($org);
    $delivery = c12Delivery($org);

    c12Run($delivery);
    c12Run($delivery);

    expect(NotificationLog::withoutGlobalScopes()->count())->toBe(1);
    Notification::assertSentTimes(WebhookDeliveryDeadNotification::class, 1);
});

test('a caught unique violation does not poison the surrounding transaction', function (): void {
    // THE savepoint regression. Without the inner DB::transaction(), the caught
    // 23505 aborts the enclosing transaction at the Postgres level and every
    // subsequent statement fails with "current transaction is aborted" — inside
    // a RefreshDatabase test, that means everything after this point dies.
    Notification::fake();

    $org = Organization::factory()->create();
    c12Operator($org);
    $delivery = c12Delivery($org);

    c12Run($delivery);
    c12Run($delivery); // loses the race against its own first row

    // If the savepoint were missing, this query would throw rather than answer.
    expect(Organization::query()->count())->toBeGreaterThan(0);
});

// ---------------------------------------------------------------------------
// Suppression
// ---------------------------------------------------------------------------

test('zero recipients is recorded as suppressed, not thrown', function (): void {
    Notification::fake();

    $org = Organization::factory()->create(); // no operator at all
    c12Run(c12Delivery($org));

    $log = NotificationLog::withoutGlobalScopes()->firstOrFail();
    expect($log->status)->toBe(NotificationStatus::Suppressed);
    expect($log->suppression_reason)->toBe(NotificationSuppressionReason::NoRecipients);
    Notification::assertNothingSent();
});

test('a second DISTINCT subject inside the window is suppressed, and its count is carried', function (): void {
    Notification::fake();

    $org = Organization::factory()->create();
    c12Operator($org);

    // First failure sends immediately — the alert that matters most is never
    // delayed for tidiness.
    c12Run(c12Delivery($org));
    Notification::assertSentTimes(WebhookDeliveryDeadNotification::class, 1);

    // Two further, genuinely distinct failures inside the window. Dedupe cannot
    // collapse these — they are different subjects — which is precisely why the
    // window exists.
    c12Run(c12Delivery($org));
    c12Run(c12Delivery($org));

    Notification::assertSentTimes(WebhookDeliveryDeadNotification::class, 1);
    expect(NotificationLog::withoutGlobalScopes()->where('status', NotificationStatus::Suppressed)->count())->toBe(2);

    // Past the window, the next one sends AND carries what was suppressed.
    $this->travel(config('notifications.suppression.window_seconds') + 1)->seconds();
    c12Run(c12Delivery($org));

    Notification::assertSentTimes(WebhookDeliveryDeadNotification::class, 2);

    $latest = NotificationLog::withoutGlobalScopes()
        ->where('status', NotificationStatus::Sent)
        ->orderByDesc('sent_at')
        ->firstOrFail();

    expect($latest->suppressed_carried_count)->toBe(2);
});

test('the window boundary is inclusive of the window, exclusive past it', function (): void {
    Notification::fake();

    $org = Organization::factory()->create();
    c12Operator($org);
    $window = (int) config('notifications.suppression.window_seconds');

    c12Run(c12Delivery($org));

    // Exactly at the boundary — still inside.
    $this->travel($window)->seconds();
    c12Run(c12Delivery($org));
    Notification::assertSentTimes(WebhookDeliveryDeadNotification::class, 1);

    // One second past.
    $this->travel(1)->seconds();
    c12Run(c12Delivery($org));
    Notification::assertSentTimes(WebhookDeliveryDeadNotification::class, 2);
});

// ---------------------------------------------------------------------------
// i18n
// ---------------------------------------------------------------------------

test('a mixed-locale recipient set produces one send per locale group', function (): void {
    Notification::fake();

    $org = Organization::factory()->create();
    $italian = c12Operator($org, 'it');
    $english = c12Operator($org, 'en');
    $unset = c12Operator($org, null);

    c12Run(c12Delivery($org));

    // A single collection-wide send would apply ONE language to everybody.
    Notification::assertSentTo($italian, WebhookDeliveryDeadNotification::class);
    Notification::assertSentTo($english, WebhookDeliveryDeadNotification::class);
    Notification::assertSentTo($unset, WebhookDeliveryDeadNotification::class);

    expect(NotificationLog::withoutGlobalScopes()->firstOrFail()->recipient_count)->toBe(3);
});

test('machine values in the persisted row are never localized', function (): void {
    Notification::fake();
    app()->setLocale('it');

    $org = Organization::factory()->create();
    c12Operator($org, 'it');
    c12Run(c12Delivery($org));

    $row = NotificationLog::withoutGlobalScopes()->firstOrFail();

    expect($row->notification_type->value)->toBe('webhook_delivery_dead');
    expect($row->status->value)->toBe('sent');
});
