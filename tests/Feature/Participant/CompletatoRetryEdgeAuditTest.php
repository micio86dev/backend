<?php

declare(strict_types=1);

/**
 * Audit of the checks that assumed `completato` is terminal
 * (scoring-retry-rt-b, design D3), exercised across the new
 * `completato -> in_attesa` edge: every consumer must keep treating a
 * `completato` row exactly as before, and must treat the re-opened `in_attesa`
 * row like any other waiting participant. The other D3 rows (finalize key,
 * scoring guard, webhooks, recovery guard) change behaviour in later slices
 * and are pinned there.
 *
 * The edge is applied here through the model, the way the authorization
 * action will; the arch test that restricts WHO may write it lands with that
 * action (PR1b).
 *
 * REQ: participant-sso Lifecycle Guard; interview-session FIX-5 (amended).
 */

use App\Exceptions\Admin\LifecycleNotReadyException;
use App\Exceptions\Sso\EntryLinkRefusalReason;
use App\Exceptions\Sso\EntryLinkRefused;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Admin\EvaluationIndexFilters;
use App\Support\Admin\EvaluationIndexQuery;
use App\Support\Admin\LifecycleReadGate;
use App\Support\Admin\ParticipantReadScope;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Sso\EntryLinkMinter;
use App\Support\Tenancy\TenantResolver;

/**
 * @return array{org: Organization, project: Project, participant: Participant}
 */
function retryEdgeAuditFixture(): array
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id, 'status' => 'active']);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    Evaluation::factory()->pending()->create(['participant_id' => $participant->id]);

    return ['org' => $org, 'project' => $project, 'participant' => $participant];
}

function retryEdgeReopen(Participant $participant): void
{
    $participant->status = 'in_attesa';
    $participant->save();
}

test('the entry-link minter refuses a completato row and mints for the re-opened in_attesa row', function (): void {
    ['project' => $project, 'participant' => $participant] = retryEdgeAuditFixture();
    $mint = fn () => (new EntryLinkMinter)->mint(
        $project, $participant->candidate_ref, $participant->display_name, $participant->email, null, null,
    );

    try {
        $mint();
        expect(false)->toBeTrue('Expected EntryLinkRefused for a completato row.');
    } catch (EntryLinkRefused $e) {
        expect($e->reason)->toBe(EntryLinkRefusalReason::Completed);
    }

    retryEdgeReopen($participant);

    $minted = $mint();
    expect($minted->token)->not->toBe('')->and($minted->expiresAt->isFuture())->toBeTrue();
});

test('the candidate status guard blocks completato and lets the re-opened in_attesa row through', function (): void {
    ['participant' => $participant] = retryEdgeAuditFixture();
    $headers = fn (): array => ['Authorization' => 'Bearer '.CandidateTokenFactory::mintCandidateToken($participant->fresh())];
    $payload = ['session_id' => 9999, 'speaker' => 'candidate', 'text' => 'Hello', 'ts' => now()->toIso8601String()];

    $this->withHeaders($headers())->postJson('/api/candidate/interview/utterance', $payload)->assertForbidden();

    retryEdgeReopen($participant);
    // The auth guard memoizes the resolved candidate within one test process.
    app('auth')->forgetGuards();

    // Past the guard the controller answers on its own terms (unknown session), never 403.
    expect($this->withHeaders($headers())->postJson('/api/candidate/interview/utterance', $payload)->status())
        ->not->toBe(403);
});

test('the evaluation stays unreadable while the pending evaluation is being retried', function (): void {
    ['participant' => $participant] = retryEdgeAuditFixture();
    $gate = new LifecycleReadGate;

    expect(fn () => $gate->assert('completato', ParticipantReadScope::Evaluation))->not->toThrow(LifecycleNotReadyException::class);

    retryEdgeReopen($participant);

    expect(fn () => $gate->assert($participant->fresh()->status, ParticipantReadScope::Evaluation))
        ->toThrow(LifecycleNotReadyException::class);
});

test('the evaluations index lists the completato participant and drops the re-opened one', function (): void {
    ['participant' => $participant] = retryEdgeAuditFixture();
    $index = fn () => (new EvaluationIndexQuery(app(TenantResolver::class)))
        ->build(new EvaluationIndexFilters)->get()->pluck('participant_id');

    // A pending evaluation of a completato participant is listed (ordinary read gate).
    expect($index())->toContain($participant->id);

    retryEdgeReopen($participant);

    expect($index())->not->toContain($participant->id);
});
