<?php

declare(strict_types=1);

/**
 * The two evaluation-retry HTTP surfaces (scoring-retry-rt-b, slice PR3b).
 *
 * `POST /api/participants/{id}/retry` (backoffice, admin and operator) and
 * `POST /api/m2m/participants/{id}/retry` (M2M, ability `participants:retry`)
 * are thin controllers over ONE action, `AuthorizeEvaluationRetry`. This file
 * pins what is specific to the HTTP layer: who may call, in which failure order
 * (403 before 404), which organization the participant is resolved in, how each
 * refusal maps to a status and a machine code, the response shape, the bounded
 * `reason`, and that the link never reaches a log line or an audit row.
 *
 * The decision logic itself is covered by AuthorizeEvaluationRetryTest.
 *
 * REQ: Evaluation Retry Authorization Action, Evaluation Retry Refusal Guards,
 *      Interim Retry Audit Logging
 *      (openspec/changes/scoring-retry-rt-b/specs/participant-sso/spec.md)
 *      M2M Evaluation Retry Endpoint
 *      (openspec/changes/scoring-retry-rt-b/specs/m2m-auth/spec.md)
 */

use App\Enums\ApiKeyMode;
use App\Exceptions\Participant\EvaluationRetryRefusalReason;
use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Services\ApiKeyGenerator;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['interview.candidate_app_url' => 'https://candidate.test']);
    // The mail is covered by RetryEmailTest; here it must simply not be sent.
    Queue::fake();
});

/**
 * An organization holding a `completato` participant with a `pending`
 * Evaluation: one valid and one invalid competency, the invalid one with an
 * ended session. Overrides select the non-eligible shapes.
 *
 * @param  array<string, mixed>  $o
 * @return array{org: Organization, project: Project, participant: Participant, evaluation: Evaluation|null, invalidCode: string}
 */
function retryEpWorld(array $o = []): array
{
    $o += ['status' => 'completato', 'evaluation' => 'pending', 'retryAttempt' => false, 'mode' => null, 'projectState' => [], 'org' => null];

    $org = $o['org'] ?? Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id, 'status' => 'active', ...$o['projectState']]);

    $codes = [];
    foreach ([1, 2] as $position) {
        $competency = Competency::factory()->create();
        DB::table('project_competencies')->insert(['project_id' => $project->id, 'competency_id' => $competency->id, 'position' => $position]);
        $codes[] = (string) $competency->code;
    }

    $participant = Participant::factory()->forProject($project)->withStatus($o['status'])->create();
    if ($o['mode'] !== null) {
        $participant->forceFill(['mode' => $o['mode']])->save();
    }

    $evaluation = null;
    if ($o['evaluation'] !== 'none') {
        $factory = $o['evaluation'] === 'completed' ? Evaluation::factory()->completed() : Evaluation::factory()->pending();
        $evaluation = $factory->create([
            'participant_id' => $participant->id,
            'framework_version_id' => $fv->id,
            'retry_attempt' => $o['retryAttempt'],
        ]);
        CompetencyResult::factory()->valid()->create(['evaluation_id' => $evaluation->id, 'competency_code' => $codes[0]]);
        CompetencyResult::factory()->unscorable()->create(['evaluation_id' => $evaluation->id, 'competency_code' => $codes[1]]);
    }

    InterviewSession::factory()->ended()->create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'framework_version_id' => $fv->id,
        'competency_code' => $codes[1],
    ]);

    return ['org' => $org, 'project' => $project, 'participant' => $participant, 'evaluation' => $evaluation, 'invalidCode' => $codes[1]];
}

/** A JWT for a user of `$org` holding `$role`. */
function retryEpToken(Organization $org, string $role): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]));

    return auth('api')->login($user);
}

/** @param  list<string>  $abilities */
function retryEpKey(Organization $org, array $abilities = ['participants:retry']): string
{
    $rawKey = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id, 'is_active' => true, 'abilities' => $abilities]);

    return $rawKey;
}

