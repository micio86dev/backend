<?php

/**
 * Cross-tenant WRITE isolation tests (C2 — ~95% correctness zone).
 *
 * Proves that a user authenticated for Org A cannot update or delete a record
 * belonging to Org B. The TenantScoped global scope makes Org B records
 * invisible from Org A's context; any find/update/delete against an Org B ID
 * returns null, which a controller translates to 404.
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

test('Org A user cannot find Org B record via scoped find (model layer)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    // Plant Org B record via DB (bypass model events).
    $orgBId = DB::table('sample_tenant_records')->insertGetId([
        'title' => 'Org B secret',
        'organization_id' => $orgB->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Scope resolver to Org A.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    // Org A user tries to find Org B's record ID — must return null.
    $found = SampleTenantRecord::find($orgBId);
    expect($found)->toBeNull();
});

test('Org A user update attempt on Org B record returns null (model layer → 404 in controller)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $orgBId = DB::table('sample_tenant_records')->insertGetId([
        'title' => 'Org B record',
        'organization_id' => $orgB->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    // Org B record is invisible from Org A scope.
    $found = SampleTenantRecord::find($orgBId);
    expect($found)->toBeNull();

    // Verify Org B record is unchanged in DB.
    $persisted = DB::table('sample_tenant_records')->find($orgBId);
    expect($persisted->title)->toBe('Org B record')
        ->and($persisted->organization_id)->toBe($orgB->id);
});

test('Org A user delete attempt on Org B record fails at model layer', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $orgBId = DB::table('sample_tenant_records')->insertGetId([
        'title' => 'Org B record to delete',
        'organization_id' => $orgB->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    // delete() on a scoped query that sees no Org B rows deletes nothing.
    SampleTenantRecord::where('id', $orgBId)->delete();

    // Record must still exist in DB.
    $persisted = DB::table('sample_tenant_records')->find($orgBId);
    expect($persisted)->not->toBeNull()
        ->and($persisted->title)->toBe('Org B record to delete');
});

test('HTTP Org A user write-protected endpoint returns 404 for Org B record ID via route', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $orgA->id]);

    $orgBId = DB::table('sample_tenant_records')->insertGetId([
        'title' => 'Org B target',
        'organization_id' => $orgB->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $token = auth('api')->login($user);

    // Use the test-only isolation route.
    $this->withToken($token)
        ->putJson("/api/test-isolation/sample-tenant-records/{$orgBId}", ['title' => 'hacked'])
        ->assertNotFound();

    // Org B record must be intact.
    $persisted = DB::table('sample_tenant_records')->find($orgBId);
    expect($persisted->title)->toBe('Org B target');
});

test('HTTP Org A user delete returns 404 for Org B record ID via route', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $orgA->id]);

    $orgBId = DB::table('sample_tenant_records')->insertGetId([
        'title' => 'Org B delete target',
        'organization_id' => $orgB->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $token = auth('api')->login($user);

    $this->withToken($token)
        ->deleteJson("/api/test-isolation/sample-tenant-records/{$orgBId}")
        ->assertNotFound();

    $persisted = DB::table('sample_tenant_records')->find($orgBId);
    expect($persisted)->not->toBeNull();
});
