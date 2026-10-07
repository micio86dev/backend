<?php

declare(strict_types=1);

/**
 * Full participant status transition matrix, including the single
 * `completato -> in_attesa` retry edge (scoring-retry-rt-b, PR1a).
 *
 * The edge is the evaluation-retry authorization: it re-opens a completed
 * participant so the candidate can re-interview the invalid competencies. It
 * is written ONLY by App\Actions\Participant\AuthorizeEvaluationRetry (PR1b,
 * pinned by an arch test there); this file pins the MODEL guard, so every
 * other move out of `completato` and every pre-existing edge stays exactly as
 * it was.
 *
 * REQ: interview-session FIX-5 (amended) and participant-sso Lifecycle Guard.
 */

use App\Exceptions\ParticipantTransitionException;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;

const RETRY_MATRIX_STATUSES = ['in_attesa', 'in_corso', 'in_valutazione', 'completato', 'errore'];

/**
 * The complete expected map. Independent of the production array on purpose:
 * a typo in either one makes the dataset below disagree with the model.
 */
const RETRY_MATRIX_ALLOWED = [
    'in_attesa' => ['in_corso', 'errore'],
    'in_corso' => ['in_valutazione', 'errore'],
    'in_valutazione' => ['completato', 'errore'],
    'completato' => ['in_attesa'],
    'errore' => ['in_attesa'],
];

function retryMatrixParticipant(string $status): Participant
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active']);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'matrix-'.uniqid(),
        'display_name' => 'Matrix Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => $status,
    ]);
    $participant->save();

    return $participant->fresh();
}

/**
 * @return array<string, array{string, string, bool}>
 */
function retryMatrixDataset(): array
{
    $rows = [];
    foreach (RETRY_MATRIX_STATUSES as $from) {
        foreach (RETRY_MATRIX_STATUSES as $to) {
            if ($from === $to) {
                continue;
            }
            $rows["{$from} -> {$to}"] = [$from, $to, in_array($to, RETRY_MATRIX_ALLOWED[$from], true)];
        }
    }

    return $rows;
}

dataset('participant status transitions', retryMatrixDataset());

test('the status transition matrix is exactly the expected one', function (string $from, string $to, bool $allowed): void {
    $participant = retryMatrixParticipant($from);
    $participant->status = $to;

    if ($allowed) {
        $participant->save();
        expect($participant->fresh()->status)->toBe($to);

        return;
    }

    expect(fn () => $participant->save())->toThrow(ParticipantTransitionException::class);
    expect($participant->fresh()->status)->toBe($from);
})->with('participant status transitions');

test('the matrix dataset covers every ordered pair of distinct statuses', function (): void {
    $dataset = retryMatrixDataset();

    expect($dataset)->toHaveCount(20);
    expect(array_filter($dataset, fn (array $row): bool => $row[2]))->toHaveCount(8);
});

test('completato -> in_attesa re-opens the participant and keeps started_at untouched', function (): void {
    $participant = retryMatrixParticipant('completato');
    $startedAt = now()->subDay()->startOfSecond();
    $participant->forceFill(['started_at' => $startedAt])->saveQuietly();

    $participant->status = 'in_attesa';
    $participant->save();

    $fresh = $participant->fresh();
    expect($fresh->status)->toBe('in_attesa');
    expect($fresh->started_at?->equalTo($startedAt))->toBeTrue();
});
