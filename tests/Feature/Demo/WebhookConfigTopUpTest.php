<?php

declare(strict_types=1);

/**
 * `applyWebhookConfigTopUp()` (design D13) is the exact mechanism a
 * production top-up against an OLDER, already-shipped dataset depends on:
 * `writeProjects()` skips any project that already exists, so a demo
 * project created before this change would otherwise stay forever
 * unconfigured while its `webhook_deliveries` rows claim otherwise.
 *
 * `CensusGateAdditiveTest`'s "older dataset" simulation only deletes rows in
 * the four NEW tables — every demo project it re-seeds against was created
 * in the SAME test run, so `webhook_url` was already filled in at creation
 * time and the fill-when-null branch of `applyWebhookConfigTopUp()` never
 * actually runs there. This file exercises that branch directly by nulling
 * out `webhook_url` on an already-seeded project (simulating the real
 * "created before this change" case) and proves both halves of the
 * contract: the fill happens, and an operator's own configuration is never
 * overwritten.
 */

use App\Models\Organization;
use App\Models\Project;
use App\Support\Demo\DemoMarker;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

test('a demo project whose webhook_url predates this change (NULL) is filled in on re-seed', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    // Simulate a project created by an OLDER version of beai:demo-seed,
    // before webhook configuration existed — the fill-when-null branch's
    // real production trigger.
    TenantContextScope::runFor($this->org->id, function (): void {
        $p1 = Project::where('slug', DemoMarker::PREFIX.'sales-ico')->firstOrFail();
        $p1->webhook_url = null;
        $p1->webhook_secret = null;
        $p1->save();
    });

    TenantContextScope::runFor($this->org->id, function (): void {
        $p1 = Project::where('slug', DemoMarker::PREFIX.'sales-ico')->firstOrFail();
        expect($p1->webhook_url)->toBeNull();
    });

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    TenantContextScope::runFor($this->org->id, function (): void {
        $p1 = Project::where('slug', DemoMarker::PREFIX.'sales-ico')->firstOrFail();
        expect($p1->webhook_url)->toBe('https://webhooks.invalid/beai-demo/sales-ico');
        expect($p1->webhook_secret)->not->toBeNull();
        expect($p1->webhook_events)->toBe(['progress', 'evaluation']);
    });
});

test('a demo project already configured by an operator with a real webhook is NEVER overwritten on re-seed', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    TenantContextScope::runFor($this->org->id, function (): void {
        $p1 = Project::where('slug', DemoMarker::PREFIX.'sales-ico')->firstOrFail();
        $p1->webhook_url = 'https://acme-real-integration.example.com/hooks';
        $p1->webhook_secret = 'an-operator-set-secret-never-touch-this';
        $p1->webhook_events = ['evaluation'];
        $p1->save();
    });

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    TenantContextScope::runFor($this->org->id, function (): void {
        $p1 = Project::where('slug', DemoMarker::PREFIX.'sales-ico')->firstOrFail();
        expect($p1->webhook_url)->toBe('https://acme-real-integration.example.com/hooks');
        expect($p1->webhook_secret)->toBe('an-operator-set-secret-never-touch-this');
        expect($p1->webhook_events)->toBe(['evaluation']);
    });
});