/** What a rejected call must not have changed. */
function retryEpSnapshot(array $world): array
{
    return [
        'status' => Participant::withoutGlobalScopes()->find($world['participant']->id)->status,
        'evaluation' => $world['evaluation'] === null ? null : Evaluation::withoutGlobalScopes()->find($world['evaluation']->id)->only(['status', 'retry_attempt', 'retry_authorized_at']),
        'sessions' => InterviewSession::withoutGlobalScopes()->where('participant_id', $world['participant']->id)->orderBy('id')->get(['id', 'ended_at', 'ended_reason'])->toArray(),
        'audit' => DB::table('audit_logs')->where('action', 'evaluation.retry_authorized')->count(),
    ];
}

// ─── operator surface: who may call ──────────────────────────────────────────

test('an admin authorizes the retry and gets the documented response', function (): void {
    $world = retryEpWorld();

    $response = $this->withToken(retryEpToken($world['org'], 'admin'))
        ->postJson("/api/participants/{$world['participant']->id}/retry");

    $response->assertOk()->assertJsonStructure(['status', 'entry_url', 'expires_at', 'email_sent', 'competencies_reset']);
    expect($response->json('status'))->toBe('in_attesa')
        ->and($response->json('entry_url'))->toStartWith('https://candidate.test')
        ->and($response->json('competencies_reset'))->toBe([$world['invalidCode']])
        ->and($response->json('email_sent'))->toBeTrue()
        ->and($response->json('expires_at'))->toBeString();

    expect(Participant::withoutGlobalScopes()->find($world['participant']->id)->status)->toBe('in_attesa')
        ->and(Evaluation::withoutGlobalScopes()->find($world['evaluation']->id)->retry_attempt)->toBeTrue();
});

test('an operator may authorize the retry', function (): void {
    $world = retryEpWorld();

    $this->withToken(retryEpToken($world['org'], 'operator'))
        ->postJson("/api/participants/{$world['participant']->id}/retry")
        ->assertOk();
});

test('a viewer is refused with 403 and nothing is written', function (): void {
    $world = retryEpWorld();
    $before = retryEpSnapshot($world);

    $this->withToken(retryEpToken($world['org'], 'viewer'))
        ->postJson("/api/participants/{$world['participant']->id}/retry")
        ->assertForbidden();

    expect(retryEpSnapshot($world))->toEqual($before);
});

test('a viewer gets 403, never 404, even for an id that does not exist or is foreign', function (): void {
    $mine = retryEpWorld();
    $foreign = retryEpWorld();
    $token = retryEpToken($mine['org'], 'viewer');

    $this->withToken($token)->postJson("/api/participants/{$foreign['participant']->id}/retry")->assertForbidden();
    $this->withToken($token)->postJson('/api/participants/999999999/retry')->assertForbidden();
});

test('an unauthenticated caller gets 401', function (): void {
    $world = retryEpWorld();

    $this->postJson("/api/participants/{$world['participant']->id}/retry")->assertUnauthorized();
});

test('an operator cannot reach a participant of another organization and nothing is written', function (): void {
    $mine = retryEpWorld();
    $foreign = retryEpWorld();
    $before = retryEpSnapshot($foreign);

    $this->withToken(retryEpToken($mine['org'], 'admin'))
        ->postJson("/api/participants/{$foreign['participant']->id}/retry")
        ->assertNotFound();

    expect(retryEpSnapshot($foreign))->toEqual($before);
});

test('a superadmin with no organization in scope gets 404 and nothing is written', function (): void {
    // Platform staff acting for no client has no tenant to authorize inside: the route must not guess one.
    $w = retryEpWorld();
    $before = retryEpSnapshot($w);
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)
        ->postJson("/api/participants/{$w['participant']->id}/retry")
        ->assertNotFound();

    expect(retryEpSnapshot($w))->toEqual($before);
});

test('an unknown id answers 404 exactly like a foreign one', function (): void {
    $mine = retryEpWorld();
    $foreign = retryEpWorld();
    $token = retryEpToken($mine['org'], 'admin');

    $foreignBody = $this->withToken($token)->postJson("/api/participants/{$foreign['participant']->id}/retry");
    $unknownBody = $this->withToken($token)->postJson('/api/participants/999999999/retry');

    $foreignBody->assertNotFound();
    $unknownBody->assertNotFound();
    // The message only echoes the id the caller sent; nothing tells the two cases apart.
    $normalize = fn (string $message, int|string $id): string => str_replace((string) $id, '{id}', $message);
    expect($normalize($foreignBody->json('message'), $foreign['participant']->id))
        ->toBe($normalize($unknownBody->json('message'), 999999999));
});

