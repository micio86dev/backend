<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\PermissionRegistrar;

it('TenantResolver binding exists in container', function (): void {
    expect(app()->bound(TenantResolver::class))->toBeTrue();
});

it('TenantResolver is registered as scoped binding — same instance within lifecycle', function (): void {
    $r1 = app(TenantResolver::class);
    $r2 = app(TenantResolver::class);

    expect($r1)->toBe($r2, 'scoped() returns the same instance within the same lifecycle');
});

it('TenantResolver is NOT a singleton — forgetInstance creates a fresh resolver', function (): void {
    $r1 = app(TenantResolver::class);
    $r1->setOrgId(99);

    app()->forgetInstance(TenantResolver::class);

    $r2 = app(TenantResolver::class);
    expect($r2->getOrgId())->toBeNull('Fresh scoped instance must start with null orgId');
    expect($r2)->not->toBe($r1, 'New instance must differ after forgetInstance');
});

it('Queue::before hook resets TenantResolver and Spatie team context before each job', function (): void {
    // Set a non-default state simulating an HTTP request context
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(5);
    $resolver->setBypass(true);
    app(PermissionRegistrar::class)->setPermissionsTeamId(5);

    // Directly fire the JobProcessing event to trigger the registered Queue::before hook.
    // TenancyServiceProvider registers Queue::before which listens to this event.
    $payload = json_encode(['displayName' => 'TestJob', 'job' => 'TestJob', 'data' => []]);
    $job = new \Illuminate\Queue\Jobs\SyncJob(app(), $payload, 'sync', 'default');
    event(new \Illuminate\Queue\Events\JobProcessing('sync', $job));

    expect($resolver->getOrgId())->toBeNull('Queue::before must reset orgId to null');
    expect($resolver->isBypass())->toBeFalse('Queue::before must reset bypass to false');
    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())
        ->toBeNull('Queue::before must call setPermissionsTeamId(null)');
});
