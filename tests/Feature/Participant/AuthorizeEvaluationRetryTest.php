<?php

declare(strict_types=1);

/**
 * AuthorizeEvaluationRetry (scoring-retry-rt-b, design D4/D5, slice PR1b).
 *
 * The one action that turns a `completato` participant whose Evaluation is
 * `pending` back into an `in_attesa` one, resets the sessions of the INVALID
 * competencies, flags the Evaluation as retried and mints the single-use link.
 * No route reaches it yet (PR3b), so every case drives the action directly.
 *
 * Fixtures come from factories only; the world is built for one organization
 * with the tenant resolver set, the way every tenant-model test does.
 *
 * REQ: Evaluation Retry Authorization Action,
 *      Evaluation Retry Refusal Guards,
 *      Interim Retry Audit Logging
 *      (openspec/changes/scoring-retry-rt-b/specs/participant-sso/spec.md)
 */

use App\Actions\Participant\AuthorizeEvaluationRetry;
use App\Actions\Participant\RetryActor;
use App\Actions\Participant\RetryAuthorization;
use App\Enums\ApiKeyMode;
use App\Enums\EvaluationStatus;
use App\Exceptions\Participant\EvaluationRetryRefusalReason;
use App\Exceptions\Participant\EvaluationRetryRefused;
use App\Exceptions\Sso\EntryLinkRefused;
use App\Exceptions\Sso\EntryLinkUrlNotConfigured;
use App\Models\AuditLog;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Models\User;
use App\Models\Utterance;
use App\Models\WebhookDelivery;
use App\Support\Participant\PlaceholderEmail;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['interview.candidate_app_url' => 'https://candidate.test']);
});

/**
 * One organization with a standard project of `$o['competencies']` competencies,
 * a `completato` participant, a `pending` Evaluation and a completed session
 * (with two utterances) per competency. The first `$o['valid']` competencies
 * hold a valid result, the rest an invalid one.
 *
 * @param  array<string, mixed>  $o
 * @return array{
 *     org: Organization,
 *     project: Project,
 *     participant: Participant,
 *     evaluation: Evaluation|null,
 *     codes: list<string>,
 *     validCodes: list<string>,
 *     invalidCodes: list<string>,
 *     sessions: array<string, InterviewSession>,
 * }
 */
function retryWorld(array $o = []): array
{
    $o += [
        'competencies' => 10,
        'valid' => 8,
        'status' => 'completato',
        'evaluation' => 'pending',
        'retryAttempt' => false,
        'email' => null,
        'mode' => null,
        'projectState' => [],
        'org' => null,
    ];

    $org = $o['org'] ?? Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id, 'status' => 'active', ...$o['projectState']]);

    $codes = [];
    for ($i = 0; $i < $o['competencies']; $i++) {
        $competency = Competency::factory()->create();
        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $competency->id,
            'position' => $i + 1,
        ]);
        $codes[] = (string) $competency->code;
    }

    $participantFactory = Participant::factory()->forProject($project)->withStatus($o['status']);
    $participant = $participantFactory->create($o['email'] === null ? [] : ['email' => $o['email']]);
    if ($o['mode'] !== null) {
        $participant->forceFill(['mode' => $o['mode']])->save();
    }

    $evaluation = null;
    if ($o['evaluation'] !== 'none') {
        $factory = Evaluation::factory();
        $factory = $o['evaluation'] === 'completed' ? $factory->completed() : $factory->pending();
        $evaluation = $factory->create([
            'participant_id' => $participant->id,
            'framework_version_id' => $fv->id,
            'retry_attempt' => $o['retryAttempt'],
        ]);
    }

    $validCodes = array_slice($codes, 0, $o['valid']);
    $invalidCodes = array_slice($codes, $o['valid']);

    $sessions = [];
    foreach ($codes as $code) {
        $session = InterviewSession::factory()->ended()->create([
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'framework_version_id' => $fv->id,
            'competency_code' => $code,
        ]);
        foreach (['interviewer', 'candidate'] as $speaker) {
            Utterance::create([
                'interview_session_id' => $session->id,
                'speaker' => $speaker,
                'text' => "{$speaker} line for {$code}",
                'ts' => now(),
            ]);
        }
        $sessions[$code] = $session;

        if ($evaluation !== null) {
            $result = CompetencyResult::factory();
            $result = in_array($code, $validCodes, true) ? $result->valid() : $result->unscorable();
            $result->create(['evaluation_id' => $evaluation->id, 'competency_code' => $code]);
        }
    }

    return compact('org', 'project', 'participant', 'evaluation', 'codes', 'validCodes', 'invalidCodes', 'sessions');
}