// ─── M2M surface: who may call ───────────────────────────────────────────────

test('an M2M client with the ability authorizes the retry and gets the documented response', function (): void {
    $world = retryEpWorld();

    $response = $this->withToken(retryEpKey($world['org']))
        ->postJson("/api/m2m/participants/{$world['participant']->id}/retry");

    $response->assertOk()->assertJsonStructure(['status', 'entry_url', 'expires_at', 'email_sent', 'competencies_reset']);
    expect($response->json('status'))->toBe('in_attesa')
        ->and($response->json('competencies_reset'))->toBe([$world['invalidCode']])
        ->and(Participant::withoutGlobalScopes()->find($world['participant']->id)->status)->toBe('in_attesa');
});

test('an M2M client without the ability gets 403 and nothing is written', function (): void {
    $world = retryEpWorld();
    $before = retryEpSnapshot($world);

    $this->withToken(retryEpKey($world['org'], ['participants:read', 'participants:create']))
        ->postJson("/api/m2m/participants/{$world['participant']->id}/retry")
        ->assertForbidden();

    expect(retryEpSnapshot($world))->toEqual($before);
});

test('an M2M client without the ability gets 403, never 404, for a foreign or unknown id', function (): void {
    $mine = retryEpWorld();
    $foreign = retryEpWorld();
    $key = retryEpKey($mine['org'], ['participants:read']);

    $this->withToken($key)->postJson("/api/m2m/participants/{$foreign['participant']->id}/retry")->assertForbidden();
    $this->withToken($key)->postJson('/api/m2m/participants/999999999/retry')->assertForbidden();
});

test('an M2M call without a key gets 401', function (): void {
    $world = retryEpWorld();

    $this->postJson("/api/m2m/participants/{$world['participant']->id}/retry")->assertUnauthorized();
});

test('an M2M client cannot reach a participant of another organization and nothing is written', function (): void {
    $mine = retryEpWorld();
    $foreign = retryEpWorld();
    $before = retryEpSnapshot($foreign);

    $this->withToken(retryEpKey($mine['org']))
        ->postJson("/api/m2m/participants/{$foreign['participant']->id}/retry")
        ->assertNotFound();

    expect(retryEpSnapshot($foreign))->toEqual($before);
});

test('a backoffice user JWT is not accepted on the M2M route', function (): void {
    $world = retryEpWorld();

    $this->withToken(retryEpToken($world['org'], 'admin'))
        ->postJson("/api/m2m/participants/{$world['participant']->id}/retry")
        ->assertUnauthorized();
});

// ─── refusals map to 409 with the machine code, on both surfaces ─────────────

dataset('retryEpRefusals', [
    'retry_already_consumed' => [['retryAttempt' => true], EvaluationRetryRefusalReason::RetryAlreadyConsumed],
    'not_completed' => [['status' => 'in_corso'], EvaluationRetryRefusalReason::NotCompleted],
    'test_mode_participant' => [['mode' => ApiKeyMode::Test], EvaluationRetryRefusalReason::TestModeParticipant],
    'evaluation_not_pending' => [['evaluation' => 'completed'], EvaluationRetryRefusalReason::EvaluationNotPending],
    'project_inaccessible' => [['projectState' => ['status' => 'archived']], EvaluationRetryRefusalReason::ProjectInaccessible],
]);

test('the operator surface maps each refusal to 409 with its machine code and writes nothing', function (array $shape, EvaluationRetryRefusalReason $reason): void {
    $world = retryEpWorld($shape);
    $before = retryEpSnapshot($world);

    $this->withToken(retryEpToken($world['org'], 'admin'))
        ->postJson("/api/participants/{$world['participant']->id}/retry")
        ->assertStatus(409)
        ->assertExactJson(['reason' => $reason->value]);

    expect(retryEpSnapshot($world))->toEqual($before);
})->with('retryEpRefusals');

test('the M2M surface maps each refusal to 409 with its machine code and writes nothing', function (array $shape, EvaluationRetryRefusalReason $reason): void {
    $world = retryEpWorld($shape);
    $before = retryEpSnapshot($world);

    $this->withToken(retryEpKey($world['org']))
        ->postJson("/api/m2m/participants/{$world['participant']->id}/retry")
        ->assertStatus(409)
        ->assertExactJson(['reason' => $reason->value]);

    expect(retryEpSnapshot($world))->toEqual($before);
})->with('retryEpRefusals');

