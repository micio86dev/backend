<?php

declare(strict_types=1);

use App\Models\InterviewSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('/end stops the HeyGen session and deletes its context; a provider failure never fails /end', function (int $stopStatus): void {
    Queue::fake();
    $org = casOrg();
    [$project] = casProject($org, 1);
    $participant = casParticipant($org, $project);
    $headers = ['Authorization' => 'Bearer '.casBearer($participant)];

    // One fake for the whole test: stubs from a second Http::fake() would sit BEHIND these.
    Http::fake(['*liveavatar*/sessions/stop' => Http::response([], $stopStatus)] + heygenOkFake());
    $this->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertStatus(201);
    $session = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->firstOrFail());
    $session->forceFill(['provider_session_ref' => 'hg-ref', 'provider_context_ref' => 'hg-ctx'])->save();

    $this->withHeaders($headers)
        ->postJson('/api/candidate/interview/end', ['session_id' => $session->id, 'ended_reason' => 'completed'])
        ->assertOk();

    $calls = Http::recorded()
        ->map(fn (array $pair): string => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))
        ->filter(fn (string $call): bool => str_ends_with($call, '/sessions/stop') || str_starts_with($call, 'DELETE '))
        ->values()->all();
    expect($calls)->toBe($stopStatus === 200 ? ['POST /v1/sessions/stop', 'DELETE /v1/contexts/hg-ctx'] : ['POST /v1/sessions/stop']);
})->with([200, 500]);