function retryAuthorize(array $world, ?RetryActor $actor = null, ?string $reason = null, ?int $participantId = null, ?int $orgId = null): RetryAuthorization
{
    return app(AuthorizeEvaluationRetry::class)->handle(
        $participantId ?? $world['participant']->id,
        $orgId ?? $world['org']->id,
        $actor ?? RetryActor::user(User::factory()->create(['organization_id' => $world['org']->id])->id),
        $reason,
    );
}

function retryRefusalOf(callable $callable): ?EvaluationRetryRefusalReason
{
    try {
        $callable();
    } catch (EvaluationRetryRefused $e) {
        return $e->reason;
    }

    return null;
}

/**
 * A snapshot of everything the action may write, to prove a refusal wrote nothing.
 *
 * @param  array{participant: Participant, evaluation: Evaluation|null}  $world
 * @return array<string, mixed>
 */
function retrySnapshot(array $world): array
{
    $participantId = $world['participant']->id;

    return [
        'status' => DB::table('participants')->where('id', $participantId)->value('status'),
        'evaluation' => DB::table('evaluations')->where('participant_id', $participantId)->first(),
        'sessions' => DB::table('interview_sessions')->where('participant_id', $participantId)->orderBy('id')->get()->all(),
        'utterances' => DB::table('utterances')->orderBy('id')->get()->all(),
        'results' => DB::table('competency_results')->orderBy('id')->get()->all(),
        'audit' => DB::table('audit_logs')->count(),
        'deliveries' => WebhookDelivery::withoutGlobalScopes()->count(),
    ];
}

