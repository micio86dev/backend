<?php

declare(strict_types=1);

/**
 * The dashboard answers "what is this costing me" in money, not in tokens.
 *
 * It used to answer in tokens because — per design D7 — no cost column
 * existed. Two have since been added: `ai_requests.estimated_cost_usd` for
 * scoring, and `interview_session_llm_usage` for the conversation, which
 * carries an estimate AND a settled figure. The dashboard went on reporting
 * tokens, which is not the question an operator is asking.
 */

use App\Models\AiRequest;
use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLlmUsage;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function costsToken(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate([
        'name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id,
    ]));
    app(TenantResolver::class)->setOrgId($org->id);

    return auth('api')->login($user);
}

/**
 * A conversation-cost row, with the whole chain beneath it.
 *
 * `interview_session_llm_usage.interview_session_id` is a real foreign key, so
 * the row cannot exist without a session, which cannot exist without a
 * participant, a project and a framework version. Built out rather than
 * stubbed: the sums under test run through the tenant global scope, and a row
 * with no owner would pass a query that is only correct because it filtered
 * nothing.
 */
function costsUsage(Organization $org, ?string $estimated, ?string $actual): void
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'organization_id' => $org->id,
        'framework_version_id' => $fv->id,
    ]);
    $participant = Participant::factory()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
    ]);
    $session = InterviewSession::factory()->ended(600)->create([
        'organization_id' => $org->id,
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'framework_version_id' => $fv->id,
        'provider' => 'heygen',
    ]);

    $usage = InterviewSessionLlmUsage::create([
        'interview_session_id' => $session->id,
        'turn_count' => 3,
        'system_prompt_chars' => 400,
        'participant_chars' => 200,
        'avatar_chars' => 600,
        'live_seconds' => 600,
        'estimated_input_tokens' => 780,
        'estimated_output_tokens' => 240,
        'estimated_cost_usd' => $estimated,
        'estimation_method' => 'chars4_context_resend_v1',
        'rate_card' => ['model_key' => 'gemini-3-flash-preview'],
    ]);

    if ($actual !== null) {
        // `forceFill` + `saveQuietly`: the table is append-only by
        // architecture test, and reconciliation is the one writer allowed to
        // settle a figure. This stands in for it.
        $usage->forceFill(['actual_cost_usd' => $actual])->saveQuietly();
    }
}

/**
 * A scoring-cost row. `ai_requests` requires a competency, a model and a
 * prompt version; none of them matter to a SUM, but all of them are NOT NULL.
 */
function costsAiRequest(Organization $org, string $usd): void
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    AiRequest::create([
        'organization_id' => $org->id,
        'competency_code' => 'COL',
        'provider' => 'anthropic',
        'model' => 'claude-opus-5',
        'prompt_version' => 'v1',
        'input_tokens' => 100,
        'output_tokens' => 50,
        'latency_ms' => 1200,
        'estimated_cost_usd' => $usd,
        'success' => true,
    ]);
}

test('an organization with no usage is told zero, not null', function (): void {
    // A blank where a number belongs reads as "we do not know". Zero is a
    // fact, and it is the correct one for a tenant that has run nothing.
    $org = Organization::factory()->create();
    $token = costsToken($org);

    $response = $this->withToken($token)->getJson('/api/dashboard/metrics');

    $response->assertOk();

    // Compared as floats, not through `assertJsonPath`: the values are floats
    // by contract (`JSON_PRESERVE_ZERO_FRACTION` keeps 0.0 from encoding as
    // an integer), and a path assertion against `0` fails on `0.0` for a
    // reason that has nothing to do with the behaviour.
    expect($response->json('data.costs.scoring_usd'))->toBe(0.0)
        ->and($response->json('data.costs.conversation_usd'))->toBe(0.0)
        ->and($response->json('data.costs.total_usd'))->toBe(0.0)
        ->and($response->json('data.costs.currency'))->toBe('USD');
});

test('scoring and conversation costs are reported apart AND summed', function (): void {
    // Apart because they answer different questions: scoring is per completed
    // evaluation and predictable, conversation is per minute of interview and
    // is the one that moves when candidates talk longer. Summed because the
    // first thing anyone wants is the one number.
    $org = Organization::factory()->create();
    $token = costsToken($org);

    costsAiRequest($org, '0.250000');
    costsAiRequest($org, '0.250000');

    $response = $this->withToken($token)->getJson('/api/dashboard/metrics');

    expect($response->json('data.costs.scoring_usd'))->toBe(0.5)
        ->and($response->json('data.costs.conversation_usd'))->toBe(0.0)
        ->and($response->json('data.costs.total_usd'))->toBe(0.5);
});

test('a settled conversation cost REPLACES its estimate rather than adding to it', function (): void {
    // The row carries both columns once the provider settles. Summing each
    // column separately would count that interview twice — and it would do so
    // silently, producing a number that is merely wrong rather than obviously
    // broken.
    $org = Organization::factory()->create();
    $token = costsToken($org);

    costsUsage($org, '1.000000', '1.500000');
    costsUsage($org, '2.000000', null);

    $response = $this->withToken($token)->getJson('/api/dashboard/metrics');

    // 1.50 (settled) + 2.00 (still an estimate) — NOT 1.00 + 1.50 + 2.00.
    expect($response->json('data.costs.conversation_usd'))->toBe(3.5);
});

test('one tenant is never billed for another tenant, in either column', function (): void {
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    $token = costsToken($mine);

    costsAiRequest($theirs, '99.000000');
    costsUsage($theirs, '99.000000', null);

    // Re-established after the helpers above moved it to the other tenant.
    app(TenantResolver::class)->setOrgId($mine->id);

    $response = $this->withToken($token)->getJson('/api/dashboard/metrics');

    expect($response->json('data.costs.total_usd'))->toBe(0.0);
});

test('the activity feed carries the id its rows are addressed by', function (): void {
    // Without it the feed names a candidate and gives no way to go and look —
    // `candidate_ref` is the calling system's opaque identifier and addresses
    // nothing in this product.
    $org = Organization::factory()->create();
    $token = costsToken($org);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'organization_id' => $org->id,
        'framework_version_id' => $fv->id,
    ]);
    $participant = Participant::factory()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
    ]);

    $response = $this->withToken($token)->getJson('/api/dashboard/activity');

    $response->assertOk();
    $response->assertJsonPath('data.0.id', $participant->id);
});
