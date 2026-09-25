<?php

declare(strict_types=1);

/**
 * `App\Models\InterviewEvent` (public-api step 5, G-34) — structural/relation
 * coverage not otherwise exercised by the HTTP-level `T-INT`/`T-TOK` suites
 * (which only ever create `created`/`invited`/`token_consumed` rows through
 * the action/controller, never read the model's own accessors back).
 */

use App\Models\InterviewEvent;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;

test('publicIdPrefix() is evt_, and PublicId::encode() produces an evt_ prefixed id', function (): void {
    $org = Organization::factory()->create();

    $event = TenantContextScope::runFor($org->id, function () use ($org): InterviewEvent {
        $project = Project::factory()->create(['organization_id' => $org->id]);
        $participant = Participant::factory()->forProject($project)->create(['organization_id' => $org->id]);

        return InterviewEvent::create([
            'participant_id' => $participant->id,
            'type' => 'created',
            'occurred_at' => now(),
        ]);
    });

    expect(InterviewEvent::publicIdPrefix())->toBe('evt_');
    expect(PublicId::encode($event))->toStartWith('evt_');
});

test('participant() resolves the owning Participant', function (): void {
    $org = Organization::factory()->create();

    [$event, $participant] = TenantContextScope::runFor($org->id, function () use ($org): array {
        $project = Project::factory()->create(['organization_id' => $org->id]);
        $participant = Participant::factory()->forProject($project)->create(['organization_id' => $org->id]);

        $event = InterviewEvent::create([
            'participant_id' => $participant->id,
            'type' => 'invited',
            'occurred_at' => now(),
        ]);

        return [$event, $participant];
    });

    expect($event->participant)->not->toBeNull();
    expect($event->participant?->id)->toBe($participant->id);
});

test('InterviewEvent has no updated_at column (append-only)', function (): void {
    $event = new InterviewEvent;

    expect($event->timestamps)->toBeFalse();
});
