<?php

declare(strict_types=1);

/**
 * Architecture guard: NO class under app/Notifications/ may implement
 * ShouldQueue (C12, D6 / tenancy delta spec).
 *
 * There is NO allowlist here, deliberately, and this guard is not redundant
 * with QueuedJobTenantContextArchTest — the reason is subtler than "that one
 * only scans app/Jobs".
 *
 * It does scan app/Notifications: its discovery is a RecursiveDirectoryIterator
 * over app_path(). The real gap is its COMPLIANCE CHECK, which is a string
 * search for 'TenantContextScope::'. A Notification that implements ShouldQueue
 * and merely MENTIONS that string in a comment would satisfy that scan — while
 * Laravel wraps it in Illuminate\Notifications\SendQueuedNotifications, a
 * framework class no scan over app/ can ever inspect, executing after
 * Queue::before has already reset the tenant resolver to null.
 *
 * So the rule here is keyed on the SHAPE, not on the string: a queued
 * Notification is wrong regardless of what its source happens to contain. The
 * queue boundary belongs in SendOperatorNotificationJob, which opens exactly
 * one TenantContextScope::runFor() and then calls Notification::sendNow().
 */

use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\Fixtures\NotificationArchFixtures\QueuedNotificationFixture;

/**
 * @return list<class-string> classes under $dir that implement ShouldQueue
 */
function queuedNotificationViolations(string $dir, string $namespacePrefix, string $pathRoot): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $violations = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace([$pathRoot.DIRECTORY_SEPARATOR, '.php'], ['', ''], $file->getPathname());
        $class = $namespacePrefix.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || $reflection->isInterface()) {
            continue;
        }

        if ($reflection->implementsInterface(ShouldQueue::class)) {
            $violations[] = $class;
        }
    }

    return $violations;
}

test('no Notification class implements ShouldQueue', function (): void {
    $violations = queuedNotificationViolations(app_path('Notifications'), 'App\\', app_path());

    expect($violations)->toBe([], sprintf(
        "These Notification classes implement ShouldQueue and must not:\n  - %s\n".
        'Queue them via SendOperatorNotificationJob and send with Notification::sendNow().',
        implode("\n  - ", $violations)
    ));
});

test('the guard actually detects a queued notification', function (): void {
    // Proves DETECTION, not just the verdict. Without this the test above
    // passes whether or not the scanner works — and it would have passed
    // vacuously before app/Notifications/ existed at all.
    //
    // The fixture also contains the literal 'TenantContextScope::', which is
    // exactly what makes it invisible to the existing string-search guard. If
    // this assertion ever fails, the loophole is open again.
    $violations = queuedNotificationViolations(
        base_path('tests/Fixtures/NotificationArchFixtures'),
        'Tests\\Fixtures\\NotificationArchFixtures\\',
        base_path('tests/Fixtures/NotificationArchFixtures')
    );

    expect($violations)->toContain(
        QueuedNotificationFixture::class
    );
});