test('a participant with no evaluation at all is refused as evaluation_not_pending', function (): void {
    $world = retryEpWorld(['evaluation' => 'none']);

    $this->withToken(retryEpToken($world['org'], 'admin'))
        ->postJson("/api/participants/{$world['participant']->id}/retry")
        ->assertStatus(409)
        ->assertExactJson(['reason' => 'evaluation_not_pending']);
});

// ─── the optional, bounded reason ────────────────────────────────────────────

test('a reason of 501 characters is a 422 on both surfaces and nothing is written', function (): void {
    $world = retryEpWorld();
    $before = retryEpSnapshot($world);
    $tooLong = str_repeat('a', 501);

    $this->withToken(retryEpToken($world['org'], 'admin'))
        ->postJson("/api/participants/{$world['participant']->id}/retry", ['reason' => $tooLong])
        ->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->withToken(retryEpKey($world['org']))
        ->postJson("/api/m2m/participants/{$world['participant']->id}/retry", ['reason' => $tooLong])
        ->assertStatus(422)->assertJsonValidationErrors('reason');

    expect(retryEpSnapshot($world))->toEqual($before);
});

test('a reason of exactly 500 characters is accepted', function (): void {
    $world = retryEpWorld();

    $this->withToken(retryEpToken($world['org'], 'admin'))
        ->postJson("/api/participants/{$world['participant']->id}/retry", ['reason' => str_repeat('a', 500)])
        ->assertOk();
});

test('a reason must be a string', function (): void {
    $world = retryEpWorld();

    $this->withToken(retryEpToken($world['org'], 'admin'))
        ->postJson("/api/participants/{$world['participant']->id}/retry", ['reason' => ['nested']])
        ->assertStatus(422)->assertJsonValidationErrors('reason');
});

test('the reason is trimmed before it is recorded and an absent one is recorded as null', function (): void {
    $withReason = retryEpWorld();
    $withoutReason = retryEpWorld(['org' => $withReason['org']]);
    $token = retryEpToken($withReason['org'], 'admin');

    $this->withToken($token)->postJson("/api/participants/{$withReason['participant']->id}/retry", ['reason' => "  customer asked \n"])->assertOk();
    $this->withToken($token)->postJson("/api/participants/{$withoutReason['participant']->id}/retry")->assertOk();

    $reasons = AuditLog::withoutGlobalScopes()->where('action', 'evaluation.retry_authorized')->orderBy('id')->get()->map(fn ($r) => $r->after['reason'])->all();
    expect($reasons)->toBe(['customer asked', null]);
});

// ─── actor, audit, log: the right actor, never the link ──────────────────────

test('the operator surface records the authenticated user as the actor', function (): void {
    $world = retryEpWorld();
    $user = User::factory()->create(['organization_id' => $world['org']->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($world['org']->id);
    $user->assignRole(SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $world['org']->id]));

    $this->withToken(auth('api')->login($user))->postJson("/api/participants/{$world['participant']->id}/retry")->assertOk();

    $row = AuditLog::withoutGlobalScopes()->where('action', 'evaluation.retry_authorized')->sole();
    expect($row->organization_id)->toBe($world['org']->id)
        ->and($row->after)->toMatchArray(['actor_type' => 'user', 'actor_user_id' => $user->id, 'actor_api_client_id' => null]);
});

test('the M2M surface records the client as the actor', function (): void {
    $world = retryEpWorld();
    $rawKey = ApiKeyGenerator::generate();
    $client = ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $world['org']->id, 'is_active' => true, 'abilities' => ['participants:retry']]);

    $this->withToken($rawKey)->postJson("/api/m2m/participants/{$world['participant']->id}/retry")->assertOk();

    $row = AuditLog::withoutGlobalScopes()->where('action', 'evaluation.retry_authorized')->sole();
    expect($row->actor_id)->toBeNull()
        ->and($row->after)->toMatchArray(['actor_type' => 'api_client', 'actor_user_id' => null, 'actor_api_client_id' => $client->id]);
});

