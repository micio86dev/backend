<?php

declare(strict_types=1);

/**
 * RED/GREEN — tavus-single-session-interview API-04.3 (scoring parity).
 *
 * Scoring reads the transcript per row, not per provider conversation. An interview whose
 * competencies share ONE conversation through continuations must hand scoring exactly what the
 * same interview on N separate conversations does.
 */

use App\Jobs\FinalizeInterview;
use App\Models\AvatarTemplate;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Services\Conversation\PromptSetResolver;
use App\Services\Scoring\TranscriptAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/** @param array<string, mixed> $body */
function srpPost(Participant $participant, string $action, array $body = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson("/api/candidate/interview/{$action}", $body);
}

/**
 * Run a scripted three-competency Tavus interview and describe what scoring would receive.
 *
 * @return array{refs: int, shape: list<array<string, mixed>>, corpora: list<array{prompt: string, validation: string}>, finalized: int, participant: string}
 */
function srpRun(bool $shared): array
{
    Queue::fake();
    PromptSetResolver::flushCache();
    config(['interview.tavus.single_session' => $shared, 'interview.tavus.single_session_projects' => []]);
    Http::fake([
        '*tavusapi*/v2/conversations' => fn () => Http::response([
            'conversation_id' => 'conv-'.uniqid('', true),
            'conversation_url' => 'https://tavus.io/conv',
        ], 200),
        '*tavusapi*/v2/conversations/*' => Http::response([], 200),
    ]);

    $org = casOrg();
    [$project, $comps] = casProject($org, 3);
    $project->forceFill(['avatar_template_id' => AvatarTemplate::create([
        'name' => 'srp '.uniqid(), 'provider' => 'tavus', 'config' => [],
    ])->id])->save();
    $participant = casParticipant($org, $project, 'in_attesa');
    $codes = array_map(fn ($c): string => $c->code, $comps);

    $ref = null;
    foreach ($codes as $i => $code) {
        $body = $shared && $ref !== null ? ['live_conversation_id' => $ref] : [];
        $started = srpPost($participant, 'start', $body)->assertStatus(201);
        if ($shared) {
            $ref = $started->json('continuation.conversation_id') ?? $started->json('conversation_id');
            expect($started->json('continuation') !== null)->toBe($i > 0);
        }
        $id = $started->json('session_id');
        foreach ([['avatar', "Question about competency {$i}."], ['candidate', "My answer number {$i} with some detail."]] as [$speaker, $text]) {
            srpPost($participant, 'utterance', ['session_id' => $id, 'speaker' => $speaker, 'text' => $text, 'ts' => now()->addSeconds($i)->toIso8601String()])->assertStatus(202);
        }
        srpPost($participant, 'end', ['session_id' => $id, 'ended_reason' => 'completed'])->assertOk();
    }

    $sessions = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->orderBy('id')->get());
    $assembler = app(TranscriptAssembler::class);

    return [
        'refs' => $sessions->pluck('provider_session_ref')->unique()->count(),
        'shape' => $sessions->map(fn (InterviewSession $s): array => [
            'ordinal' => array_search($s->competency_code, $codes, true),
            'status' => $s->status,
            'ended_reason' => $s->ended_reason,
            'question_index' => $s->question_index,
            'utterances' => $s->utterances()->count(),
        ])->all(),
        // The competency code is a random fixture value: normalise it to its ordinal.
        'corpora' => array_map(function (string $code) use ($participant, $assembler, $codes): array {
            $corpora = $assembler->assembleForParticipant($participant->id, $code);

            return [
                'prompt' => str_replace($codes, ['C0', 'C1', 'C2'], $corpora->prompt),
                'validation' => $corpora->validation,
            ];
        }, $codes),
        'finalized' => Queue::pushed(FinalizeInterview::class)->count(),
        'participant' => (string) DB::table('participants')->where('id', $participant->id)->value('status'),
    ];
}

test('scoring receives the same input for one shared conversation as for three separate ones', function (): void {
    $separate = srpRun(shared: false);
    $shared = srpRun(shared: true);

    // The two runs really differ in what they are comparing.
    expect($separate['refs'])->toBe(3)->and($shared['refs'])->toBe(1);

    expect($shared['shape'])->toBe($separate['shape'])
        ->and($shared['corpora'])->toBe($separate['corpora'])
        ->and($shared['finalized'])->toBe($separate['finalized'])->toBe(1)
        ->and($shared['participant'])->toBe($separate['participant'])->toBe('in_valutazione');
});
