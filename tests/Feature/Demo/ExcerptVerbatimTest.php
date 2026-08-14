<?php

declare(strict_types=1);

/**
 * `beai:demo-seed` — excerpt-verbatim invariant (design D4; spec: "Every
 * Excerpt Is a Verbatim Substring of Its Seeded Transcript").
 *
 * Checked with the PRODUCTION `ExcerptValidator` against the PRODUCTION
 * `TranscriptAssembler::assemble()` — the same two classes that guard a
 * real scoring run — not a copy of the substring logic. An excerpt that is
 * not a verbatim substring of the transcript is a fabricated quotation
 * attributed to a real person; this is the check that makes it impossible.
 */

use App\DTOs\Scoring\IndicatorScoreDTO;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Services\Scoring\ExcerptValidator;
use App\Services\Scoring\TranscriptAssembler;
use App\Support\Demo\DemoMarker;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

test('every IndicatorScore excerpt is a verbatim substring of its own session transcript', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $assembler = new TranscriptAssembler;
    $validator = new ExcerptValidator;

    $checked = 0;

    TenantContextScope::runFor($this->org->id, function () use ($assembler, $validator, &$checked): void {
        $scoredRefs = [
            DemoMarker::PREFIX.'c-001',
            DemoMarker::PREFIX.'c-002',
            DemoMarker::PREFIX.'c-007',
            DemoMarker::PREFIX.'c-009',
        ];

        foreach ($scoredRefs as $ref) {
            $participant = Participant::where('candidate_ref', $ref)->firstOrFail();
            $evaluation = Evaluation::where('participant_id', $participant->id)->firstOrFail();

            $results = CompetencyResult::where('evaluation_id', $evaluation->id)->get();

            foreach ($results as $result) {
                $session = InterviewSession::where('participant_id', $participant->id)
                    ->where('competency_code', $result->competency_code)
                    ->firstOrFail();

                $transcript = $assembler->assemble($session);

                foreach ($result->indicatorScores as $indicatorScore) {
                    $dto = new IndicatorScoreDTO(
                        position: $indicatorScore->position,
                        indicatorText: $indicatorScore->indicator_text,
                        score: $indicatorScore->score,
                        explanation: $indicatorScore->explanation,
                        excerpts: $indicatorScore->excerpts,
                    );

                    // Throws ExcerptNotVerbatimException on any failure —
                    // the test fails loudly rather than silently passing.
                    $validator->validate($dto, $transcript);
                    $checked++;
                }
            }
        }
    });

    expect($checked)->toBeGreaterThan(0);
});