/** The claims of a JWT, read straight from its payload segment. */
function retryTokenClaims(string $entryUrl): array
{
    $jwt = Str::afterLast($entryUrl, '/interview/');

    return json_decode((string) base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
}

// ─── 5.1 happy path ──────────────────────────────────────────────────────────

test('authorizing a retry re-opens the participant and resets only the invalid competencies', function (): void {
    $world = retryWorld();
    expect($world['invalidCodes'])->toHaveCount(2)->and($world['validCodes'])->toHaveCount(8);
    $validResultsBefore = DB::table('competency_results')->orderBy('id')->get()->all();

    $before = now();
    $result = retryAuthorize($world, reason: 'provider outage on two answers');

    expect($result)->toBeInstanceOf(RetryAuthorization::class)
        ->and($result->status)->toBe('in_attesa')
        ->and($result->competenciesReset)->toEqualCanonicalizing($world['invalidCodes'])
        ->and($result->entryUrl)->toStartWith('https://candidate.test/')->toContain('/interview/');

    expect($world['participant']->fresh()->status)->toBe('in_attesa');

    foreach ($world['invalidCodes'] as $code) {
        $session = InterviewSession::where('participant_id', $world['participant']->id)->where('competency_code', $code)->firstOrFail();
        expect($session->status)->toBe('pending')
            ->and($session->provider_session_ref)->toBeNull()
            ->and($session->ended_reason)->toBeNull()
            ->and($session->ended_at)->toBeNull()
            ->and($session->utterances()->count())->toBe(0);
    }

    foreach ($world['validCodes'] as $code) {
        $session = InterviewSession::where('participant_id', $world['participant']->id)->where('competency_code', $code)->firstOrFail();
        expect($session->status)->toBe('completed')
            ->and($session->provider_session_ref)->not->toBeNull()
            ->and($session->ended_reason)->toBe('completed')
            ->and($session->ended_at)->not->toBeNull()
            ->and($session->utterances()->count())->toBe(2);
    }

    // Every CompetencyResult row is untouched, valid and invalid alike.
    expect(DB::table('competency_results')->orderBy('id')->get()->all())->toEqual($validResultsBefore);

    $evaluation = $world['evaluation']->fresh();
    expect($evaluation->retry_attempt)->toBeTrue()
        ->and($evaluation->status)->toBe(EvaluationStatus::Pending)
        ->and($evaluation->retry_authorized_at)->not->toBeNull()
        ->and($evaluation->retry_authorized_at->greaterThanOrEqualTo($before->copy()->startOfSecond()))->toBeTrue();
});

test('a competency with no result row at all counts as invalid, and one with no session is not reported', function (): void {
    $world = retryWorld(['competencies' => 3, 'valid' => 1]);
    $noResultCode = $world['invalidCodes'][0];
    $noSessionCode = $world['invalidCodes'][1];

    DB::table('competency_results')->where('competency_code', $noResultCode)->delete();
    $world['sessions'][$noSessionCode]->utterances()->delete();
    $world['sessions'][$noSessionCode]->delete();

    $result = retryAuthorize($world);

    expect($result->competenciesReset)->toBe([$noResultCode]);
    expect(InterviewSession::where('participant_id', $world['participant']->id)->where('competency_code', $noResultCode)->value('status'))->toBe('pending');
    expect(InterviewSession::where('participant_id', $world['participant']->id)->where('competency_code', $world['validCodes'][0])->value('status'))->toBe('completed');
});

test('the link is minted from the participant row and carries no request input', function (): void {
    $world = retryWorld(['email' => 'candidate.row@example.com']);

    $claims = retryTokenClaims(retryAuthorize($world)->entryUrl);

    expect($claims['candidate_ref'])->toBe($world['participant']->candidate_ref)
        ->and($claims['email'])->toBe('candidate.row@example.com')
        ->and($claims['display_name'])->toBe($world['participant']->display_name)
        ->and($claims['project_id'])->toBe($world['project']->id)
        ->and($claims['org_id'])->toBe($world['org']->id)
        ->and($claims['role_code'])->toBe($world['participant']->role_code)
        ->and($claims['lang'])->toBe($world['participant']->language)
        ->and($claims)->not->toHaveKeys(['external_id', 'source']);
});

// ─── 5.2 / 5.3 refusals ──────────────────────────────────────────────────────

test('a consumed retry is refused as retry_already_consumed without writing anything', function (): void {
    $world = retryWorld(['retryAttempt' => true]);
    $before = retrySnapshot($world);

    expect(retryRefusalOf(fn () => retryAuthorize($world)))->toBe(EvaluationRetryRefusalReason::RetryAlreadyConsumed);
    expect(retrySnapshot($world))->toEqual($before);
});

test('a participant that is not completato is refused as not_completed without writing anything', function (string $status): void {
    $world = retryWorld(['status' => $status]);
    $before = retrySnapshot($world);

    expect(retryRefusalOf(fn () => retryAuthorize($world)))->toBe(EvaluationRetryRefusalReason::NotCompleted);
    expect(retrySnapshot($world))->toEqual($before);
})->with(['in_attesa', 'in_corso', 'in_valutazione', 'errore']);

test('a test-mode participant is refused as test_mode_participant without writing anything', function (): void {
    $world = retryWorld(['mode' => ApiKeyMode::Test]);
    $before = retrySnapshot($world);

    expect(retryRefusalOf(fn () => retryAuthorize($world)))->toBe(EvaluationRetryRefusalReason::TestModeParticipant);
    expect(retrySnapshot($world))->toEqual($before);
});

test('an evaluation that is not pending is refused as evaluation_not_pending without writing anything', function (string $evaluation): void {
    $world = retryWorld(['evaluation' => $evaluation]);
    $before = retrySnapshot($world);

    expect(retryRefusalOf(fn () => retryAuthorize($world)))->toBe(EvaluationRetryRefusalReason::EvaluationNotPending);
    expect(retrySnapshot($world))->toEqual($before);
})->with(['completed', 'none']);

test('an inaccessible project is refused as project_inaccessible without writing anything', function (string $case): void {
    $state = match ($case) {
        'closed' => ['status' => 'archived'],
        'not yet live' => ['goes_live_at' => now()->addDay()],
        'past deadline' => ['deadline_at' => now()->subDay()],
        'soft deleted' => [],
    };
    $world = retryWorld();
    $world['project']->forceFill($state)->save();
    if ($case === 'soft deleted') {
        $world['project']->delete();
    }
    $before = retrySnapshot($world);

    expect(retryRefusalOf(fn () => retryAuthorize($world)))->toBe(EvaluationRetryRefusalReason::ProjectInaccessible);
    expect(retrySnapshot($world))->toEqual($before);
})->with(['closed', 'not yet live', 'past deadline', 'soft deleted']);

test('a project refusal is decided by the guard, before any write statement runs', function (): void {
    $world = retryWorld(['projectState' => ['status' => 'archived']]);
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $actor = RetryActor::apiClient(1);
    expect(retryRefusalOf(fn () => retryAuthorize($world, $actor)))->toBe(EvaluationRetryRefusalReason::ProjectInaccessible);
    expect($writes)->toBe([]);
});

test('the refusal order is consumed, not completed, test mode, not pending, project inaccessible', function (): void {
    // Consumed AND not completato: a mid-retry participant is in_attesa, yet the answer is "consumed".
    $consumed = retryWorld(['retryAttempt' => true, 'status' => 'in_attesa']);
    expect(retryRefusalOf(fn () => retryAuthorize($consumed)))->toBe(EvaluationRetryRefusalReason::RetryAlreadyConsumed);

    // Not completed AND test mode AND no evaluation: not_completed wins.
    $notCompleted = retryWorld(['status' => 'in_corso', 'mode' => ApiKeyMode::Test, 'evaluation' => 'none']);
    expect(retryRefusalOf(fn () => retryAuthorize($notCompleted)))->toBe(EvaluationRetryRefusalReason::NotCompleted);

    // Test mode AND completed evaluation: test mode wins over not pending.
    $testMode = retryWorld(['mode' => ApiKeyMode::Test, 'evaluation' => 'completed']);
    expect(retryRefusalOf(fn () => retryAuthorize($testMode)))->toBe(EvaluationRetryRefusalReason::TestModeParticipant);

    // Not pending AND archived project: not pending wins over project inaccessible.
    $notPending = retryWorld(['evaluation' => 'completed', 'projectState' => ['status' => 'archived']]);
    expect(retryRefusalOf(fn () => retryAuthorize($notPending)))->toBe(EvaluationRetryRefusalReason::EvaluationNotPending);
});

// ─── 5.4 atomicity ───────────────────────────────────────────────────────────

test('a gate refusal raised by the mint after the flip rolls the whole authorization back', function (): void {
    // The deadline is an hour away, so the guard passes ...
    $world = retryWorld(['projectState' => ['deadline_at' => now()->addHour()]]);
    $before = retrySnapshot($world);

    // ... and the clock jumps past it right after the participant flip, before the mint.
    Participant::updated(function (): void {
        $this->travelTo(now()->addHours(2));
    });

    expect(retryRefusalOf(fn () => retryAuthorize($world)))->toBe(EvaluationRetryRefusalReason::ProjectInaccessible);

    $this->travelBack();
    expect(retrySnapshot($world))->toEqual($before);
    expect($world['participant']->fresh()->status)->toBe('completato')
        ->and($world['evaluation']->fresh()->retry_attempt)->toBeFalse()
        ->and($world['evaluation']->fresh()->retry_authorized_at)->toBeNull();
    expect(AuditLog::withoutGlobalScopes()->where('action', 'evaluation.retry_authorized')->count())->toBe(0);
});

test('any other failure inside the transaction rolls back too and is not disguised as a refusal', function (): void {
    $world = retryWorld();
    $before = retrySnapshot($world);

    config(['interview.candidate_app_url' => '']);

    expect(fn () => retryAuthorize($world))->toThrow(EntryLinkUrlNotConfigured::class);
    expect(retrySnapshot($world))->toEqual($before);
});

test('a mint refusal other than the entry gates is a defect signal: rolled back, not disguised as a refusal', function (): void {
    $world = retryWorld();
    // A row whose role is not the project's role cannot be minted for: the minter refuses on role_code.
    DB::table('participants')->where('id', $world['participant']->id)->update(['role_code' => 'FLL']);
    $before = retrySnapshot($world);

    expect(fn () => retryAuthorize($world, RetryActor::apiClient(1)))->toThrow(EntryLinkRefused::class);

    expect(retrySnapshot($world))->toEqual($before);
    expect($world['evaluation']->fresh()->retry_attempt)->toBeFalse();
});

// ─── 5.5 tenancy ─────────────────────────────────────────────────────────────

test('a participant of another organization is not found and nothing is written', function (): void {
    $other = retryWorld();
    $mine = retryWorld();
    $before = retrySnapshot($other);

    expect(fn () => retryAuthorize($mine, participantId: $other['participant']->id))->toThrow(ModelNotFoundException::class);
    expect(retrySnapshot($other))->toEqual($before);
    expect($other['participant']->fresh()->status)->toBe('completato');
});

test('the action runs under the target organization whatever the ambient tenant context is', function (): void {
    $world = retryWorld();
    $other = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($other->id);
    $resolver->setBypass(true);

    $result = retryAuthorize($world);

    // The scoped reads saw the target organization, not the ambient one ...
    expect($result->competenciesReset)->toEqualCanonicalizing($world['invalidCodes']);
    // ... and the ambient context is restored for the caller.
    expect($resolver->getOrgId())->toBe($other->id)->and($resolver->isBypass())->toBeTrue();
});

test('two participants of two organizations never leak into each other', function (): void {
    $a = retryWorld();
    $b = retryWorld();

    $result = retryAuthorize($a);

    expect($result->competenciesReset)->toEqualCanonicalizing($a['invalidCodes']);
    expect($b['participant']->fresh()->status)->toBe('completato')
        ->and($b['evaluation']->fresh()->retry_attempt)->toBeFalse();
    expect(DB::table('interview_sessions')->where('participant_id', $b['participant']->id)->where('status', 'pending')->count())->toBe(0);
});

// ─── 5.6 concurrency ─────────────────────────────────────────────────────────

test('a second authorization after the first commit is refused and mints no second link', function (): void {
    $world = retryWorld();
    $first = retryAuthorize($world);
    $authorizedAt = $world['evaluation']->fresh()->retry_authorized_at;
    $before = retrySnapshot($world);

    $second = null;
    $reason = retryRefusalOf(function () use ($world, &$second): void {
        $second = retryAuthorize($world);
    });

    expect($reason)->toBe(EvaluationRetryRefusalReason::RetryAlreadyConsumed);
    expect($second)->toBeNull();
    expect(retrySnapshot($world))->toEqual($before);
    expect($world['evaluation']->fresh()->retry_authorized_at->equalTo($authorizedAt))->toBeTrue();
    expect($first->competenciesReset)->not->toBeEmpty();
});

test('the participant row is selected FOR UPDATE inside the transaction', function (): void {
    $world = retryWorld();
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    retryAuthorize($world);

    $locked = array_filter($statements, fn (string $sql): bool => str_contains($sql, 'from "participants"') && str_contains($sql, 'for update'));
    expect($locked)->not->toBeEmpty();
});

// ─── 5.7 interim log line ────────────────────────────────────────────────────

test('a success emits the interim participant.retry_authorized line with no contact data, link or token', function (): void {
    $world = retryWorld();
    Log::spy();
    $user = User::factory()->create(['organization_id' => $world['org']->id]);

    $result = retryAuthorize($world, RetryActor::user($user->id), 'candidate lost connection');

    $captured = null;
    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) use (&$captured): bool {
        if (str_contains($message, 'participant.retry_authorized')) {
            $captured = ['message' => $message, 'context' => $context];

            return true;
        }

        return false;
    })->once();

    expect($captured['message'])->toContain('INTERIM');
    $context = $captured['context'];
    expect($context)->toMatchArray([
        'actor_type' => 'user',
        'actor_user_id' => $user->id,
        'actor_api_client_id' => null,
        'participant_id' => $world['participant']->id,
        'organization_id' => $world['org']->id,
        'project_id' => $world['project']->id,
        'previous_status' => 'completato',
        'new_status' => 'in_attesa',
        'reason' => 'candidate lost connection',
        'email_queued' => false,
    ]);
    expect($context['competencies_reset'])->toEqualCanonicalizing($world['invalidCodes']);
    expect(Carbon::parse($context['at'])->isValid())->toBeTrue();

    $serialized = json_encode($captured, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    foreach ([
        $world['participant']->email,
        $world['participant']->display_name,
        $world['participant']->candidate_ref,
        $result->entryUrl,
        Str::afterLast($result->entryUrl, '/interview/'),
    ] as $secret) {
        expect(str_contains($serialized, (string) $secret))->toBeFalse("The log line leaks `{$secret}`.");
    }
});

