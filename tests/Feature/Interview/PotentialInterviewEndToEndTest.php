<?php

declare(strict_types=1);

/**
 * End-to-end pin for a `potential` interview: `/start` per competency, then
 * scoring and the 90% completion gate.
 *
 * A `potential` project (`role_code` null, MTG and LAT with ROLE-LESS BARS rows)
 * must flow through the same `ScoreEvaluationJob`, reliability, completion gate
 * and evaluation webhook as `standard`. This file adds no production behavior:
 * it proves the pieces compose.
 *
 * Helpers are file-local (`potE2e*`); nothing is shared with other test files.
 *
 * REQ: Potential Scoring And Completion Parity (potential-assessment-interview)
 */

use App\Contracts\LLMProvider;
use App\Enums\EvaluationStatus;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\FinalizeInterview;
use App\Jobs\ScoreEvaluationJob;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\User;
use App\Models\Utterance;
use App\Models\WebhookDelivery;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use App\Testing\CassetteLLMProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Seed a `potential` project with a webhook enabled for `evaluation`, two
 * role-less competencies (MTG, LAT) with 3 indicators and one authored primary
 * each, and an `in_attesa` participant.
 *
 * @return array{org: Organization, project: Project, participant: Participant, codes: list<string>}
 */
function potE2eSeed(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create([
        'status' => 'active',
        'role_code' => null,
        'language' => 'en',
        'assessment_type' => 'potential',
        'webhook_url' => 'https://calling-system.example.test/hooks',
        'webhook_secret' => 'whsec_potential_e2e',
        'webhook_events' => ['evaluation'],
    ]);

    $codes = [];

    foreach (['MTG', 'LAT'] as $position => $prefix) {
        $competency = Competency::factory()->potential()->create(['code' => $prefix.'_'.uniqid()]);

        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $competency->id,
            'position' => $position + 1,
        ]);

        foreach ([0, 1, 2] as $indicatorPosition) {
            $indicator = new BarsIndicator;
            $indicator->forceFill([
                'role_id' => null,
                'competency_id' => $competency->id,
                'revision_id' => $competency->revision_id,
                'text' => ['en' => "{$prefix} indicator {$indicatorPosition}"],
                'anchor_5' => ['en' => 'Anchor 5'],
                'anchor_3' => ['en' => 'Anchor 3'],
                'anchor_1' => ['en' => 'Anchor 1'],
                'position' => $indicatorPosition,
            ]);
            $indicator->save();
        }

        ProjectQuestion::create([
            'project_id' => $project->id,
            'competency_id' => $competency->id,
            'text' => ['en' => "Authored {$prefix} question", 'it' => "Domanda {$prefix}"],
            'position' => 0,
        ]);

        $codes[] = (string) $competency->code;
    }

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'pot-e2e-'.uniqid(),
        'display_name' => 'Potential E2E',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_attesa',
    ]);
    $participant->save();

    return ['org' => $org, 'project' => $project, 'participant' => $participant->fresh(), 'codes' => $codes];
}

/**
 * Drive `/start` once per competency through the real endpoint, completing the
 * session it opened (with a candidate utterance) before the next call, then
 * move the participant to `in_valutazione` as the interview-finalize step does.
 *
 * @param  array{org: Organization, project: Project, participant: Participant, codes: list<string>}  $scenario
 */
function potE2eRunInterview(array $scenario, object $test): void
{
    $bearer = CandidateTokenFactory::mintCandidateToken($scenario['participant']);

    foreach ($scenario['codes'] as $code) {
        $test->withHeaders(['Authorization' => 'Bearer '.$bearer])
            ->postJson('/api/candidate/interview/start')
            ->assertStatus(201);

        $session = InterviewSession::where('participant_id', $scenario['participant']->id)
            ->where('competency_code', $code)
            ->firstOrFail();

        $session->forceFill(['status' => 'completed'])->save();

        // `/end` closes the live stretch `/start` opened; completing the session by
        // hand must too, or a later retry `/start` finds it still open.
        DB::table('interview_session_live_periods')
            ->where('interview_session_id', $session->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now(), 'closed_reason' => 'end']);

        $utterance = new Utterance;
        $utterance->forceFill([
            'organization_id' => $scenario['org']->id,
            'interview_session_id' => $session->id,
            'speaker' => 'Candidate',
            'text' => 'I led the rollout end to end and checked in with the team daily.',
            'ts' => now(),
        ]);
        $utterance->save();
    }

    Participant::withoutGlobalScopes()->whereKey($scenario['participant']->id)->update(['status' => 'in_valutazione']);
}

/**
 * @return array{behaviors: list<array<string, mixed>>}
 */
function potE2eBehaviors(int $first, int $second, int $third): array
{
    $scores = [$first, $second, $third];
    $behaviors = [];

    foreach ($scores as $index => $score) {
        $behaviors[] = [
            'indicator' => "echo {$index}",
            'score' => $score,
            'explanation' => 'Scored from the transcript.',
            'excerpts' => $score === -1 ? [] : ['I led the rollout end to end'],
        ];
    }

    return ['behaviors' => $behaviors];
}

