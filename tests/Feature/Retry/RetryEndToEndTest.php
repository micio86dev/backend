<?php

declare(strict_types=1);

/**
 * SA-07 end to end: the single re-interview of a `pending` evaluation, driven
 * only through the public entry points (scoring-retry-rt-b, slice PR3b).
 *
 * First run: the interview is scored `pending` (1 valid of 3) and its
 * `pending` webhook is delivered. An operator authorizes the retry over HTTP,
 * the candidate exchanges the returned link, re-interviews ONLY the invalid
 * competencies (the valid one is never asked again, and the first one opens
 * with the neutral `reinterview` greeting), the finalize trigger fires under
 * the attempt-scoped key, the job scores the missing competencies, and a
 * second `evaluation` webhook `completed` is delivered under
 * `{evaluation_id}:retry` with its own delivery id. A second authorization is
 * refused `retry_already_consumed`.
 *
 * Only the LLM (cassette), the avatar provider (HTTP fake) and the queue
 * transport (a faked queue whose pushed jobs are run by hand, in the order the
 * workers would) are substituted.
 *
 * REQ: Retry - Single Re-Interview of a `pending` Evaluation (RT-B)
 *      (openspec/changes/scoring-retry-rt-b/specs/scoring-engine/spec.md)
 *      Evaluation Retry Authorization Action
 *      (openspec/changes/scoring-retry-rt-b/specs/participant-sso/spec.md)
 */

use App\Contracts\LLMProvider;
use App\Enums\EvaluationStatus;
use App\Events\EvaluationCompleted;
use App\Jobs\FinalizeInterview;
use App\Jobs\ScoreEvaluationJob;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Models\Utterance;
use App\Testing\CassetteLLMProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

