<?php

declare(strict_types=1);

/**
 * `beai:demo-seed` — the evaluation report actually renders (spec:
 * "`project_competencies` Is Populated for Every Seeded Project" §"A
 * completed demo participant's evaluation report renders non-empty").
 *
 * Defect A (`project_competencies` never populated) was the entire reason
 * this change exists: `AdminEvaluationSerializer`, `EvaluationPayloadAssembler`
 * and `ProgressPayloadAssembler` all resolve competency order and count from
 * that pivot, and without it the report renders empty. A row count on
 * `project_competencies` proves the pivot exists; it does NOT prove the
 * report an operator actually sees is non-empty, ordered, and carries real
 * scores and excerpts — only running the production serializer proves that.
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Services\Admin\AdminEvaluationSerializer;
use App\Support\Demo\DemoMarker;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

test('AdminEvaluationSerializer renders a non-empty report for a completed demo participant, competencies in pivot order, with real scores and excerpts', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $report = TenantContextScope::runFor($this->org->id, function (): array {
        $c001 = Participant::where('organization_id', $this->org->id)
            ->where('candidate_ref', DemoMarker::PREFIX.'c-001')
            ->firstOrFail();

        return app(AdminEvaluationSerializer::class)->serialize($c001);
    });

    // Defect A, directly: an empty report here means the pivot the serializer
    // reads from is empty or unreadable, regardless of what any row count
    // elsewhere claims.
    expect($report)->not->toBe([]);

    // Ordered exactly as project_competencies.position for P1 (design volume
    // table): PRS, STG, DRV, COM, COL.
    expect(array_keys($report))->toBe(['PRS', 'STG', 'DRV', 'COM', 'COL']);

    // c-001's fixture (DemoDataset::participants()): PRS mean 3.67, all 3
    // indicators assessed (5,3,3) → reliability 100%.
    expect($report['PRS']['score'])->toBe(3.67);
    expect($report['PRS']['reliability'])->toBe('100%');
    expect($report['PRS']['behaviors'])->toHaveCount(3);

    foreach ($report['PRS']['behaviors'] as $behavior) {
        expect($behavior['indicator'])->toBeString()->not->toBe('');
        expect($behavior['score'])->not->toBeNull(); // none of c-001's PRS scores are -1
        expect($behavior['excerpts'])->not->toBe([]);
        expect($behavior['excerpts'][0])->toBeString()->not->toBe('');
    }

    // COL is c-001's all-but-one-assessed competency (5,3,-1): its third
    // indicator is the unassessable sentinel, which the serializer renders
    // as score=null (never the literal -1) with no excerpt — the same
    // "null means no value" distinction the BARS-valid requirement asserts.
    $colUnassessed = collect($report['COL']['behaviors'])->firstWhere('score', null);
    expect($colUnassessed)->not->toBeNull();
    expect($colUnassessed['excerpts'])->toBe([]);
});
