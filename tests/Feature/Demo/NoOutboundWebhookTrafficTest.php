<?php

declare(strict_types=1);

/**
 * `beai:demo-seed` inserts `webhook_deliveries` rows DIRECTLY via
 * `WebhookDelivery::create()` — never through `WebhookDeliveryRecorder`,
 * never through `SendEvaluationWebhook`/`SendProgressWebhook`, so no
 * listener ever fires and `DeliverWebhookJob` is never dispatched (proposal
 * correction 3; design D15). Until this test, that guarantee rested on a
 * static code read plus the non-resolvable `.invalid` host as defence in
 * depth — nothing in the suite actually PROVED it at runtime.
 *
 * `Http::preventStrayRequests()` (verified standalone, no `Http::fake()`
 * required — confirmed empirically: it throws
 * `Illuminate\Http\Client\StrayRequestException` for any un-faked outbound
 * call) makes a real attempt fail LOUDLY instead of silently resolving
 * nowhere against `.invalid`. `Event::fake()` on the four
 * webhook-triggering events adds a second, independent proof: even the
 * EVENTS a real interview flow would dispatch are never fired by the demo
 * writers, which run outside that entire pipeline.
 */

use App\Events\CompetencySessionEnded;
use App\Events\EvaluationCompleted;
use App\Events\EvaluationFailed;
use App\Events\ParticipantCreated;
use App\Models\Organization;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

test('beai:demo-seed never makes an outbound HTTP call, even though it seeds 8 webhook_deliveries rows', function (): void {
    Http::preventStrayRequests();

    // Reaching the assertion below is the proof: any un-faked outbound call
    // made during the run throws Illuminate\Http\Client\StrayRequestException
    // (confirmed empirically — preventStrayRequests() works standalone, no
    // Http::fake() required) and fails this test loudly, instead of quietly
    // resolving nowhere against `.invalid`. No intermediate variable holds
    // the PendingCommand (that would delay real execution past this line —
    // see CensusGateAdditiveTest's note on the same gotcha).
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    // The command completing at all, past the preventStrayRequests() guard
    // above, is the substantive assertion; this makes it an explicit one
    // too, so the test never reports as risky/assertion-free.
    expect(true)->toBeTrue();
});

test('beai:demo-seed never dispatches the progress/evaluation events a real delivery pipeline listens for', function (): void {
    Event::fake([
        ParticipantCreated::class,
        CompetencySessionEnded::class,
        EvaluationCompleted::class,
        EvaluationFailed::class,
    ]);

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    Event::assertNotDispatched(ParticipantCreated::class);
    Event::assertNotDispatched(CompetencySessionEnded::class);
    Event::assertNotDispatched(EvaluationCompleted::class);
    Event::assertNotDispatched(EvaluationFailed::class);
});
