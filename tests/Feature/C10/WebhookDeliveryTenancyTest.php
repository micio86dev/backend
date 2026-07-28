<?php

declare(strict_types=1);

/**
 * RED — 8.4/8.5: tenancy (C10 design.md D4, Δ3) — the correctness-critical 95% zone.
 *
 * 8.4 is "the D4 regression test C9 never wrote": C9's ScoreEvaluationJob establishes
 * tenant context on its READ path but never re-verified the WRITE path against a null
 * ambient resolver in an isolated unit test — this file exercises exactly that for
 * WebhookDeliveryRecorder, which DOES establish context via
 * App\Support\Tenancy\TenantContextScope::runFor() before every INSERT.
 *
 * 8.5 proves cross-tenant isolation both on write (org A's delivery only ever carries
 * org A's webhook_url) and on read (TenantScoped's global scope keeps org B's query
 * from seeing org A's row, and vice versa).
 */

use App\Enums\WebhookEventType;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDeliveryRecorder;
use App\Support\Tenancy\TenantResolver;

test('recording with a null ambient TenantResolver still stamps the correct organization_id (D4 regression)', function (): void {
    [$org, $project, $participant] = c10RecorderFixtures();

    // Simulate the queue-context finding (design.md S7): no tenant established at all,
    // exactly as TenancyServiceProvider leaves it before any job re-establishes context.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(null);
    $resolver->setBypass(false);

    $delivery = app(WebhookDeliveryRecorder::class)->record(
        $project->id, $participant->id, WebhookEventType::Evaluation, 'null-resolver-dedupe', c10StubPayload()
    );

    expect($delivery->organization_id)->toBe($org->id);

    // TenantContextScope::runFor()'s finally block must restore the PREVIOUS (null)
    // ambient state — not leave org A's context leaked into whatever runs next.
    expect($resolver->getOrgId())->toBeNull();
});

test('cross-tenant: recording for org A never resolves org B webhook_url or webhook_secret', function (): void {
    [$orgA, $projectA, $participantA] = c10RecorderFixtures([
        'webhook_url' => 'https://org-a.example.test/hook',
        'webhook_secret' => 'orgA-secret-value',
    ]);

    $orgB = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgB->id);
    $resolver->setBypass(false);
    $projectB = Project::factory()->create([
        'webhook_url' => 'https://org-b.example.test/hook',
        'webhook_secret' => 'orgB-secret-value',
        'webhook_events' => ['progress', 'evaluation'],
    ]);
    Participant::factory()->forProject($projectB)->create();

    $deliveryA = app(WebhookDeliveryRecorder::class)->record(
        $projectA->id, $participantA->id, WebhookEventType::Evaluation, 'org-a-dedupe', c10StubPayload()
    );

    expect($deliveryA->target_url)->toBe('https://org-a.example.test/hook')
        ->and($deliveryA->target_url)->not->toBe('https://org-b.example.test/hook')
        ->and($deliveryA->organization_id)->toBe($orgA->id);

    // Read-side isolation: org B's ambient context must never see org A's row.
    $resolver->setOrgId($orgB->id);
    expect(WebhookDelivery::count())->toBe(0);

    // Org A's ambient context sees exactly its own row.
    $resolver->setOrgId($orgA->id);
    expect(WebhookDelivery::count())->toBe(1)
        ->and(WebhookDelivery::first()->target_url)->toBe('https://org-a.example.test/hook');
});

test('webhook_secret never appears in the persisted delivery row', function (): void {
    [, $project, $participant] = c10RecorderFixtures(['webhook_secret' => 'super-sensitive-secret-xyz']);

    $delivery = app(WebhookDeliveryRecorder::class)->record(
        $project->id, $participant->id, WebhookEventType::Evaluation, 'secret-leak-check', c10StubPayload()
    );

    expect(json_encode($delivery->getAttributes()))->not->toContain('super-sensitive-secret-xyz');
});

test('a project-not-found exception never contains any webhook_secret', function (): void {
    [, , $participant] = c10RecorderFixtures(['webhook_secret' => 'must-never-leak-in-exception']);

    $recorder = app(WebhookDeliveryRecorder::class);

    try {
        $recorder->record(
            999999999, $participant->id, WebhookEventType::Evaluation, 'nonexistent-project', c10StubPayload()
        );
        expect(false)->toBeTrue('Expected a RuntimeException for a missing project.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('must-never-leak-in-exception');
    }
});
