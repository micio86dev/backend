<?php

declare(strict_types=1);

/**
 * RED — P4.3-P4.12/P4.15-P4.17: `POST /api/participants/{id}/evaluation/audit`,
 * the ordered 8-step flow (scoring-audit-jev, design D12, spec "The Audit
 * Trigger Is Admin-Only, Throttled, and Refuses an In-Flight Duplicate").
 */

use App\Jobs\AuditEvaluationJob;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{project: Project, participant: Participant, evaluation: Evaluation}
 */
function evaluationAuditFixture(Organization $org, string $participantStatus = 'completato'): array
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus($participantStatus)->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    return ['project' => $project, 'participant' => $participant, 'evaluation' => $evaluation];
}

// ─── Step 1: kill switch — checked BEFORE authorization (P4.3/P4.4) ───────────

test('the kill switch refuses with 409 audit_disabled — checked before authorization', function (): void {
    config(['scoring.audit.enabled' => false]);

    $org = Organization::factory()->create();
    $fixture = evaluationAuditFixture($org);
    // A viewer would ALSO fail authorization — the kill switch must win first.
    $token = authTokenForRole($org, 'viewer');

    $this->withToken($token)
        ->postJson("/api/participants/{$fixture['participant']->id}/evaluation/audit")
        ->assertStatus(409)
        ->assertJson(['reason' => 'audit_disabled']);
});

// ─── Step 2: RBAC — model-less, 403 before 404 (P4.5/P4.6) ────────────────────

test('operator is refused with 403', function (): void {
    $org = Organization::factory()->create();
    $fixture = evaluationAuditFixture($org);
    $token = authTokenForRole($org, 'operator');

    $this->withToken($token)
        ->postJson("/api/participants/{$fixture['participant']->id}/evaluation/audit")
        ->assertStatus(403);
});

test('viewer is refused with 403', function (): void {
    $org = Organization::factory()->create();
    $fixture = evaluationAuditFixture($org);
    $token = authTokenForRole($org, 'viewer');

    $this->withToken($token)
        ->postJson("/api/participants/{$fixture['participant']->id}/evaluation/audit")
        ->assertStatus(403);
});

test('a non-admin never learns whether an unknown participant id exists — 403, not 404, since RBAC runs before resolution', function (): void {
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    $token = authTokenForRole($org, 'viewer');

    $this->withToken($token)
        ->postJson('/api/participants/999999/evaluation/audit')
        ->assertStatus(403);
});

// ─── Step 3: cross-tenant / lifecycle gate (P4.7/P4.8) ────────────────────────

test('a cross-tenant participant id returns 404, never 403', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $fixtureB = evaluationAuditFixture($orgB);
    $token = authTokenForRole($orgA, 'admin');

    $this->withToken($token)
        ->postJson("/api/participants/{$fixtureB['participant']->id}/evaluation/audit")
        ->assertStatus(404);
});

test('an unknown participant id returns 404', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');

    $this->withToken($token)
        ->postJson('/api/participants/999999/evaluation/audit')
        ->assertStatus(404);
});

test('a non-completato participant returns 409 lifecycle_not_ready', function (): void {
    $org = Organization::factory()->create();
    $fixture = evaluationAuditFixture($org, 'in_corso');
    $token = authTokenForRole($org, 'admin');

    $this->withToken($token)
        ->postJson("/api/participants/{$fixture['participant']->id}/evaluation/audit")
        ->assertStatus(409)
        ->assertJson(['error' => 'lifecycle_not_ready']);
});

// ─── Step 4: an existing Evaluation row is required (P4.9/P4.10) ──────────────

test('a completato participant with no persisted Evaluation row is refused, no job dispatched', function (): void {
    Queue::fake();

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    // Deliberately NO Evaluation row for this participant.

    $token = authTokenForRole($org, 'admin');

    $this->withToken($token)
        ->postJson("/api/participants/{$participant->id}/evaluation/audit")
        ->assertStatus(404);

    Queue::assertNotPushed(AuditEvaluationJob::class);
});

// ─── Step 5: in-flight lock (P4.11/P4.12) ─────────────────────────────────────

test('a second request while the lock is held returns 409 audit_already_running, no second job dispatched', function (): void {
    Queue::fake();

    $org = Organization::factory()->create();
    $fixture = evaluationAuditFixture($org);
    $token = authTokenForRole($org, 'admin');

    // Held, never released in this test — simulates an in-flight run.
    Cache::lock("audit:evaluation:{$fixture['evaluation']->id}", 60)->get();

    $this->withToken($token)
        ->postJson("/api/participants/{$fixture['participant']->id}/evaluation/audit")
        ->assertStatus(409)
        ->assertJson(['reason' => 'audit_already_running']);

    Queue::assertNotPushed(AuditEvaluationJob::class);
});

