<?php

declare(strict_types=1);

/**
 * The `progress` webhook's first `answers` entry (interview-question-index-offset,
 * D1/D8).
 *
 * `Unit/C10/ProgressPayloadAssemblerTest.php:118` already asserts
 * `answers[0].question_index` toBe(0), but its fixture (`c10CreateSession()`)
 * writes `question_index` directly and therefore cannot fail on the historic
 * `position - 1` defect. This test drives a real `/start` + `/end` on a project's
 * FIRST competency (`position = 0`) and reads the value the real writer produced,
 * end to end through `ProgressPayloadAssembler`.
 *
 * REQ: webhooks-integration "progress payload — creation and advancement cases" —
 *      the first competency's answers entry carries question_index 0, not -1.
 */

use App\Services\Webhooks\ProgressPayloadAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('the progress payload\'s first answers entry carries question_index 0, not -1', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project, $competencies] = casDenseProject($org, 2);
    $participant = casParticipant($org, $project, 'in_attesa');
    $token = casBearer($participant);

    $start = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $start->json('session_id'),
            'ended_reason' => 'completed',
        ])->assertStatus(200);

    $payload = (new ProgressPayloadAssembler)->assemble($participant->id, (string) Str::uuid());
    $first = collect($payload['data']['competencies'])->firstWhere('code', $competencies[0]->code);

    expect($first)->not->toBeNull();
    expect($first['answers'])->toHaveCount(1);
    expect($first['answers'][0]['question_index'])
        ->toBe(0, 'the first competency (position = 0) must produce question_index = 0 in the progress payload, never -1');
});
