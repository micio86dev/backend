<?php

declare(strict_types=1);

namespace Tests\Fixtures\NotificationArchFixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Deliberately NON-COMPLIANT fixture for NotificationNeverQueuedArchTest.
 *
 * It exists so the guard's DETECTION is proven, not merely its verdict. An arch
 * test that only ever runs against a clean tree passes whether or not it
 * actually works — and this one would have passed vacuously before
 * app/Notifications/ existed at all.
 *
 * It also carries the string 'TenantContextScope::' on purpose, because that is
 * precisely the loophole this guard closes: the existing
 * QueuedJobTenantContextArchTest checks compliance with a string search, and a
 * queued Notification mentioning that string would satisfy it while Laravel
 * wrapped the class in SendQueuedNotifications, executing after Queue::before
 * had reset the resolver to null.
 *
 * NEVER move this under app/.
 */
class QueuedNotificationFixture extends Notification implements ShouldQueue
{
    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }
}
