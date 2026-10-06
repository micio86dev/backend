<?php

declare(strict_types=1);

/**
 * `AuditRecorder` names only a human User as the actor of an audit row.
 *
 * `audit_logs.actor_id` is a foreign key to `users`. On the `auth:api-m2m`
 * surface the default guard resolves the authenticated ApiClient, whose id is
 * unrelated to any user: writing it either violates the foreign key (the row
 * is lost, swallowed by the recorder) or names an innocent user whose id
 * happens to match. The M2M actor travels in the payload instead.
 */

use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($this->org->id);
    $resolver->setBypass(false);
});

test('an authenticated user is recorded as the actor', function (): void {
    $user = User::factory()->create(['organization_id' => $this->org->id]);
    Auth::shouldUse('api');
    Auth::guard('api')->setUser($user);

    app(AuditRecorder::class)->record('test.action', 'thing', 1);

    expect(AuditLog::withoutGlobalScopes()->where('action', 'test.action')->sole()->actor_id)->toBe($user->id);
});

test('an authenticated API client is never recorded as the actor, and the row is still written', function (): void {
    // A user whose id equals the client's must not be named either.
    $client = ApiClient::factory()->create(['organization_id' => $this->org->id]);
    User::factory()->create(['id' => $client->id + 1000, 'organization_id' => $this->org->id]);
    Auth::shouldUse('api-m2m');
    Auth::guard('api-m2m')->setUser($client);

    app(AuditRecorder::class)->record('test.action', 'thing', 1);

    expect(AuditLog::withoutGlobalScopes()->where('action', 'test.action')->sole()->actor_id)->toBeNull();
});

test('with nobody authenticated the actor is null', function (): void {
    app(AuditRecorder::class)->record('test.action', 'thing', 1);

    expect(AuditLog::withoutGlobalScopes()->where('action', 'test.action')->sole()->actor_id)->toBeNull();
});
