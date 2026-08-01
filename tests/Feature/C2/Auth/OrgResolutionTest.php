<?php

/**
 * Org resolution — DB truth over JWT claim (C2).
 *
 * Verifies that TenantContext resolves organization_id exclusively from
 * the DB record, NOT the JWT claim. If a user's org changes in the DB,
 * the next request uses the new org even with the stale JWT.
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

test('stale JWT claim does not affect scoping — DB truth is authoritative', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    // User starts in Org A — token is issued with Org A in the JWT claim.
    $user = User::factory()->create(['organization_id' => $orgA->id]);
    $token = auth('api')->login($user);

    // Seed data via DB (bypass model events).
    DB::table('sample_tenant_records')->insert([
        ['title' => 'Org A record', 'organization_id' => $orgA->id, 'created_at' => now(), 'updated_at' => now()],
        ['title' => 'Org B record', 'organization_id' => $orgB->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Admin changes user's org in DB to Org B (token still carries Org A claim).
    $user->organization_id = $orgB->id;
    $user->save();

    // Make request with stale token (JWT says Org A, DB says Org B).
    $this->withToken($token)->getJson('/api/auth/me')->assertOk();

    $resolver = app(TenantResolver::class);

    // TenantContext must have used DB value (Org B), not JWT claim (Org A).
    expect($resolver->getOrgId())->toBe($orgB->id);
    expect($resolver->isBypass())->toBeFalse();

    // Org B scope is now active — Org B record visible, Org A record invisible.
    expect(SampleTenantRecord::count())->toBe(1);
    expect(SampleTenantRecord::first()->organization_id)->toBe($orgB->id);
});

test('JWT with Org A claim — DB has Org A — scopes correctly to Org A', function (): void {
    $orgA = Organization::factory()->create();

    // User belongs to Org A in DB.
    $user = User::factory()->create(['organization_id' => $orgA->id]);
    $token = auth('api')->login($user);

    $this->withToken($token)->getJson('/api/auth/me')->assertOk();

    $resolver = app(TenantResolver::class);
    // DB has Org A → resolver must be Org A.
    expect($resolver->getOrgId())->toBe($orgA->id);
});
