<?php

declare(strict_types=1);

/**
 * RED/GREEN — tavus-single-session-interview API-04.1/API-04.2 (design N6, A9).
 *
 * A continuation is granted only for a conversation this participant owns, whose stored plan
 * covers the next competency and whose next row is brand new. Every other input yields null and
 * writes nothing, so the caller falls to the ordinary issue path.
 */

use App\Actions\Interview\AdvanceOnLiveConversation;
use App\Models\AvatarTemplate;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLivePeriod;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * @return array{org: Organization, project: Project, participant: Participant, owner: InterviewSession, codes: list<string>}
 */
function aolScenario(string $ref = 'conv-1', int $competencies = 3): array
{
    config(['interview.tavus.single_session' => true, 'interview.tavus.single_session_projects' => []]);
    $org = casOrg();
    [$project] = casProject($org, $competencies);
    $participant = casParticipant($org, $project, 'in_corso');
    /** @var list<string> $codes */
    $codes = DB::table('project_competencies as pc')->join('framework_competencies as fc', 'fc.id', '=', 'pc.competency_id')
        ->where('pc.project_id', $project->id)->orderBy('pc.position')->pluck('fc.code')->all();
    $plan = ['competencies' => array_map(fn (string $code, int $i): array => [
        'code' => $code, 'primary_questions' => ["Q{$i}a", "Q{$i}b"], 'follow_up_budget' => 3 + $i,
    ], $codes, array_keys($codes)), 'chars' => 100];

    $owner = aolRow($org, $project, $participant, $codes[0], 'completed', $ref, $plan);
    $owner->forceFill([
        'avatar_template_id' => null, 'llm_model_key' => 'claude-x', 'llm_binding_status' => 'applied',
        'system_prompt_chars' => 4321, 'conversation_prompt_version' => 'v7+s3.abcdef123456',
    ])->save();
    casInTenant($org, fn () => InterviewSessionLivePeriod::create([
        'interview_session_id' => $owner->id, 'provider_session_ref' => $ref,
        'started_at' => now()->subMinutes(5), 'ended_at' => now()->subMinute(), 'closed_reason' => 'end',
    ]));

    return compact('org', 'project', 'participant', 'owner', 'codes');
}

/** @param array<string, mixed>|null $plan */
function aolRow(Organization $org, Project $project, Participant $participant, string $code, string $status, ?string $ref, ?array $plan = null, string $provider = 'tavus'): InterviewSession
{
    return casInTenant($org, fn () => InterviewSession::factory()->create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'framework_version_id' => $project->framework_version_id,
        'organization_id' => $org->id,
        'provider' => $provider,
        'status' => $status,
        'provider_session_ref' => $ref,
        'competency_code' => $code,
        'conversation_plan' => $plan,
    ]));
}

/** @param array{org: Organization, project: Project, participant: Participant, codes: list<string>} $s */
function aolAdvance(array $s, ?string $id, int $next = 1, ?Participant $as = null, string $provider = 'tavus'): ?InterviewSession
{
    $participant = $as ?? $s['participant'];

    return casInTenant($participant->organization, fn () => app(AdvanceOnLiveConversation::class)->handle(
        $participant,
        $s['project'],
        $provider,
        $id,
        ['competency_code' => $s['codes'][$next], 'question_index' => $next, 'competency_ordinal' => $next + 1, 'total_competencies' => count($s['codes'])],
    ));
}

function aolRowCount(): int
{
    return InterviewSession::withoutGlobalScopes()->count();
}

test('a brand new competency in the plan of an owned live conversation is granted on the same ref', function (): void {
    Http::fake();
    $s = aolScenario();

    $granted = aolAdvance($s, 'conv-1');

    expect($granted)->not->toBeNull()
        ->and($granted->status)->toBe('in_corso')
        ->and($granted->provider_session_ref)->toBe('conv-1')
        ->and($granted->competency_code)->toBe($s['codes'][1])
        ->and($granted->question_index)->toBe(1)
        ->and($granted->participant_id)->toBe($s['participant']->id)
        ->and($granted->primary_questions)->toBe(['Q1a', 'Q1b'])
        ->and($granted->follow_up_budget)->toBe(4)
        ->and($granted->started_at)->not->toBeNull()
        ->and($granted->conversation_plan)->toBeNull();
    Http::assertNothingSent();

    $period = InterviewSessionLivePeriod::withoutGlobalScopes()->where('interview_session_id', $granted->id)->sole();
    expect($period->provider_session_ref)->toBe('conv-1')->and($period->ended_at)->toBeNull();
});

