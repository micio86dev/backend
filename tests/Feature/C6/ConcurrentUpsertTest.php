<?php

declare(strict_types=1);

/**
 * Concurrent upsert test (C6 — Participant + SSO Ingress).
 *
 * Asserts:
 * - Two simultaneous valid exchange requests for same (project_id, candidate_ref)
 *   → exactly one row; no duplicate key error.
 *
 * Note: True concurrency tests require process-level parallelism. This test
 * verifies the idempotent upsert semantic by simulating the second call while
 * the first has already created the row (sequential idempotency).
 * True race condition coverage at the DB level relies on the UNIQUE constraint
 * and ON CONFLICT DO UPDATE semantics which are tested here at the SQL level.
 *
 * REQ: Idempotent Upsert — "Concurrent exchanges produce exactly one participant"
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;

function makeConcurrentProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create([
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'goes_live_at' => null,
        'deadline_at' => null,
    ]);
}

test('two sequential exchange requests for same (project_id, candidate_ref) → exactly one row', function (): void {
    $org = Organization::factory()->create();
    $project = makeConcurrentProject($org);
    $ref = 'concurrent-cand';

    // First exchange
    $token1 = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => $ref,
        'display_name' => 'First',
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => 'ICO',
        'lang' => 'en',
    ]);
    $this->getJson('/api/sso/exchange?token='.$token1)->assertOk();

    // Second exchange (different jti, same candidate_ref) — simulates a second link sent
    $token2 = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => $ref,
        'display_name' => 'Second',
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => 'ICO',
        'lang' => 'en',
    ]);
    $this->getJson('/api/sso/exchange?token='.$token2)->assertOk();

    // Exactly one row — no duplicate key error, upsert worked
    $count = Participant::where('project_id', $project->id)
        ->where('candidate_ref', $ref)
        ->count();

    expect($count)->toBe(1);
});

test('ON CONFLICT upsert on (project_id, candidate_ref) — direct DB test', function (): void {
    $org = Organization::factory()->create();
    $project = makeConcurrentProject($org);
    $ref = 'direct-upsert-test';
    $now = now()->toDateTimeString();

    // Insert first row
    DB::statement("
        INSERT INTO participants
            (organization_id, project_id, candidate_ref, display_name, role_code, language, status, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, 'in_attesa', ?, ?)
        ON CONFLICT (project_id, candidate_ref)
        DO UPDATE SET
            display_name = EXCLUDED.display_name,
            role_code    = EXCLUDED.role_code,
            language     = EXCLUDED.language,
            updated_at   = EXCLUDED.updated_at
        WHERE participants.status = 'in_attesa'
    ", [$org->id, $project->id, $ref, 'First Display', 'ICO', 'en', $now, $now]);

    // Second insert with same conflict key — should update, not error
    DB::statement("
        INSERT INTO participants
            (organization_id, project_id, candidate_ref, display_name, role_code, language, status, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, 'in_attesa', ?, ?)
        ON CONFLICT (project_id, candidate_ref)
        DO UPDATE SET
            display_name = EXCLUDED.display_name,
            role_code    = EXCLUDED.role_code,
            language     = EXCLUDED.language,
            updated_at   = EXCLUDED.updated_at
        WHERE participants.status = 'in_attesa'
    ", [$org->id, $project->id, $ref, 'Second Display', 'ICO', 'en', $now, $now]);

    $count = Participant::where('project_id', $project->id)->where('candidate_ref', $ref)->count();
    $participant = Participant::where('project_id', $project->id)->where('candidate_ref', $ref)->first();

    expect($count)->toBe(1);
    expect($participant->display_name)->toBe('Second Display');
});
