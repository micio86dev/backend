<?php

declare(strict_types=1);

/**
 * `ai_requests` attribution, asserted against the DATABASE — not the
 * fixture (design D12/D14; spec "ai_requests Rows Are Attributed to
 * Existing Evaluations With Plausible Values"). A NULL `evaluation_id` has
 * no marker, no `cascadeOnDelete` path, and no teardown path — the fixture
 * satisfying this is necessary but not sufficient; the WRITTEN rows must.
 */

use App\Models\AiRequest;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

test('every seeded ai_requests row has a non-null evaluation_id referencing a real demo evaluation', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    TenantContextScope::runFor($this->org->id, function (): void {
        expect(AiRequest::whereNull('evaluation_id')->count())->toBe(0);

        $evaluationIds = AiRequest::query()->pluck('evaluation_id')->unique();
        $demoEvaluationIds = Evaluation::query()->pluck('id')->all();

        foreach ($evaluationIds as $evaluationId) {
            expect($demoEvaluationIds)->toContain($evaluationId);
        }
    });
});

test('ai_requests carries multiple rows per some competency_results rows, not a flat 1:1 mapping (proves the audit-retry rows are real, not fixture-only)', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    TenantContextScope::runFor($this->org->id, function (): void {
        $aiRequestCount = AiRequest::count();
        $competencyResultCount = CompetencyResult::count();

        // Aggregate proof: more calls were made than there are results —
        // the sample size a meaningful percentile needs (design D14).
        expect($competencyResultCount)->toBeGreaterThan(0);
        expect($aiRequestCount)->toBeGreaterThan($competencyResultCount);

        // Concrete proof, not just an aggregate coincidence: c-002's
        // evaluation has, by design, a second (queue-retry) ai_requests row
        // for EVERY one of its 5 competencies — a real >1-per-result case,
        // read from the database.
        $c002Evaluation = Evaluation::query()
            ->whereHas('participant', fn ($q) => $q->where('candidate_ref', 'beai-demo-c-002'))
            ->firstOrFail();

        $rowsByCompetency = AiRequest::where('evaluation_id', $c002Evaluation->id)
            ->get()
            ->groupBy('competency_code');

        expect($rowsByCompetency)->toHaveCount(5);

        foreach ($rowsByCompetency as $competencyCode => $rows) {
            expect($rows)->toHaveCount(2, "competency [{$competencyCode}] on c-002's evaluation should have 2 ai_requests rows (first call + queue-retry).");
        }
    });
});