function potE2eEvaluation(Participant $participant): Evaluation
{
    return Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->firstOrFail();
}

// ─── Both competencies valid → completato ────────────────────────────────────

test('a potential interview reaches completato through scoring and the shared gate', function (): void {
    Queue::fake();
    Http::fake(['*liveavatar*/contexts*' => Http::response(['data' => ['context_id' => 'ctx-pot']], 200),
        '*liveavatar*/sessions/token*' => Http::response(['data' => ['session_id' => 'sess-pot', 'session_token' => 'tok-pot']], 200),
        '*liveavatar*/sessions/*' => Http::response([], 200)]);

    $scenario = potE2eSeed();
    potE2eRunInterview($scenario, test());

    app()->instance(LLMProvider::class, new CassetteLLMProvider(array_fill_keys(
        $scenario['codes'],
        json_encode(potE2eBehaviors(5, 3, 1), JSON_THROW_ON_ERROR),
    )));

    (new ScoreEvaluationJob($scenario['participant']->id))->handle();

    $participant = Participant::withoutGlobalScopes()->findOrFail($scenario['participant']->id);
    expect($participant->status)->toBe('completato');

    $evaluation = potE2eEvaluation($participant);
    expect($evaluation->status->value)->toBe('completed');
    expect($evaluation->framework_version_id)->not->toBeNull();
    expect($evaluation->model_version)->toBeString()->not->toBe('');
    expect($evaluation->prompt_version)->toBeString()->not->toBe('');
    expect($evaluation->evaluated_at)->not->toBeNull();

    $results = CompetencyResult::withoutGlobalScopes()->where('evaluation_id', $evaluation->id)->get();
    expect($results)->toHaveCount(2);

    foreach ($results as $result) {
        // 3 assessed of 3 indicators: reliability = assessed / total = 1.0.
        expect((float) $result->reliability)->toBe(1.0);
        expect((bool) $result->valid)->toBeTrue();
        expect($result->unscorable_reason)->toBeNull();
    }

    Queue::assertPushed(DeliverWebhookJob::class, 1);

    $delivery = WebhookDelivery::withoutGlobalScopes()->where('participant_id', $participant->id)->firstOrFail();
    $payload = $delivery->payload;

    expect(array_keys($payload))->toEqualCanonicalizing(
        ['version', 'event', 'delivery_id', 'occurred_at', 'livemode', 'candidate_ref', 'project', 'data'],
    );
    expect($payload['event'])->toBe('evaluation');
    expect($payload['candidate_ref'])->toBe($participant->candidate_ref);
    expect($payload['data']['status'])->toBe('completed');
    // jsonb does not preserve object key order: compare as a set.
    expect(array_keys($payload['data']['text']))->toEqualCanonicalizing($scenario['codes']);
    expect($payload['data']['text'][$scenario['codes'][0]]['behaviors'])->toHaveCount(3);
});

// ─── Below the 90% gate → pending, partial webhook ───────────────────────────

test('a potential evaluation below the gate is pending and ships partial data', function (): void {
    Queue::fake();
    Http::fake(['*liveavatar*/contexts*' => Http::response(['data' => ['context_id' => 'ctx-pot']], 200),
        '*liveavatar*/sessions/token*' => Http::response(['data' => ['session_id' => 'sess-pot', 'session_token' => 'tok-pot']], 200),
        '*liveavatar*/sessions/*' => Http::response([], 200)]);

    $scenario = potE2eSeed();
    potE2eRunInterview($scenario, test());

    // First competency fully assessed; second has no assessable evidence at all.
    app()->instance(LLMProvider::class, new CassetteLLMProvider([
        $scenario['codes'][0] => json_encode(potE2eBehaviors(5, 3, 1), JSON_THROW_ON_ERROR),
        $scenario['codes'][1] => json_encode(potE2eBehaviors(-1, -1, -1), JSON_THROW_ON_ERROR),
    ]));

    (new ScoreEvaluationJob($scenario['participant']->id))->handle();

    $participant = Participant::withoutGlobalScopes()->findOrFail($scenario['participant']->id);
    $evaluation = potE2eEvaluation($participant);

    // 1 valid of 2 competencies = 50% < 90%.
    expect($evaluation->status->value)->toBe('pending');

    // The candidate lifecycle still closes on the first terminal resolution.
    expect($participant->status)->toBe('completato');

    $valid = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('valid', true)
        ->count();
    expect($valid)->toBe(1);

    $delivery = WebhookDelivery::withoutGlobalScopes()->where('participant_id', $participant->id)->firstOrFail();

    expect($delivery->payload['data']['status'])->toBe('pending');
    // jsonb does not preserve object key order: compare as a set.
    expect(array_keys($delivery->payload['data']['text']))->toEqualCanonicalizing($scenario['codes']);
    expect($delivery->payload['data']['text'][$scenario['codes'][1]]['reliability'])->toBe('0%');
});

// ─── Below the gate → one authorized retry → definitive completed ────────────