test('the organization comes from the credential, never from the request body', function (): void {
    $mine = retryEpWorld();
    $foreign = retryEpWorld();

    $this->withToken(retryEpKey($mine['org']))
        ->postJson("/api/m2m/participants/{$foreign['participant']->id}/retry", ['organization_id' => $foreign['org']->id])
        ->assertNotFound();
    $this->withToken(retryEpToken($mine['org'], 'admin'))
        ->postJson("/api/participants/{$foreign['participant']->id}/retry", ['organization_id' => $foreign['org']->id])
        ->assertNotFound();

    expect(Participant::withoutGlobalScopes()->find($foreign['participant']->id)->status)->toBe('completato');
});

test('the entry link is returned to the caller and written to no log line and no audit row', function (string $surface): void {
    $world = retryEpWorld();
    $lines = [];
    Log::listen(function (MessageLogged $event) use (&$lines): void {
        $lines[] = [$event->message, $event->context];
    });

    $response = $surface === 'operator'
        ? $this->withToken(retryEpToken($world['org'], 'admin'))->postJson("/api/participants/{$world['participant']->id}/retry")
        : $this->withToken(retryEpKey($world['org']))->postJson("/api/m2m/participants/{$world['participant']->id}/retry");
    $response->assertOk();

    $url = $response->json('entry_url');
    $token = (string) parse_url($url, PHP_URL_QUERY).(string) parse_url($url, PHP_URL_FRAGMENT).(string) parse_url($url, PHP_URL_PATH);
    expect(strlen($token))->toBeGreaterThan(20);

    $audit = json_encode(AuditLog::withoutGlobalScopes()->get()->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    expect($audit)->not->toContain($url);

    // The ONLY places that may carry the url are the response and the queued job.
    $logged = json_encode($lines, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    expect($lines)->not->toBeEmpty()
        ->and($logged)->not->toContain($url)
        ->and($logged)->not->toContain($token);
})->with(['operator', 'm2m']);

// ─── exactly one authorization, across both surfaces ─────────────────────────

test('only one authorization is possible: the second call on either surface is 409 retry_already_consumed', function (string $first, string $second): void {
    $world = retryEpWorld();
    $call = fn (string $surface) => $surface === 'operator'
        ? $this->withToken(retryEpToken($world['org'], 'admin'))->postJson("/api/participants/{$world['participant']->id}/retry")
        : $this->withToken(retryEpKey($world['org']))->postJson("/api/m2m/participants/{$world['participant']->id}/retry");

    $call($first)->assertOk();
    $call($second)->assertStatus(409)->assertExactJson(['reason' => 'retry_already_consumed']);

    expect(AuditLog::withoutGlobalScopes()->where('action', 'evaluation.retry_authorized')->count())->toBe(1);
})->with([
    'operator then operator' => ['operator', 'operator'],
    'operator then m2m' => ['operator', 'm2m'],
    'm2m then operator' => ['m2m', 'operator'],
    'm2m then m2m' => ['m2m', 'm2m'],
]);

// ─── no public exposure ──────────────────────────────────────────────────────

test('neither retry route exists on the public /v1 surface', function (): void {
    $uris = collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->all();
    $retry = array_values(array_filter($uris, fn (string $u): bool => str_ends_with($u, '/retry')));

    sort($retry);
    expect($retry)->toBe(['api/m2m/participants/{id}/retry', 'api/participants/{id}/retry']);
});

// ─── the ability can be granted to a client ──────────────────────────────────

test('POST /api/m2m/clients accepts participants:retry, stores it canonical, and still rejects unknown abilities', function (): void {
    $org = Organization::factory()->create();
    $token = retryEpToken($org, 'admin');

    $created = $this->withToken($token)->postJson('/api/m2m/clients', ['name' => 'Retry client', 'abilities' => ['participants:retry']]);
    $created->assertCreated();
    expect($created->json('data.abilities'))->toBe(['participants:retry'])
        ->and(ApiClient::withoutGlobalScopes()->find($created->json('data.id'))->abilities)->toBe(['participants:retry']);

    $this->withToken($token)->postJson('/api/m2m/clients', ['name' => 'Bad', 'abilities' => ['participants:retry', 'participants:retry_everything']])
        ->assertUnprocessable()->assertJsonValidationErrors('abilities');
});