test('the log line records an M2M actor and a null reason', function (): void {
    $world = retryWorld();
    Log::spy();

    retryAuthorize($world, RetryActor::apiClient(77));

    $captured = null;
    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) use (&$captured): bool {
        if (str_contains($message, 'participant.retry_authorized')) {
            $captured = $context;

            return true;
        }

        return false;
    })->once();

    expect($captured)->toMatchArray(['actor_type' => 'api_client', 'actor_user_id' => null, 'actor_api_client_id' => 77, 'reason' => null]);
});

test('a refusal emits no participant.retry_authorized line', function (): void {
    $world = retryWorld(['retryAttempt' => true]);
    Log::spy();

    retryRefusalOf(fn () => retryAuthorize($world));

    Log::shouldNotHaveReceived('info', [Mockery::on(fn ($message): bool => is_string($message) && str_contains($message, 'participant.retry_authorized')), Mockery::any()]);
});

// ─── 5.8 audit row ───────────────────────────────────────────────────────────

test('a success writes exactly one audit row naming the evaluation, the actor and the reset competencies', function (): void {
    $world = retryWorld();
    $user = User::factory()->create(['organization_id' => $world['org']->id]);
    $this->actingAs($user);

    $result = retryAuthorize($world, RetryActor::user($user->id), 'operator note');

    $rows = AuditLog::withoutGlobalScopes()->where('action', 'evaluation.retry_authorized')->get();
    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    expect($row->organization_id)->toBe($world['org']->id)
        ->and($row->actor_id)->toBe($user->id)
        ->and($row->subject_type)->toBe('evaluation')
        ->and($row->subject_id)->toBe($world['evaluation']->id)
        ->and($row->before)->toBeNull()
        ->and($row->after)->toMatchArray([
            'participant_id' => $world['participant']->id,
            'evaluation_id' => $world['evaluation']->id,
            'reason' => 'operator note',
            'actor_type' => 'user',
            'actor_user_id' => $user->id,
            'actor_api_client_id' => null,
        ]);
    expect($row->after['competencies_reset'])->toEqualCanonicalizing($world['invalidCodes']);

    $serialized = json_encode($row->after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    foreach ([$world['participant']->email, $world['participant']->display_name, $world['participant']->candidate_ref, $result->entryUrl] as $secret) {
        expect(str_contains($serialized, (string) $secret))->toBeFalse("The audit row leaks `{$secret}`.");
    }
});

test('an M2M authorization audit row has no actor_id and names the client in the payload', function (): void {
    $world = retryWorld();

    retryAuthorize($world, RetryActor::apiClient(31), null);

    $row = AuditLog::withoutGlobalScopes()->where('action', 'evaluation.retry_authorized')->sole();
    expect($row->actor_id)->toBeNull()
        ->and($row->after)->toMatchArray(['actor_type' => 'api_client', 'actor_user_id' => null, 'actor_api_client_id' => 31, 'reason' => null]);
});

test('a refusal writes no audit row', function (): void {
    $world = retryWorld(['status' => 'in_corso']);

    retryRefusalOf(fn () => retryAuthorize($world));

    expect(AuditLog::withoutGlobalScopes()->where('action', 'evaluation.retry_authorized')->count())->toBe(0);
});

test('the audit row is written after the transaction commits, never inside it', function (): void {
    $world = retryWorld();
    $levelAtAudit = null;
    $baseLevel = DB::transactionLevel();
    DB::listen(function ($query) use (&$levelAtAudit): void {
        if (str_contains(strtolower($query->sql), 'insert into "audit_logs"')) {
            $levelAtAudit = DB::transactionLevel();
        }
    });

    retryAuthorize($world);

    // RefreshDatabase wraps the test in one transaction: the action's own level is back to it.
    expect($levelAtAudit)->toBe($baseLevel);
});

test('a user actor without an authorizer id is refused before any write and leaves no audit row', function (): void {
    $world = retryWorld();
    $before = retrySnapshot($world);

    expect(fn () => retryAuthorize($world, RetryActor::user(null)))->toThrow(InvalidArgumentException::class);

    expect(retrySnapshot($world))->toEqual($before);
    expect(AuditLog::withoutGlobalScopes()->where('action', 'evaluation.retry_authorized')->count())->toBe(0);
});

// ─── 5.10 link lifetime (owner resolution I9) ────────────────────────────────

test('the retry link lives 24 hours only when BEAI can email it', function (): void {
    $this->travelTo(Carbon::parse('2026-10-06 09:00:00'));
    $world = retryWorld();

    $result = retryAuthorize($world);
    $claims = retryTokenClaims($result->entryUrl);

    expect($claims['exp'] - $claims['iat'])->toBe(1440 * 60)
        ->and($result->expiresAt->getTimestamp())->toBe($claims['exp']);
});

test('the retry link for a placeholder address stays at 30 minutes', function (): void {
    $this->travelTo(Carbon::parse('2026-10-06 09:00:00'));
    $world = retryWorld(['email' => PlaceholderEmail::for('legacy-candidate')]);

    $result = retryAuthorize($world);
    $claims = retryTokenClaims($result->entryUrl);

    expect($claims['exp'] - $claims['iat'])->toBe(30 * 60)
        ->and($result->expiresAt->getTimestamp())->toBe($claims['exp'])
        ->and($result->emailSent)->toBeFalse();
});

test('the retry link for a purged address stays at 30 minutes', function (): void {
    $this->travelTo(Carbon::parse('2026-10-06 09:00:00'));
    $world = retryWorld();
    $world['participant']->forceFill(['email' => PlaceholderEmail::forPurged($world['participant']->candidate_ref)])->save();

    $claims = retryTokenClaims(retryAuthorize($world)->entryUrl);

    expect($claims['exp'] - $claims['iat'])->toBe(30 * 60);
});

test('the retry link for a reusable-link visitor stays at 30 minutes', function (): void {
    $this->travelTo(Carbon::parse('2026-10-06 09:00:00'));
    $world = retryWorld();
    $link = ReusableInterviewLink::factory()->forProject($world['project'])->create();
    $world['participant']->forceFill(['reusable_interview_link_id' => $link->id])->save();

    $result = retryAuthorize($world);
    $claims = retryTokenClaims($result->entryUrl);

    expect($claims['exp'] - $claims['iat'])->toBe(30 * 60)
        ->and($result->emailSent)->toBeFalse();
});

test('the emailed-link lifetime follows the configured value', function (): void {
    $this->travelTo(Carbon::parse('2026-10-06 09:00:00'));
    config(['candidate_invitations.emailed_link_ttl_minutes' => 720]);
    $world = retryWorld();

    $claims = retryTokenClaims(retryAuthorize($world)->entryUrl);

    expect($claims['exp'] - $claims['iat'])->toBe(720 * 60);
});

test('no webhook delivery is created by an authorization', function (): void {
    $world = retryWorld();
    $before = WebhookDelivery::withoutGlobalScopes()->count();

    retryAuthorize($world);

    expect(WebhookDelivery::withoutGlobalScopes()->count())->toBe($before);
});

test('a failure while writing the log line after the commit neither undoes nor disguises the authorization', function (): void {
    $world = retryWorld();
    $failed = false;
    Log::listen(function ($message) use (&$failed): void {
        if (! $failed && str_contains((string) $message->message, 'participant.retry_authorized')) {
            $failed = true;

            throw new RuntimeException('log sink down');
        }
    });

    $result = retryAuthorize($world, reason: 'log sink down after commit');

    // The retry is committed: the caller must learn it, not see an error while the participant is already re-opened.
    expect($result)->toBeInstanceOf(RetryAuthorization::class)
        ->and($result->status)->toBe('in_attesa')
        ->and($world['participant']->fresh()->status)->toBe('in_attesa')
        ->and(Evaluation::withoutGlobalScopes()->find($world['evaluation']->id)->retry_attempt)->toBeTrue()
        ->and($failed)->toBeTrue();
});
