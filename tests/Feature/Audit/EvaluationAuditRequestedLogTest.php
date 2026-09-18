<?php

declare(strict_types=1);

/**
 * RED — P4.13/P4.14: an accepted `POST /participants/{id}/evaluation/audit`
 * writes exactly one `evaluation.audit_requested` `audit_logs` row naming
 * the admin actor and the evaluation subject (scoring-audit-jev, design D12
 * step 6). A REFUSED request writes none. Mirrors
 * `tests/Feature/C13/AuditLogTest.php`'s shape.
 */

use App\Models\AuditLog;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{project: Project, participant: Participant, evaluation: Evaluation}
 */
function auditLogFixture(Organization $org, string $participantStatus = 'completato'): array
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus($participantStatus)->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    return ['project' => $project, 'participant' => $participant, 'evaluation' => $evaluation];
}

test('an accepted request writes one evaluation.audit_requested row naming the admin actor and the evaluation subject', function (): void {
    Queue::fake();

    $org = Organization::factory()->create();
    $fixture = auditLogFixture($org);
    $userAndToken = authUserAndTokenForRole($org, 'admin');

    $this->withToken($userAndToken['token'])
        ->postJson("/api/participants/{$fixture['participant']->id}/evaluation/audit")
        ->assertStatus(202);

    $row = AuditLog::withoutGlobalScopes()
        ->where('action', 'evaluation.audit_requested')
        ->firstOrFail();

    expect($row->actor_id)->toBe($userAndToken['user']->id)
        ->and($row->subject_type)->toBe('evaluation')
        ->and($row->subject_id)->toBe($fixture['evaluation']->id)
        ->and($row->organization_id)->toBe($org->id);
});

test('a refused request writes no evaluation.audit_requested row', function (): void {
    $org = Organization::factory()->create();
    $fixture = auditLogFixture($org);
    // Operator is refused by EvaluationPolicy::audit (admin only).
    $token = authTokenForRole($org, 'operator');

    $this->withToken($token)
        ->postJson("/api/participants/{$fixture['participant']->id}/evaluation/audit")
        ->assertStatus(403);

    $count = AuditLog::withoutGlobalScopes()
        ->where('action', 'evaluation.audit_requested')
        ->count();

    expect($count)->toBe(0);
});

test('a request refused by the kill switch writes no evaluation.audit_requested row', function (): void {
    config(['scoring.audit.enabled' => false]);

    $org = Organization::factory()->create();
    $fixture = auditLogFixture($org);
    $token = authTokenForRole($org, 'admin');

    $this->withToken($token)
        ->postJson("/api/participants/{$fixture['participant']->id}/evaluation/audit")
        ->assertStatus(409);

    $count = AuditLog::withoutGlobalScopes()
        ->where('action', 'evaluation.audit_requested')
        ->count();

    expect($count)->toBe(0);
});