test('a Redis throw on lock acquisition fails CLOSED with 409 audit_lock_unavailable', function (): void {
    Queue::fake();

    $org = Organization::factory()->create();
    $fixture = evaluationAuditFixture($org);
    $token = authTokenForRole($org, 'admin');

    // Simulates a Redis-unavailable outage: only lock() throws, the rest of
    // the store behaves like a normal array cache for everything else the
    // SAME request touches (e.g. Spatie's permission cache). Registered as a
    // real driver rather than a Cache::partialMock() facade mock —
    // partialMock() creates a Mockery instance whose constructor never runs,
    // so its internal $app property stays null and ANY unstubbed Cache call
    // elsewhere in the request fatals on that null property instead of
    // exercising a representative failure mode.
    Cache::extend('throwing-lock', fn () => Cache::repository(new class extends ArrayStore
    {
        public function lock($name, $seconds = 0, $owner = null)
        {
            throw new RuntimeException('Redis connection refused.');
        }
    }));
    config(['cache.stores.throwing-lock' => ['driver' => 'throwing-lock']]);
    config(['cache.default' => 'throwing-lock']);

    // Design D7: fails CLOSED, the opposite direction from the M2M guard's
    // fail-open — failing open here risks paying a vendor twice for one run.
    $this->withToken($token)
        ->postJson("/api/participants/{$fixture['participant']->id}/evaluation/audit")
        ->assertStatus(409)
        ->assertJson(['reason' => 'audit_lock_unavailable']);

    Queue::assertNotPushed(AuditEvaluationJob::class);
});

test('a throw from the acquired Lock itself (not just Cache::lock()) also fails CLOSED with 409 audit_lock_unavailable', function (): void {
    // The more realistic Redis-outage shape: `RedisStore::lock()` resolves a
    // connection lazily and returns a Lock object successfully, but the
    // actual command (SETNX) inside that Lock's get()/acquire() is what
    // fails when the connection drops. Both throw points must be caught —
    // this test proves the SECOND one specifically, distinct from the
    // previous test's `Cache::lock()`-itself-throws shape.
    Queue::fake();

    $org = Organization::factory()->create();
    $fixture = evaluationAuditFixture($org);
    $token = authTokenForRole($org, 'admin');

    Cache::extend('throwing-lock-get', fn () => Cache::repository(new class extends ArrayStore
    {
        public function lock($name, $seconds = 0, $owner = null)
        {
            return new class($name, $seconds, $owner) extends Lock
            {
                public function acquire()
                {
                    throw new RuntimeException('Redis connection refused.');
                }

                public function release()
                {
                    return true;
                }

                public function forceRelease()
                {
                    //
                }

                protected function getCurrentOwner()
                {
                    return null;
                }
            };
        }
    }));
    config(['cache.stores.throwing-lock-get' => ['driver' => 'throwing-lock-get']]);
    config(['cache.default' => 'throwing-lock-get']);

    $this->withToken($token)
        ->postJson("/api/participants/{$fixture['participant']->id}/evaluation/audit")
        ->assertStatus(409)
        ->assertJson(['reason' => 'audit_lock_unavailable']);

    Queue::assertNotPushed(AuditEvaluationJob::class);
});

// ─── Steps 7/8: success (P4.15/P4.16/P4.17) ───────────────────────────────────

test('an accepted request returns 202 and dispatches exactly one AuditEvaluationJob for this evaluation', function (): void {
    Queue::fake();

    $org = Organization::factory()->create();
    $fixture = evaluationAuditFixture($org);
    $token = authTokenForRole($org, 'admin');

    $this->withToken($token)
        ->postJson("/api/participants/{$fixture['participant']->id}/evaluation/audit")
        ->assertStatus(202)
        ->assertJson(['status' => 'queued', 'evaluation_id' => $fixture['evaluation']->id]);

    Queue::assertPushed(AuditEvaluationJob::class, 1);
});

test('the route is registered with throttle:6,1', function (): void {
    $route = collect(app('router')->getRoutes())->first(
        fn ($r) => $r->uri() === 'api/participants/{id}/evaluation/audit' && in_array('POST', $r->methods(), true)
    );

    expect($route)->not->toBeNull()
        ->and($route->middleware())->toContain('throttle:6,1');
});
