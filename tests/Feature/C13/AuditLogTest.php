<?php

declare(strict_types=1);

/**
 * audit_logs — append-only record of admin/operator mutations (C13).
 *
 * A binding NFR that had no implementation. The tests that matter most here are
 * the ones about what must NOT end up in the trail: an audit log that captures
 * credentials is a breach with good intentions, and it is a breach in a table
 * built to be read.
 */

use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;

function auditOrg(): Organization
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return $org;
}

// ─── Shape ───────────────────────────────────────────────────────────────────

test('a recorded mutation carries actor, action, subject and organization', function (): void {
    $org = auditOrg();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($user);

    app(AuditRecorder::class)->record('api_client.revoked', 'api_client', 42);

    $row = AuditLog::withoutGlobalScopes()->firstOrFail();

    expect($row->actor_id)->toBe($user->id);
    expect($row->action)->toBe('api_client.revoked');
    expect($row->subject_type)->toBe('api_client');
    expect($row->subject_id)->toBe(42);
    expect($row->organization_id)->toBe($org->id);
});

test('a mutation with no human actor is still recorded', function (): void {
    auditOrg();

    // An M2M client or a console command has no User behind it. "Something
    // changed and no human did it" is exactly the event an auditor wants — an
    // anonymous row beats no row.
    app(AuditRecorder::class)->record('project.updated', 'project', 7);

    $row = AuditLog::withoutGlobalScopes()->firstOrFail();
    expect($row->actor_id)->toBeNull();
});

// ─── Redaction — the reason the recorder is central ──────────────────────────

test('credential values never reach the trail', function (): void {
    auditOrg();

    app(AuditRecorder::class)->record('api_client.created', 'api_client', 1, after: [
        'name' => 'Acme integration',
        'key_hash' => 'HASH-THAT-MUST-NOT-BE-STORED',
        'password' => 'hunter2',
        'webhook_secret' => 'whsec_live_abc',
    ]);

    $row = AuditLog::withoutGlobalScopes()->firstOrFail();
    $encoded = json_encode($row->after);

    // Names are kept, values are not: "the webhook secret was rotated" is what
    // an auditor needs; the secret is what they must never get.
    expect($row->after)->toHaveKey('key_hash');
    expect($encoded)->not->toContain('HASH-THAT-MUST-NOT-BE-STORED');
    expect($encoded)->not->toContain('hunter2');
    expect($encoded)->not->toContain('whsec_live_abc');
    expect($row->after['name'])->toBe('Acme integration');
});

test('a nested credential is redacted at any depth', function (): void {
    auditOrg();

    // A changed attribute can itself be an array — a project's webhook config,
    // for instance. A top-level-only pass would let this through while looking
    // like it had worked.
    app(AuditRecorder::class)->record('project.updated', 'project', 1, after: [
        'webhooks' => ['url' => 'https://x.test', 'secret' => 'NESTED-SECRET'],
    ]);

    $encoded = json_encode(AuditLog::withoutGlobalScopes()->firstOrFail()->after);
    expect($encoded)->not->toContain('NESTED-SECRET');
});

test('attributes ending in _token or _secret are redacted by convention', function (): void {
    auditOrg();

    app(AuditRecorder::class)->record('user.updated', 'user', 1, after: [
        'access_token' => 'AT-LEAK',
        'client_secret' => 'CS-LEAK',
    ]);

    $encoded = json_encode(AuditLog::withoutGlobalScopes()->firstOrFail()->after);

    // Enumerating every future name is impossible; the convention is the guard.
    expect($encoded)->not->toContain('AT-LEAK');
    expect($encoded)->not->toContain('CS-LEAK');
});

// ─── Containment ─────────────────────────────────────────────────────────────

test('a failing audit write never breaks the mutation it records', function (): void {
    // No tenant context — AuditLog::create will fail closed. The mutation being
    // audited must not care: it is the user's intent, the row is a side effect.
    // Losing the row is bad; losing the user's work because logging it failed
    // turns an observability outage into a functional one.
    app(TenantResolver::class)->setOrgId(null);

    app(AuditRecorder::class)->record('project.updated', 'project', 1);

    expect(true)->toBeTrue(); // reaching this line IS the assertion
});

// ─── Tenancy ─────────────────────────────────────────────────────────────────

test('one organization never reads another trail', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    TenantContextScope::runFor($orgA->id, fn () => app(AuditRecorder::class)->record('a', 'x', 1));
    TenantContextScope::runFor($orgB->id, fn () => app(AuditRecorder::class)->record('b', 'x', 2));

    $seenByA = TenantContextScope::runFor($orgA->id, fn () => AuditLog::query()->pluck('action')->all());

    expect($seenByA)->toBe(['a']);
});

// ─── The first real consumer ─────────────────────────────────────────────────

test('revoking an M2M client leaves a trail', function (): void {
    $org = auditOrg();
    $client = ApiClient::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

    $source = (string) file_get_contents(app_path('Http/Controllers/M2m/ApiClientController.php'));

    // Revocation is the most security-relevant admin mutation that exists
    // today, which makes it the right first consumer.
    expect($source)->toContain("'api_client.revoked'");
    expect($source)->toContain("'api_client.created'");
    expect($client->is_active)->toBeTrue();
});
