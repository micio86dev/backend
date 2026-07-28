<?php

declare(strict_types=1);

/**
 * RED — 12.3: SendProgressWebhook listener (C10, design.md D4/D7).
 *
 * Mirrors SendEvaluationWebhook's pattern exactly (plain listener, try/catch,
 * recorder + assembler, dispatch-if-pending) — auto-discovered for BOTH
 * ParticipantCreated and CompetencySessionEnded via a single union-typed handle().
 *
 * Payload assembly itself (ProgressPayloadAssembler) is already thoroughly tested in
 * PR3 (ProgressPayloadAssemblerTest.php) — this file proves the LISTENER wires
 * events → recorder → assembler → delivery row correctly, per the two spec
 * scenarios: new-candidate (full competency list, empty answers) and advancement
 * (cumulative state).
 */

use App\Enums\WebhookDeliveryStatus;
use App\Events\CompetencySessionEnded;
use App\Events\ParticipantCreated;
use App\Jobs\DeliverWebhookJob;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{0: Organization, 1: Project, 2: Participant}
 */
function c10ProgressListenerFixtures(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create([
        'webhook_url' => 'https://receiver.example.test/hook',
        'webhook_secret' => 'whsec_progress_listener_test_secret',
        'webhook_events' => ['progress', 'evaluation'],
    ]);

    $prs = Competency::factory()->create(['code' => 'PRS']);
    $col = Competency::factory()->create(['code' => 'COL']);
    $project->competencies()->attach([
        $prs->id => ['position' => 0],
        $col->id => ['position' => 1],
    ]);

    $participant = Participant::factory()->forProject($project)->create([
        'candidate_ref' => 'progress-listener-verbatim-ref',
    ]);

    return [$org, $project, $participant];
}

test('ParticipantCreated produces a new-candidate payload: full competency list, empty answers', function (): void {
    Queue::fake();

    [, $project, $participant] = c10ProgressListenerFixtures();

    event(new ParticipantCreated($participant->id, $project->id));

    $delivery = WebhookDelivery::where('participant_id', $participant->id)->first();
    expect($delivery)->not->toBeNull()
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->payload['candidate_ref'])->toBe('progress-listener-verbatim-ref')
        ->and(array_column($delivery->payload['data']['competencies'], 'code'))->toBe(['PRS', 'COL']);

    foreach ($delivery->payload['data']['competencies'] as $competency) {
        expect($competency['answers'])->toBe([]);
    }

    Queue::assertPushed(DeliverWebhookJob::class);
});

test('CompetencySessionEnded produces an advancement payload reflecting cumulative state', function (): void {
    Queue::fake();

    [$org, $project, $participant] = c10ProgressListenerFixtures();

    InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'status' => 'completed',
        'ended_reason' => 'completed',
        'started_at' => now()->subMinutes(5),
        'ended_at' => now(),
    ]);

    event(new CompetencySessionEnded($participant->id, $project->id, 'PRS'));

    $delivery = WebhookDelivery::where('participant_id', $participant->id)->first();
    expect($delivery)->not->toBeNull()
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending);

    $byCode = collect($delivery->payload['data']['competencies'])->keyBy('code');
    expect($byCode['PRS']['status'])->toBe('completed')
        ->and($byCode['PRS']['answers'])->toHaveCount(1)
        ->and($byCode['COL']['answers'])->toBe([]);

    Queue::assertPushed(DeliverWebhookJob::class);
});

test('a forced exception inside the recorder is caught and never propagates back to the caller', function (): void {
    Queue::fake();

    $nonExistentParticipantId = 999999999;

    expect(fn () => event(new ParticipantCreated($nonExistentParticipantId, 1)))->not->toThrow(Throwable::class);

    expect(WebhookDelivery::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('skipped gate outcome (no_webhook_url) dispatches nothing', function (): void {
    Queue::fake();

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['webhook_url' => null, 'webhook_secret' => null]);
    $participant = Participant::factory()->forProject($project)->create();

    event(new ParticipantCreated($participant->id, $project->id));

    $delivery = WebhookDelivery::where('participant_id', $participant->id)->first();
    expect($delivery)->not->toBeNull()
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Skipped);

    Queue::assertNothingPushed();
});
