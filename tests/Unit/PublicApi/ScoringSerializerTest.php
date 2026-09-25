<?php

declare(strict_types=1);

/**
 * `App\PublicApi\Serializers\ScoringSerializer` (public-api step 6) —
 * unit-level coverage of the tenancy-violation defensive guard
 * (`T-INT-020`/`T-INT-021` only exercise the healthy path).
 */

use App\Models\Evaluation;
use App\Models\Organization;
use App\PublicApi\Serializers\ScoringSerializer;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\PublicApi\Step6Fixtures;

test('a framework_version_id that cannot resolve under the ambient tenant scope refuses to serialize, rather than papering over the gap', function (): void {
    ['org' => $org] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::buildCompletedScoredParticipant($org, $project);

    $evaluation = TenantContextScope::runFor(
        $org->id,
        fn () => Evaluation::where('participant_id', $participant->id)->firstOrFail(),
    );

    // Reassign the pinned FrameworkVersion to a DIFFERENT organization —
    // simulating a tenancy-violation data state directly at the database
    // level (raw update, bypassing Eloquent's own tenant scope entirely,
    // since a normal write path can never produce this on purpose). The
    // Evaluation row itself stays correctly in $org; only its
    // framework_version_id no longer resolves UNDER $org's own tenant
    // scope.
    $otherOrg = Organization::factory()->create();
    DB::table('framework_versions')
        ->where('id', $evaluation->framework_version_id)
        ->update(['organization_id' => $otherOrg->id]);

    TenantContextScope::runFor($org->id, function () use ($participant): void {
        (new ScoringSerializer)->toArray($participant);
    });
})->throws(RuntimeException::class, 'did not resolve under the ambient tenant scope');

test('a completed evaluation with a null evaluated_at refuses to serialize an empty string, rather than papering over the invariant violation', function (): void {
    ['org' => $org] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::buildCompletedScoredParticipant($org, $project);

    $evaluation = TenantContextScope::runFor(
        $org->id,
        fn () => Evaluation::where('participant_id', $participant->id)->firstOrFail(),
    );

    // `ScoreEvaluationJob::resolveEvaluationTerminalState()` always sets
    // `evaluated_at` in the SAME write as the terminal status — a
    // completed evaluation with a null `evaluated_at` can never happen
    // through that write path, only through direct model manipulation
    // (the same "simulate a broken invariant directly at the database
    // level" discipline the tenancy-violation test above already uses).
    DB::table('evaluations')
        ->where('id', $evaluation->id)
        ->update(['evaluated_at' => null]);

    TenantContextScope::runFor($org->id, function () use ($participant): void {
        (new ScoringSerializer)->toArray($participant);
    });
})->throws(RuntimeException::class, 'is completed but evaluated_at is null');
