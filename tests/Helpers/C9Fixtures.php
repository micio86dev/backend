<?php

declare(strict_types=1);

/**
 * Shared fixtures for the C9 interview-flow tests.
 *
 * These live here, not inside a test file, because Pest only makes a test
 * file's functions global once that file has been loaded — running a single
 * spec that borrowed them from a sibling failed with "undefined function".
 * Autoloaded via composer.json `autoload-dev.files`, alongside the C10 and
 * scoring fixtures.
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function casOrg(): Organization
{
    return Organization::factory()->create();
}

/**
 * A project with `$count` competencies, each with a composition-sufficient
 * BarsIndicator so `/start` can compose a prompt.
 *
 * @return array{0: Project, 1: list<Competency>}
 */
function casProject(Organization $org, int $count = 1): array
{
    // Tenant context must exist before creating tenant-scoped models — the project
    // factory pulls a FrameworkVersion with it.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create([
        'status' => 'active',
        'assessment_type' => 'standard',
    ]);

    // firstOrCreate, not create: roles are a platform-wide catalogue with a unique
    // code, so two organizations in one test share the same role row.
    $role = Role::firstOrCreate(
        ['code' => $project->role_code],
        Role::factory()->make(['code' => $project->role_code])->getAttributes(),
    );

    $competencies = [];
    for ($i = 0; $i < $count; $i++) {
        $comp = Competency::factory()->create();
        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $comp->id,
            'position' => $i + 1,
        ]);

        $ind = new BarsIndicator;
        $ind->forceFill([
            'role_id' => $role->id,
            'competency_id' => $comp->id,
            'text' => ['en' => "CAS fixture indicator {$i}"],
            'anchor_5' => ['en' => "Excellent {$i}"],
            'anchor_3' => ['en' => "Adequate {$i}"],
            'anchor_1' => ['en' => "Insufficient {$i}"],
            'position' => 0,
        ]);
        $ind->save();

        $competencies[] = $comp;
    }

    return [$project, $competencies];
}

function casParticipant(Organization $org, Project $project, string $status = 'in_corso'): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'cas-'.uniqid(),
        'display_name' => 'CAS Test Candidate',
        'status' => $status,
        'started_at' => now(),
    ]);
    $p->save();

    return $p->fresh();
}

function casBearer(Participant $participant): string
{
    return CandidateTokenFactory::mintCandidateToken($participant);
}

/** Provider returns 4xx → ProviderFailureClass::ClientError → session error, participant untouched. */
function casClientErrorFake(): array
{
    return ['*liveavatar*' => Http::response(['message' => 'malformed request'], 422)];
}

/** Provider returns 5xx → ProviderFailureClass::Upstream → session error AND participant errore. */
/** A provider call that succeeds, for the paths where the failure is not the subject. */
function heygenOkFake(): array
{
    return [
        '*liveavatar*/contexts*' => Http::response(['data' => ['id' => 'ctx-'.uniqid()]], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => [
                'session_id' => 'heygen-session-'.uniqid(),
                'session_token' => 'heygen-token-'.uniqid(),
            ],
        ], 200),
        // The transcript fetch MUST return the real shape. A 200 whose body lacks
        // `data.transcript_data` is contract drift, and `reconcileTranscript()`
        // throws on it by design (fail-loud, F1) — which surfaces as a 502 from
        // /end and looks like a broken endpoint rather than a thin fixture.
        '*liveavatar*/sessions/*/transcript*' => Http::response([
            'data' => ['transcript_data' => [
                ['role' => 'assistant', 'transcript' => 'Fixture question.', 'time_ms' => 2000],
                ['role' => 'user', 'transcript' => 'Fixture answer.', 'time_ms' => 1000],
            ]],
        ], 200),
        '*liveavatar*/sessions/*' => Http::response([], 200), // teardown
    ];
}

/** Provider returns 5xx → ProviderFailureClass::Upstream → session error AND participant errore. */
function casUpstreamFake(): array
{
    return ['*liveavatar*' => Http::response(['message' => 'upstream exploded'], 503)];
}

function casInTenant(Organization $org, callable $fn): mixed
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return $fn();
}