test('all five LLM snapshot columns are copied from the owning row', function (): void {
    $s = aolScenario();
    $s['owner']->forceFill(['avatar_template_id' => AvatarTemplate::create(['name' => 'aol', 'provider' => 'tavus', 'config' => []])->id])->save();

    $granted = aolAdvance($s, 'conv-1');

    expect($granted->avatar_template_id)->toBe($s['owner']->fresh()->avatar_template_id)
        ->and($granted->llm_model_key)->toBe('claude-x')
        ->and($granted->llm_binding_status)->toBe('applied')
        ->and($granted->system_prompt_chars)->toBe(4321)
        ->and($granted->conversation_prompt_version)->toBe('v7+s3.abcdef123456');
});

test('an absent or empty id is not a claim', function (?string $id): void {
    $s = aolScenario();

    expect(aolAdvance($s, $id))->toBeNull()->and(aolRowCount())->toBe(1);
})->with([[null], ['']]);

test('another participant of the same organization cannot claim the conversation', function (): void {
    $s = aolScenario();
    $stranger = casParticipant($s['org'], $s['project'], 'in_corso');

    expect(aolAdvance($s, 'conv-1', 1, $stranger))->toBeNull()->and(aolRowCount())->toBe(1);
});

test('a participant of another organization cannot claim a ref that organization does not own', function (): void {
    $s = aolScenario();
    $other = aolScenario('conv-other');

    // The foreign participant names the first organization's ref.
    expect(aolAdvance($other, 'conv-1'))->toBeNull()->and(aolRowCount())->toBe(2);
});

test('a ref nulled by /suspend is not a conversation any more', function (): void {
    $s = aolScenario();
    $s['owner']->forceFill(['provider_session_ref' => null])->save();

    expect(aolAdvance($s, 'conv-1'))->toBeNull();
});

test('a code outside the stored plan is refused', function (): void {
    $s = aolScenario(competencies: 3);
    $plan = $s['owner']->conversation_plan;
    $plan['competencies'] = array_slice($plan['competencies'], 0, 2);
    $s['owner']->forceFill(['conversation_plan' => $plan])->save();

    expect(aolAdvance($s, 'conv-1', 2))->toBeNull()->and(aolRowCount())->toBe(1);
});

test('a row that already exists for the next code is refused whatever its status', function (string $status): void {
    $s = aolScenario();
    aolRow($s['org'], $s['project'], $s['participant'], $s['codes'][1], $status, null);

    expect(aolAdvance($s, 'conv-1'))->toBeNull()->and(aolRowCount())->toBe(2);
})->with(['pending', 'error', 'in_corso', 'completed']);

test('a closed single-session gate refuses', function (): void {
    $s = aolScenario();
    config(['interview.tavus.single_session' => false]);

    expect(aolAdvance($s, 'conv-1'))->toBeNull();
});

test('a non-Tavus provider refuses', function (): void {
    $s = aolScenario();

    expect(aolAdvance($s, 'conv-1', 1, null, 'heygen'))->toBeNull();
});

test('a conversation the release already ended is refused', function (): void {
    $s = aolScenario();
    casInTenant($s['org'], fn () => InterviewSession::where('provider_session_ref', 'conv-1')->update(['provider_released_at' => now()]));

    expect(aolAdvance($s, 'conv-1'))->toBeNull()->and(aolRowCount())->toBe(1);
});

test('a conversation another row is live on is refused', function (): void {
    $s = aolScenario();
    aolRow($s['org'], $s['project'], $s['participant'], $s['codes'][2], 'in_corso', 'conv-1');

    expect(aolAdvance($s, 'conv-1'))->toBeNull();
});

test('a participant who is not in progress is refused', function (): void {
    $s = aolScenario();
    $waiting = casParticipant($s['org'], $s['project'], 'in_attesa');

    expect(aolAdvance($s, 'conv-1', 1, $waiting))->toBeNull();
});

test('a failing transaction rolls everything back and surfaces the failure', function (): void {
    $s = aolScenario();
    // The period is the last write of the transaction: failing it proves the row insert rolls back.
    Event::listen('eloquent.creating: '.InterviewSessionLivePeriod::class, function (): void {
        throw new RuntimeException('boom');
    });

    expect(fn () => aolAdvance($s, 'conv-1'))->toThrow(RuntimeException::class)
        ->and(aolRowCount())->toBe(1);
});
