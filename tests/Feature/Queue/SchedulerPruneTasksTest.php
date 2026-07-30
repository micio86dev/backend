<?php

declare(strict_types=1);

/**
 * `queue:prune-failed` / `queue:prune-batches` scheduling (queue-worker-scheduler
 * PR3, design.md D6, tasks.md 5.2 — folded into this batch after the
 * orchestrator corrected the earlier "runner only" scope: queue-table
 * pruning is queue hygiene, not a domain concern, and belongs in this
 * change).
 *
 * Both tasks MUST run ->onOneServer() (queue-runtime/spec.md Requirement 7
 * — structurally guarded by tests/Arch/Queue/SchedulerOnOneServerArchTest.php,
 * which now has a REAL task to protect instead of only synthetic snippets)
 * and MUST read their retention window from config('queue.maintenance.*'),
 * never a literal — so a future change can shorten retention without a
 * code change.
 */

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

/**
 * ->withSchedule()'s registration callback only attaches once the console
 * kernel has "started" (Artisan::starting() fires on the first command
 * bootstrap). Running a harmless real command first ensures the callback
 * has run before we inspect the (singleton) Schedule instance.
 */
function qwsBootScheduledSchedule(): Schedule
{
    Artisan::call('schedule:list');

    return app(Schedule::class);
}

test('queue:prune-failed is scheduled daily, onOneServer, with the configured retention window', function (): void {
    config()->set('queue.maintenance.failed_jobs_retention_hours', 168);

    $schedule = qwsBootScheduledSchedule();
    $event = Collection::make($schedule->events())
        ->first(fn ($e) => str_contains($e->command, 'queue:prune-failed'));

    expect($event)->not->toBeNull();
    expect($event->command)->toContain('--hours=168');
    expect($event->onOneServer)->toBeTrue();
    expect($event->expression)->toBe('10 3 * * *'); // dailyAt('03:10')
});

test('queue:prune-batches is scheduled daily, onOneServer, with the configured retention window', function (): void {
    config()->set('queue.maintenance.batches_retention_hours', 168);

    $schedule = qwsBootScheduledSchedule();
    $event = Collection::make($schedule->events())
        ->first(fn ($e) => str_contains($e->command, 'queue:prune-batches'));

    expect($event)->not->toBeNull();
    expect($event->command)->toContain('--hours=168');
    expect($event->onOneServer)->toBeTrue();
    expect($event->expression)->toBe('20 3 * * *'); // dailyAt('03:20')
});

test('prune retention windows are config-driven, not hardcoded', function (): void {
    config()->set('queue.maintenance.failed_jobs_retention_hours', 42);
    config()->set('queue.maintenance.batches_retention_hours', 99);

    $schedule = qwsBootScheduledSchedule();
    $events = Collection::make($schedule->events());

    $pruneFailed = $events->first(fn ($e) => str_contains($e->command, 'queue:prune-failed'));
    $pruneBatches = $events->first(fn ($e) => str_contains($e->command, 'queue:prune-batches'));

    expect($pruneFailed->command)->toContain('--hours=42');
    expect($pruneBatches->command)->toContain('--hours=99');
});
