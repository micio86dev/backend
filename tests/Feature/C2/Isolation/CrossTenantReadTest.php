<?php

/**
 * Cross-tenant READ isolation tests (C2 — ~95% correctness zone).
 *
 * Proves that a user authenticated for Org A sees ONLY Org A rows when
 * querying a TenantScoped model. Org B rows MUST be invisible.
 *
 * Uses SampleTenantRecord (tests/Fixtures/Models) backed by a table created
 * inline via Schema::create (not a migration file, so rollback order is unaffected).
 */

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\SampleTenantRecord;

beforeEach(function (): void {
    // RefreshDatabase runs migrate:fresh before each test; recreate the test-only table.
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

test('Organization users() relation is callable and returns HasMany', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    $usersRelation = $org->users();
    expect($usersRelation)->toBeInstanceOf(HasMany::class);
    expect($org->users->count())->toBe(1);
});

test('authenticated Org A user sees only Org A rows', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $orgA->id]);

    // Use DB::table() to bypass both global scopes AND model events (creating listener).
    $orgAId = DB::table('sample_tenant_records')->insertGetId([
        'title' => 'Org A record',
        'organization_id' => $orgA->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('sample_tenant_records')->insert([
        'title' => 'Org B record',
        'organization_id' => $orgB->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Set resolver to Org A — simulating what TenantContext does after login.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    $results = SampleTenantRecord::all();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($orgAId)
        ->and($results->first()->organization_id)->toBe($orgA->id);
});

test('Org B rows count is zero when resolver is set to Org A', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    // Plant ONLY Org B rows via DB (bypass model events).
    DB::table('sample_tenant_records')->insert([
        'title' => 'Org B only',
        'organization_id' => $orgB->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    // Org A user must see nothing.
    expect(SampleTenantRecord::count())->toBe(0);
});

test('HTTP request for Org A user returns only Org A rows via TenantContext', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $orgA->id]);

    // Seed directly (bypass model events) to plant both orgs' data.
    DB::table('sample_tenant_records')->insert([
        ['title' => 'Org A via HTTP', 'organization_id' => $orgA->id, 'created_at' => now(), 'updated_at' => now()],
        ['title' => 'Org B via HTTP', 'organization_id' => $orgB->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $token = auth('api')->login($user);

    // Hit any auth-protected endpoint so TenantContext sets the resolver.
    $this->withToken($token)->getJson('/api/auth/me')->assertOk();

    // After TenantContext runs, the resolver must be scoped to Org A.
    $resolver = app(TenantResolver::class);
    expect($resolver->getOrgId())->toBe($orgA->id);

    // Verify isolation at the model layer too.
    $results = SampleTenantRecord::all();
    expect($results)->toHaveCount(1)
        ->and($results->first()->organization_id)->toBe($orgA->id);
});
