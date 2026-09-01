<?php

declare(strict_types=1);

/**
 * RED — 12.1: SSO exchange progress seam (C10, design.md D5, Δ — participant-sso delta).
 *
 * `SsoExchangeController::exchange()` uses a RAW autocommit `DB::statement()` upsert
 * (`:137-158`) deliberately for TOCTOU-safe atomicity — there is NO `DB::transaction`
 * in this file. That atomicity is NOT weakened here: this seam's "must not fire on a
 * rolled-back write" analog is scenario 4 below (a pre-flight gate failure means the
 * upsert statement never even runs, so trivially nothing is observable) — NOT a
 * commit/rollback pair like `/end`'s explicit transaction, because there is no
 * transaction to roll back. "Created" is inferred from the PRE-FLIGHT read at
 * `:119-121` (`$existingStatus === null`), captured BEFORE the upsert runs.
 *
 * Scenario 3 (the TOCTOU race) cannot be reproduced with real OS-level concurrency in
 * a single-threaded test (same limitation documented at
 * `tests/Feature/C6/ConcurrentUpsertTest.php:12-17`) — it is exercised by firing the
 * event twice with the identical dedupe key, which is exactly what two racing
 * requests that both observed `existingStatus === null` would do; the dedupe
 * mechanism that collapses this was already proven independently in PR4
 * (`WebhookDeliveryDedupeTest.php`).
 */

use App\Events\ParticipantCreated;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Queue::fake() is mandatory here, not cosmetic: under QUEUE_CONNECTION=sync with no
// active DB transaction (this seam has none — it's a raw autocommit statement),
// DeliverWebhookJob::dispatch(...)->afterCommit() executes IMMEDIATELY and
// synchronously inside the listener, making a real outbound HTTP call. Without
// Http::fake() too, that call would hit the network for real. This bug was found the
// hard way: omitting Queue::fake() here caused every downstream assertion to fail
// with the ambient TenantResolver mysteriously reset to null after event() returned
// — traced to the real (failing, DNS-erroring) HTTP attempt running inside the same
// call stack before the test's own assertions ran.
beforeEach(function (): void {
    Queue::fake();
    Http::fake();
});

/**
 * @param  array<string, mixed>  $attrs
 */
function c10SsoWebhookProject(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(array_merge([
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'goes_live_at' => null,
        'deadline_at' => null,
        'webhook_url' => 'https://receiver.example.test/hook',
        'webhook_secret' => 'whsec_sso_seam_test_secret',
        'webhook_events' => ['progress', 'evaluation'],
    ], $attrs));
}

function c10SsoLink(Project $project, Organization $org, string $ref, string $display = 'Test Candidate'): string
{
    return CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => $ref,
        'display_name' => $display,
        'email' => uniqid('cand-').'@example.test',
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => $project->role_code,
        'lang' => 'en',
    ]);
}

test('first exchange for a new candidate produces exactly one progress delivery row', function (): void {
    $org = Organization::factory()->create();
    $project = c10SsoWebhookProject($org);
    $token = c10SsoLink($project, $org, 'cand-new-progress');

    $this->getJson('/api/sso/exchange?token='.$token)->assertOk();

    $participant = Participant::where('project_id', $project->id)->where('candidate_ref', 'cand-new-progress')->first();
    expect($participant)->not->toBeNull();

    expect(WebhookDelivery::where('participant_id', $participant->id)->count())->toBe(1);
});

test('idempotent re-exchange (status still in_attesa) does not produce a second progress delivery row', function (): void {
    $org = Organization::factory()->create();
    $project = c10SsoWebhookProject($org);
    $ref = 'cand-idempotent-progress';

    $token1 = c10SsoLink($project, $org, $ref, 'First');
    $this->getJson('/api/sso/exchange?token='.$token1)->assertOk();

    $participant = Participant::where('project_id', $project->id)->where('candidate_ref', $ref)->first();
    expect(WebhookDelivery::where('participant_id', $participant->id)->count())->toBe(1);

    // Second exchange — participant still in_attesa, this is an UPDATE not an INSERT.
    $token2 = c10SsoLink($project, $org, $ref, 'Second');
    $this->getJson('/api/sso/exchange?token='.$token2)->assertOk();

    // Exactly the same one row — no second delivery created.
    expect(WebhookDelivery::where('participant_id', $participant->id)->count())->toBe(1);
});

test('concurrent-creation race collapses into one webhook_deliveries row via dedupe', function (): void {
    $org = Organization::factory()->create();
    $project = c10SsoWebhookProject($org);
    $participant = Participant::factory()->forProject($project)->create();

    // Simulates two concurrent exchanges that BOTH observed existingStatus === null
    // before either committed (see class doc — not reproducible with real
    // concurrency in a single-threaded test).
    event(new ParticipantCreated($participant->id, $project->id));
    event(new ParticipantCreated($participant->id, $project->id));

    expect(WebhookDelivery::where('participant_id', $participant->id)->count())->toBe(1);
});

test('a pre-flight-gate failure (401 invalid token, before the reload+null-check) produces zero delivery rows', function (): void {
    $org = Organization::factory()->create();
    c10SsoWebhookProject($org);

    $this->getJson('/api/sso/exchange?token=not-a-real-jwt')->assertUnauthorized();

    expect(WebhookDelivery::count())->toBe(0);
});

test('a pre-flight-gate failure (403 project not active, before the reload+null-check) produces zero delivery rows', function (): void {
    $org = Organization::factory()->create();
    $project = c10SsoWebhookProject($org, ['status' => 'draft']);
    $token = c10SsoLink($project, $org, 'cand-gate-403');

    $this->getJson('/api/sso/exchange?token='.$token)->assertForbidden();

    expect(WebhookDelivery::count())->toBe(0);
});
