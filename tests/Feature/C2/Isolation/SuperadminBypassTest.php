<?php

/**
 * Superadmin bypass isolation tests (C2 — ~95% correctness zone).
 *
 * Invariants tested:
 * (a) null org + is_superadmin=true → bypass=true → ALL org rows visible.
 * (b) null org + is_superadmin=false → 403 (fail-closed, no bypass granted).
 * (c) regular Org A user CANNOT reach the bypass branch — default stays scoped.
 */

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\SampleTenantRecord;

beforeEach(function (): void {
    if (! Schema::hasTable('sample_tenant_records')) {
        Schema::create('sample_tenant_records', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'id']);
        });
    }
});

test('superadmin bypass=true sees rows from all orgs', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    // Seed via DB to bypass the creating listener.
    DB::table('sample_tenant_records')->insert([
        ['title' => 'Org A row', 'organization_id' => $orgA->id, 'created_at' => now(), 'updated_at' => now()],
        ['title' => 'Org B row', 'organization_id' => $orgB->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Simulate superadmin context (what TenantContext sets for is_superadmin=true).
    $resolver = app(TenantResolver::class);
    $resolver->setBypass(true);
    $resolver->setOrgId(null);

    expect(SampleTenantRecord::count())->toBe(2);
});

test('superadmin via HTTP (null org, is_superadmin=true) sets bypass and hits me endpoint', function (): void {
    $superadmin = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    DB::table('sample_tenant_records')->insert([
        ['title' => 'Org A row', 'organization_id' => $orgA->id, 'created_at' => now(), 'updated_at' => now()],
        ['title' => 'Org B row', 'organization_id' => $orgB->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $token = auth('api')->login($superadmin);

    // TenantContext will set bypass=true for the superadmin.
    $this->withToken($token)->getJson('/api/auth/me')->assertOk();

    $resolver = app(TenantResolver::class);
    expect($resolver->isBypass())->toBeTrue();
    expect($resolver->getOrgId())->toBeNull();

    // With bypass=true, global scope is skipped — all rows visible.
    expect(SampleTenantRecord::count())->toBe(2);
});

test('null org with is_superadmin=false returns 403 (no bypass granted)', function (): void {
    $misconfiguredUser = User::factory()->create(['organization_id' => null, 'is_superadmin' => false]);

    $token = auth('api')->login($misconfiguredUser);

    $this->withToken($token)->getJson('/api/auth/me')->assertForbidden();

    // Resolver must NOT have bypass set.
    $resolver = app(TenantResolver::class);
    expect($resolver->isBypass())->toBeFalse();
});

test('regular Org A user cannot trigger bypass branch', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $orgA->id]);

    DB::table('sample_tenant_records')->insert([
        ['title' => 'Org A row', 'organization_id' => $orgA->id, 'created_at' => now(), 'updated_at' => now()],
        ['title' => 'Org B row', 'organization_id' => $orgB->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $token = auth('api')->login($user);
    $this->withToken($token)->getJson('/api/auth/me')->assertOk();

    $resolver = app(TenantResolver::class);
    expect($resolver->isBypass())->toBeFalse();
    expect($resolver->getOrgId())->toBe($orgA->id);

    // Only Org A rows are visible — bypass never triggered.
    expect(SampleTenantRecord::count())->toBe(1);
});

test('superadmin without bypass flag follows scoped default (bypass=false)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    DB::table('sample_tenant_records')->insert([
        ['title' => 'Org A row', 'organization_id' => $orgA->id, 'created_at' => now(), 'updated_at' => now()],
        ['title' => 'Org B row', 'organization_id' => $orgB->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Explicitly NOT setting bypass — default state.
    $resolver = app(TenantResolver::class);
    // bypass defaults to false, orgId defaults to null.
    expect($resolver->isBypass())->toBeFalse();

    // Global scope: WHERE organization_id = null → no rows match (empty result).
    expect(SampleTenantRecord::count())->toBe(0);
});
