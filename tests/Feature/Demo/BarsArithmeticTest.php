<?php

declare(strict_types=1);

/**
 * `beai:demo-seed` — BARS arithmetic is COMPUTED, never hardcoded (design
 * D5; spec: "Seeded Evaluation Data Is BARS-Valid").
 *
 * Every `score`/`reliability`/`valid` is recomputed here with the same
 * production classes the writer used (`MeanCalculator`,
 * `AssessableFractionReliability`, `ThresholdValidityPredicate`), and every
 * evaluation status is recomputed with `CompletionGate`. A seeded number
 * that merely LOOKS right but disagrees with the engine teaches the
 * operator something false — this test proves agreement, not plausibility.
 *
 * Also the direct regression guard for legacy Defect D: an all-`-1`
 * competency's `unscorable_reason` MUST be NULL (`ScoreEvaluationJob.php:
 * 715-722`), never the out-of-domain `'no_assessable_evidence'` string the
 * old `DemoSeeder` wrote.
 */

use App\Enums\EvaluationStatus;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\Organization;
use App\Models\Participant;
use App\Services\Scoring\AssessableFractionReliability;
use App\Services\Scoring\CompletionGate;
use App\Services\Scoring\MeanCalculator;
use App\Services\Scoring\ThresholdValidityPredicate;
use App\Support\Demo\DemoMarker;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);
});

test('every competency_results row recomputes to the stored score/reliability/valid', function (): void {
    $mean = new MeanCalculator;
    $reliability = new AssessableFractionReliability;
    $validity = new ThresholdValidityPredicate;

    $checked = 0;

    TenantContextScope::runFor($this->org->id, function () use ($mean, $reliability, $validity, &$checked): void {
        $refs = [DemoMarker::PREFIX.'c-001', DemoMarker::PREFIX.'c-002', DemoMarker::PREFIX.'c-007', DemoMarker::PREFIX.'c-009'];

        foreach ($refs as $ref) {
            $participant = Participant::where('candidate_ref', $ref)->firstOrFail();
            $evaluation = Evaluation::where('participant_id', $participant->id)->firstOrFail();
            $results = CompetencyResult::where('evaluation_id', $evaluation->id)->get();

            foreach ($results as $result) {
                $scores = $result->indicatorScores()->orderBy('position')->pluck('score')->all();

                $expectedMean = $mean->compute($scores);
                $expectedReliability = $reliability->compute($scores);
                $expectedValid = $validity->isValid($expectedReliability);

                expect($result->score)->toBe($expectedMean);
                expect(round($result->reliability, 4))->toBe(round($expectedReliability, 4));
                expect($result->valid)->toBe($expectedValid);

                $checked++;
            }
        }
    });

    expect($checked)->toBe(20);
});

test('CompletionGate resolves c-001/c-007/c-009 to completed and c-002 to pending', function (): void {
    $gate = new CompletionGate;

    TenantContextScope::runFor($this->org->id, function () use ($gate): void {
        $expectations = [
            DemoMarker::PREFIX.'c-001' => EvaluationStatus::Completed,
            DemoMarker::PREFIX.'c-002' => EvaluationStatus::Pending,
            DemoMarker::PREFIX.'c-007' => EvaluationStatus::Completed,
            DemoMarker::PREFIX.'c-009' => EvaluationStatus::Completed,
        ];

        foreach ($expectations as $ref => $expectedStatus) {
            $participant = Participant::where('candidate_ref', $ref)->firstOrFail();
            $evaluation = Evaluation::where('participant_id', $participant->id)->firstOrFail();
            $results = CompetencyResult::where('evaluation_id', $evaluation->id)->get();

            $validCount = $results->where('valid', true)->count();
            $totalCount = $results->count();

            expect($gate->evaluate($validCount, $totalCount))->toBe($expectedStatus);
            expect($evaluation->status)->toBe($expectedStatus);
        }
    });
});

test('at least one all-unassessable competency stores a NULL score with a NULL unscorable_reason (Defect-D regression)', function (): void {
    TenantContextScope::runFor($this->org->id, function (): void {
        $c002 = Participant::where('candidate_ref', DemoMarker::PREFIX.'c-002')->firstOrFail();
        $evaluation = Evaluation::where('participant_id', $c002->id)->firstOrFail();

        $col = CompetencyResult::where('evaluation_id', $evaluation->id)
            ->where('competency_code', 'COL')
            ->firstOrFail();

        expect($col->score)->toBeNull();
        // The regression this guards: legacy DemoSeeder wrote the
        // out-of-domain 'no_assessable_evidence' here. The real engine
        // (ScoreEvaluationJob.php:715-722) writes NULL for the all-(-1) case.
        expect($col->unscorable_reason)->toBeNull();
    });
});

test('at least one indicator score in the seeded dataset is -1 (unassessable)', function (): void {
    $count = TenantContextScope::runFor(
        $this->org->id,
        fn () => IndicatorScore::where('score', -1)->count(),
    );

    expect($count)->toBeGreaterThanOrEqual(1);
});

test('unscorable_reason is never the out-of-domain legacy value', function (): void {
    $offenders = TenantContextScope::runFor(
        $this->org->id,
        fn () => CompetencyResult::where('unscorable_reason', 'no_assessable_evidence')->count(),
    );

    expect($offenders)->toBe(0);
});
