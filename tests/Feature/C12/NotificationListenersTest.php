<?php

declare(strict_types=1);

/**
 * The listeners, the anti-spam invariant, and what happens when the notifier
 * itself fails (C12, D5/D8).
 */

use App\Enums\NotificationStatus;
use App\Enums\NotificationSubjectType;
use App\Enums\NotificationType;
use App\Events\EvaluationCompleted;
use App\Events\EvaluationFailed;
use App\Events\WebhookDeliveryDead;
use App\Jobs\SendOperatorNotificationJob;
use App\Listeners\NotifyOnScoringFailure;
use App\Listeners\NotifyOnWebhookDeliveryDead;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Support\Notifications\OperatorRecipientResolver;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role as AuthorizationRole;
use Spatie\Permission\PermissionRegistrar;

// ---------------------------------------------------------------------------
// Wiring
// ---------------------------------------------------------------------------

test('both listeners are auto-discovered for their events', function (): void {
    // Auto-discovery is a convention, not a declaration — nothing in the repo
    // states this wiring, so nothing but a test can notice it breaking.
    expect(Event::getListeners(WebhookDeliveryDead::class))->not->toBeEmpty();
    expect(Event::getListeners(EvaluationFailed::class))->not->toBeEmpty();
});

test('the dead-letter listener dispatches the job with scalars only', function (): void {
    Bus::fake();

    (new NotifyOnWebhookDeliveryDead)->handle(new WebhookDeliveryDead(42));

    Bus::assertDispatched(
        SendOperatorNotificationJob::class,
        fn (SendOperatorNotificationJob $job): bool => $job->type === NotificationType::WebhookDeliveryDead
            && $job->subjectType === NotificationSubjectType::WebhookDelivery
            && $job->subjectId === 42
    );
});

test('the scoring listener dispatches the job with scalars only', function (): void {
    Bus::fake();

    (new NotifyOnScoringFailure)->handle(new EvaluationFailed(7));

    Bus::assertDispatched(
        SendOperatorNotificationJob::class,
        fn (SendOperatorNotificationJob $job): bool => $job->type === NotificationType::ScoringFailed
            && $job->subjectType === NotificationSubjectType::Participant
            && $job->subjectId === 7
    );
});

// ---------------------------------------------------------------------------
// A notification bug must never damage what it reports on
// ---------------------------------------------------------------------------

test('a throwing dispatch never escapes the scoring listener', function (): void {
    // This listener runs SYNCHRONOUSLY inside ScoreEvaluationJob::failed(). A
    // throw here would corrupt the handling of the very failure it is
    // reporting — turning "we could not score this candidate" into "and we lost
    // the record of why".
    Bus::fake();
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('queue is down'));

    (new NotifyOnScoringFailure)->handle(new EvaluationFailed(7));

    expect(true)->toBeTrue(); // reaching this line IS the assertion
});

test('a throwing dispatch never escapes the dead-letter listener', function (): void {
    Bus::fake();
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('queue is down'));

    (new NotifyOnWebhookDeliveryDead)->handle(new WebhookDeliveryDead(42));

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// The anti-spam invariant — a requirement, so it gets a test
// ---------------------------------------------------------------------------

test('EvaluationCompleted produces ZERO notifications', function (): void {
    Bus::fake();
    Notification::fake();

    // One per candidate would make a 500-candidate campaign 500 emails. This is
    // dashboard territory (C11), and the absence is asserted rather than
    // assumed — an absence nobody tests is an absence nobody maintains.
    EvaluationCompleted::dispatch(1);

    Bus::assertNotDispatched(SendOperatorNotificationJob::class);
    Notification::assertNothingSent();
});

// ---------------------------------------------------------------------------
// When the notifier itself fails
// ---------------------------------------------------------------------------

test('a failing send records `failed` with the error and rethrows for the queue', function (): void {
    $org = Organization::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $user->assignRole(AuthorizationRole::firstOrCreate([
        'name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id,
    ]));

    $delivery = TenantContextScope::runFor($org->id, function () use ($org): WebhookDelivery {
        $project = Project::factory()->create(['organization_id' => $org->id]);
        $participant = Participant::factory()->create(['project_id' => $project->id]);

        return WebhookDelivery::factory()->forParticipant($participant)->create();
    });

    Notification::shouldReceive('sendNow')->andThrow(new RuntimeException('smtp unreachable'));

    $job = new SendOperatorNotificationJob(
        NotificationType::WebhookDeliveryDead,
        NotificationSubjectType::WebhookDelivery,
        $delivery->getKey(),
    );

    expect(fn () => $job->handle(app(OperatorRecipientResolver::class)))
        ->toThrow(RuntimeException::class);

    $log = NotificationLog::withoutGlobalScopes()->firstOrFail();

    // The row records the attempt; the QUEUE owns the retry. Swallowing the
    // exception here would leave a `failed` row that never gets another chance.
    expect($log->status)->toBe(NotificationStatus::Failed);
    expect($log->last_error)->toContain('smtp unreachable');
});

test('last_error is truncated to the configured length', function (): void {
    $max = (int) config('notifications.dispatch.last_error_max_chars');
    expect($max)->toBeGreaterThan(0);

    $org = Organization::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $user->assignRole(AuthorizationRole::firstOrCreate([
        'name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id,
    ]));

    $delivery = TenantContextScope::runFor($org->id, function () use ($org): WebhookDelivery {
        $project = Project::factory()->create(['organization_id' => $org->id]);
        $participant = Participant::factory()->create(['project_id' => $project->id]);

        return WebhookDelivery::factory()->forParticipant($participant)->create();
    });

    Notification::shouldReceive('sendNow')->andThrow(new RuntimeException(str_repeat('x', $max + 500)));

    $job = new SendOperatorNotificationJob(
        NotificationType::WebhookDeliveryDead,
        NotificationSubjectType::WebhookDelivery,
        $delivery->getKey(),
    );

    try {
        $job->handle(app(OperatorRecipientResolver::class));
    } catch (RuntimeException) {
        // expected — the queue owns the retry
    }

    $log = NotificationLog::withoutGlobalScopes()->firstOrFail();
    expect(mb_strlen((string) $log->last_error))->toBe($max);
});

test('the job declares its own retry contract rather than inheriting the worker default', function (): void {
    $job = new SendOperatorNotificationJob(
        NotificationType::ScoringFailed,
        NotificationSubjectType::Participant,
        1,
    );

    // A job that declares nothing gets a null maxTries in its payload and
    // silently takes whatever the worker was started with.
    expect($job->tries())->toBe((int) config('notifications.dispatch.tries'));
    expect($job->backoff())->toBe(config('notifications.dispatch.backoff_seconds'));
    expect($job->timeout())->toBeGreaterThan(0);
});
