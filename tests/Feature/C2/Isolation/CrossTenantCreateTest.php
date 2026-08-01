<?php

/**
 * Cross-tenant CREATE isolation tests — tamper-proof stamp (C2).
 *
 * Proves that the TenantScoped `creating` listener UNCONDITIONALLY overrides
 * any client-supplied organization_id with the resolver value.
 * A caller in Org A cannot stamp a record with Org B's id — ever.
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

test('create auto-stamps organization_id from resolver (no org supplied)', function (): void {
    $orgA = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    $record = SampleTenantRecord::create(['title' => 'auto-stamped']);

    expect($record->organization_id)->toBe($orgA->id);

    // Confirm via DB read (not model cache).
    $persisted = DB::table('sample_tenant_records')->find($record->id);
    expect($persisted->organization_id)->toBe($orgA->id);
});

test('explicit foreign organization_id is silently overridden by resolver (tamper-proof)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    // Attempt to stamp with Org B's id — should be overridden to Org A.
    $record = SampleTenantRecord::create([
        'title' => 'tamper attempt',
        'organization_id' => $orgB->id,
    ]);

    // No error raised — the override is silent.
    // But the persisted record MUST have Org A's id.
    $persisted = DB::table('sample_tenant_records')->find($record->id);
    expect($persisted->organization_id)->toBe($orgA->id)
        ->and($persisted->organization_id)->not->toBe($orgB->id);
});

test('HTTP create request with explicit foreign org_id results in correct org stamp', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $orgA->id]);

    $token = auth('api')->login($user);

    // Hit the test isolation create route with a payload containing Org B's id.
    $response = $this->withToken($token)
        ->postJson('/api/test-isolation/sample-tenant-records', [
            'title' => 'crafted payload',
            'organization_id' => $orgB->id,
        ]);

    $response->assertCreated();

    $id = $response->json('id');
    $persisted = DB::table('sample_tenant_records')->find($id);

    // Must be stamped with Org A, NOT Org B.
    expect($persisted->organization_id)->toBe($orgA->id)
        ->and($persisted->organization_id)->not->toBe($orgB->id);
});