test('a below-gate potential evaluation is retried once through the real surfaces and ends definitive', function (): void {
    Queue::fake();
    Http::fake(heygenOkFake());
    config(['interview.candidate_app_url' => 'https://candidate.test']);

    // ── first run: MTG valid, LAT with no assessable evidence → pending ──────
    $scenario = potE2eSeed();
    $participantId = $scenario['participant']->id;
    [$validCode, $invalidCode] = $scenario['codes'];

    potE2eRunInterview($scenario, test());
    app()->instance(LLMProvider::class, new CassetteLLMProvider([
        $validCode => json_encode(potE2eBehaviors(5, 3, 1), JSON_THROW_ON_ERROR),
        $invalidCode => json_encode(potE2eBehaviors(-1, -1, -1), JSON_THROW_ON_ERROR),
    ]));
    (new ScoreEvaluationJob($participantId))->handle();

    $evaluation = potE2eEvaluation(Participant::withoutGlobalScopes()->findOrFail($participantId));
    expect($evaluation->status->value)->toBe('pending');

    $validResultId = (int) DB::table('competency_results')
        ->where('evaluation_id', $evaluation->id)->where('competency_code', $validCode)->value('id');
    // The first finalization still holds its two-hour key.
    Cache::add('finalize:'.$participantId, true, 7200);

    // ── an operator of the organization authorizes the retry over HTTP ───────
    $org = $scenario['org'];
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id]));
    $operatorToken = auth('api')->login($user);

    $authorized = $this->withToken($operatorToken)->postJson("/api/participants/{$participantId}/retry");
    $authorized->assertOk();
    // Only the competency without a valid result is asked again, MTG/LAT with no role in play.
    expect($authorized->json('competencies_reset'))->toBe([$invalidCode]);
    expect(Participant::withoutGlobalScopes()->find($participantId)->status)->toBe('in_attesa');

    // ── the candidate re-interviews ONLY the invalid competency ──────────────
    // One PHP process serves every request of this test, so the guards would hand the
    // retry requests the Participant they resolved during the first interview, with
    // its stale `in_corso` status. Production serves each request in a fresh process.
    app('auth')->forgetGuards();

    $linkToken = basename((string) parse_url($authorized->json('entry_url'), PHP_URL_PATH));
    $exchange = $this->getJson('/api/sso/exchange?token='.$linkToken)->assertOk();
    $candidate = ['Authorization' => 'Bearer '.$exchange->json('access_token')];

    $start = $this->withHeaders($candidate)->postJson('/api/candidate/interview/start')->assertStatus(201);
    $session = InterviewSession::withoutGlobalScopes()->findOrFail($start->json('session_id'));
    expect($session->competency_code)->toBe($invalidCode);

    $this->withHeaders($candidate)->postJson('/api/candidate/interview/end', ['session_id' => $session->id, 'ended_reason' => 'completed'])
        ->assertOk()->assertJsonPath('next_action', 'done');

    // The valid competency was never asked again.
    expect(InterviewSession::withoutGlobalScopes()->where('participant_id', $participantId)->where('competency_code', $validCode)->count())->toBe(1)
        ->and(Participant::withoutGlobalScopes()->find($participantId)->status)->toBe('in_valutazione');

    // ── the workers run what the endpoints queued ────────────────────────────
    Queue::assertPushed(FinalizeInterview::class);
    (new FinalizeInterview($participantId, $org->id))->handle();
    Queue::assertPushed(ScoreEvaluationJob::class);

    app()->instance(LLMProvider::class, new CassetteLLMProvider([
        $invalidCode => json_encode(potE2eBehaviors(5, 3, 3), JSON_THROW_ON_ERROR),
    ]));
    (new ScoreEvaluationJob($participantId, retryAttempt: true))->handle();

    // ── outcome: definitive completed, role-less scoring on both competencies ─
    $final = Evaluation::withoutGlobalScopes()->findOrFail($evaluation->id);
    expect($final->status)->toBe(EvaluationStatus::Completed)
        ->and($final->retry_attempt)->toBeTrue()
        ->and(Participant::withoutGlobalScopes()->find($participantId)->status)->toBe('completato')
        ->and(DB::table('competency_results')->where('id', $validResultId)->exists())->toBeTrue()
        ->and(DB::table('competency_results')->where('evaluation_id', $evaluation->id)->count())->toBe(2)
        ->and(DB::table('competency_results')->where('evaluation_id', $evaluation->id)->where('valid', true)->count())->toBe(2);

    $rows = DB::table('webhook_deliveries')->where('participant_id', $participantId)->where('event_type', 'evaluation')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->dedupe_key)->toBe((string) $evaluation->id)
        ->and($rows[1]->dedupe_key)->toBe($evaluation->id.':retry')
        ->and(json_decode($rows[1]->payload, true)['data']['status'])->toBe('completed');

    // ── the single retry is spent ────────────────────────────────────────────
    $this->withToken($operatorToken)->postJson("/api/participants/{$participantId}/retry")
        ->assertStatus(409)->assertExactJson(['reason' => 'retry_already_consumed']);
});
