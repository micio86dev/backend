<?php

declare(strict_types=1);

/**
 * RED/GREEN — tavus-single-session-interview API-03b.4: the anti-leak sentinel.
 *
 * A single-session conversation hands the model every covered competency's anchors at creation, so
 * the one place they may appear is the provider create request. A UUID planted in `anchor_5`
 * must be PRESENT in the faked `POST /v2/conversations` body and ABSENT from the stored plan and
 * from the body of every candidate-facing response: `/start`, `/utterance`, `/end`, `/integrity`
 * and `/snapshot`.
 */

use App\Models\AvatarTemplate;
use App\Models\BarsIndicator;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Services\Conversation\PromptSetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('an anchor reaches only the provider create request', function (): void {
    Queue::fake();
    Storage::fake();
    PromptSetResolver::flushCache();
    config(['interview.tavus.single_session' => true]);

    $sentinel = (string) Str::uuid();
    $org = Organization::factory()->create();
    [$project, $competencies] = casProject($org, 2);
    $project->forceFill(['avatar_template_id' => AvatarTemplate::create(['name' => 'sentinel '.uniqid(), 'provider' => 'tavus', 'config' => []])->id])->save();

    foreach ($competencies as $competency) {
        BarsIndicator::query()->where('competency_id', $competency->id)->update(['anchor_5' => json_encode(['en' => $sentinel.'-'.$competency->code, 'it' => $sentinel.'-'.$competency->code])]);
    }

    Http::fake([
        '*tavusapi*/v2/conversations' => fn () => Http::response(['conversation_id' => 'conv-'.uniqid('', true), 'conversation_url' => 'https://tavus.io/conv'], 200),
        '*tavusapi*/v2/conversations/*' => Http::response([], 200),
    ]);

    $participant = casParticipant($org, $project, 'in_attesa');
    $headers = ['Authorization' => 'Bearer '.casBearer($participant)];

    $start = $this->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertStatus(201);
    $sessionId = $start->json('session_id');

    $utterance = $this->withHeaders($headers)->postJson('/api/candidate/interview/utterance', [
        'session_id' => $sessionId,
        'speaker' => 'candidate',
        'text' => 'A candidate answer.',
        'ts' => now()->toIso8601String(),
    ])->assertSuccessful();

    $integrity = $this->withHeaders($headers)->postJson('/api/candidate/interview/integrity', [
        'session_id' => $sessionId,
        'events' => [['kind' => 'tab_hidden', 'payload' => [], 'ts' => now()->toIso8601String()]],
    ])->assertSuccessful();

    $snapshot = $this->withHeaders($headers)->postJson('/api/candidate/interview/snapshot', [
        'session_id' => $sessionId,
        'image_base64' => base64_encode("\xFF\xD8\xFF".str_repeat('A', 10)),
    ])->assertSuccessful();

    $end = $this->withHeaders($headers)->postJson('/api/candidate/interview/end', [
        'session_id' => $sessionId,
        'ended_reason' => 'completed',
    ])->assertSuccessful();

    $create = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->first(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v2/conversations'));
    $stored = casInTenant($org, fn () => json_encode(InterviewSession::query()->where('participant_id', $participant->id)->orderBy('id')->first()->conversation_plan));

    // The create request carries both competencies' anchors; nothing else does.
    foreach ($competencies as $competency) {
        expect($create->data()['conversational_context'])->toContain($sentinel.'-'.$competency->code);
    }

    expect($stored)->toBeString()->not->toBeEmpty();

    foreach ([$start, $utterance, $end, $integrity, $snapshot] as $response) {
        expect($response->getContent())->not->toContain($sentinel);
    }

    expect($stored)->not->toContain($sentinel);
});