test('SA-07: authorize, re-interview only the invalid competencies, score, deliver a second webhook, and refuse a second retry', function (): void {
    Queue::fake();
    Http::fake(heygenOkFake());
    config(['interview.candidate_app_url' => 'https://candidate.test']);

    // ── first run: 3 competencies, only the first valid, scored `pending` ────
    $org = casOrg();
    [$project, $competencies] = casProject($org, 3);
    $codes = array_map(fn ($c): string => (string) $c->code, $competencies);
    $participant = casParticipant($org, $project, 'completato');

    $project = Project::withoutGlobalScopes()->findOrFail($project->id);
    $project->forceFill([
        'webhook_url' => 'https://receiver.example.test/hook',
        'webhook_secret' => 'whsec_sa07_secret',
        'webhook_events' => ['progress', 'evaluation'],
    ])->save();

    $evaluation = casInTenant($org, function () use ($project, $participant, $codes) {
        $evaluation = Evaluation::factory()->pending()->create([
            'participant_id' => $participant->id,
            'framework_version_id' => $project->framework_version_id,
            'retry_attempt' => false,
        ]);

        foreach ($codes as $i => $code) {
            $session = InterviewSession::create([
                'participant_id' => $participant->id,
                'project_id' => $project->id,
                'question_index' => $i,
                'competency_code' => $code,
                'framework_version_id' => $project->framework_version_id,
                'provider' => 'heygen',
                'status' => 'completed',
                'ended_reason' => 'completed',
                'ended_at' => now()->subHour(),
            ]);
            $utterance = new Utterance;
            $utterance->forceFill([
                'organization_id' => $participant->organization_id,
                'interview_session_id' => $session->id,
                'speaker' => 'Candidate',
                'text' => 'Answer for '.$code.'.',
                'ts' => now()->subHour()->addSeconds($i),
            ]);
            $utterance->save();

            $result = CompetencyResult::factory();
            $result = $i === 0 ? $result->valid() : $result->unscorable();
            $result = $result->create(['evaluation_id' => $evaluation->id, 'competency_code' => $code]);
            IndicatorScore::factory()->create(['competency_result_id' => $result->id]);
        }

        return $evaluation;
    });
    $validResultId = (int) DB::table('competency_results')->where('evaluation_id', $evaluation->id)->where('competency_code', $codes[0])->value('id');

    event(new EvaluationCompleted($evaluation->id));
    $first = DB::table('webhook_deliveries')->where('participant_id', $participant->id)->where('event_type', 'evaluation')->get();
    expect($first)->toHaveCount(1)
        ->and(json_decode($first[0]->payload, true)['data']['status'])->toBe('pending');
    // The first finalization still holds its two-hour key.
    Cache::add('finalize:'.$participant->id, true, 7200);

    // ── the operator authorizes the retry over HTTP ──────────────────────────
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id]));
    $operatorToken = auth('api')->login($user);

    $authorized = $this->withToken($operatorToken)->postJson("/api/participants/{$participant->id}/retry", ['reason' => 'scoring was below the gate']);
    $authorized->assertOk();
    expect($authorized->json('competencies_reset'))->toEqualCanonicalizing([$codes[1], $codes[2]]);
    expect(Participant::withoutGlobalScopes()->find($participant->id)->status)->toBe('in_attesa');

    // ── the candidate exchanges the returned link ────────────────────────────
    $linkToken = basename((string) parse_url($authorized->json('entry_url'), PHP_URL_PATH));
    $exchange = $this->getJson('/api/sso/exchange?token='.$linkToken)->assertOk();
    $candidate = ['Authorization' => 'Bearer '.$exchange->json('access_token')];
    // The same link is single-use.
    $this->getJson('/api/sso/exchange?token='.$linkToken)->assertUnauthorized();

    // ── re-interview: only the invalid competencies, the first one reinterview ─
    $start = $this->withHeaders($candidate)->postJson('/api/candidate/interview/start')->assertStatus(201);
    $firstSession = InterviewSession::withoutGlobalScopes()->findOrFail($start->json('session_id'));
    expect($firstSession->competency_code)->toBe($codes[1]);

    $opening = (string) Http::recorded(fn ($request) => str_contains($request->url(), '/contexts'))->map(fn (array $pair) => $pair[0]->data())->values()->last()['opening_text'];
    expect($opening)->toBe(trans('interview.opening.reinterview_authored', ['question' => 'CAS fixture question 1'], $project->language));

    $this->withHeaders($candidate)->postJson('/api/candidate/interview/end', ['session_id' => $firstSession->id, 'ended_reason' => 'completed'])
        ->assertOk()->assertJsonPath('next_action', 'continue');

    $second = $this->withHeaders($candidate)->postJson('/api/candidate/interview/start')->assertStatus(201);
    $secondSession = InterviewSession::withoutGlobalScopes()->findOrFail($second->json('session_id'));
    expect($secondSession->competency_code)->toBe($codes[2]);

    $this->withHeaders($candidate)->postJson('/api/candidate/interview/end', ['session_id' => $secondSession->id, 'ended_reason' => 'completed'])
        ->assertOk()->assertJsonPath('next_action', 'done');

    // The valid competency's session was never touched or re-asked.
    expect(InterviewSession::withoutGlobalScopes()->where('participant_id', $participant->id)->where('competency_code', $codes[0])->count())->toBe(1)
        ->and(Participant::withoutGlobalScopes()->find($participant->id)->status)->toBe('in_valutazione');

    // ── the workers run what the endpoints queued ────────────────────────────
    Queue::assertPushed(FinalizeInterview::class);
    (new FinalizeInterview($participant->id, $org->id))->handle();
    expect(Cache::has('finalize:'.$participant->id.':retry'))->toBeTrue();
    Queue::assertPushed(ScoreEvaluationJob::class);

    $entries = [];
    foreach ([$codes[1], $codes[2]] as $code) {
        $entries[$code] = json_encode(['behaviors' => [[
            'indicator' => 'CAS fixture indicator '.array_search($code, $codes, true),
            'score' => 5,
            'explanation' => 'Clear evidence.',
            'excerpts' => ['Answer for '.$code.'.'],
        ]]], JSON_THROW_ON_ERROR);
    }
    app()->instance(LLMProvider::class, new CassetteLLMProvider($entries));
    (new ScoreEvaluationJob($participant->id, retryAttempt: true))->handle();

    // ── outcome ──────────────────────────────────────────────────────────────
    $finalEvaluation = Evaluation::withoutGlobalScopes()->findOrFail($evaluation->id);
    expect($finalEvaluation->status)->toBe(EvaluationStatus::Completed)
        ->and($finalEvaluation->retry_attempt)->toBeTrue()
        ->and(Participant::withoutGlobalScopes()->find($participant->id)->status)->toBe('completato')
        ->and(DB::table('competency_results')->where('id', $validResultId)->exists())->toBeTrue()
        ->and(DB::table('competency_results')->where('evaluation_id', $evaluation->id)->count())->toBe(3);

    $rows = DB::table('webhook_deliveries')->where('participant_id', $participant->id)->where('event_type', 'evaluation')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->dedupe_key)->toBe((string) $evaluation->id)
        ->and($rows[1]->dedupe_key)->toBe($evaluation->id.':retry')
        ->and($rows[1]->delivery_id)->not->toBe($rows[0]->delivery_id)
        ->and(json_decode($rows[1]->payload, true)['data']['status'])->toBe('completed');

    // ── a second authorization is refused ────────────────────────────────────
    $this->withToken($operatorToken)->postJson("/api/participants/{$participant->id}/retry")
        ->assertStatus(409)->assertExactJson(['reason' => 'retry_already_consumed']);
});
