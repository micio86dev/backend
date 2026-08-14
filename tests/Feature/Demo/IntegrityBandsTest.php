<?php

declare(strict_types=1);

/**
 * `beai:demo-seed` — IntegrityEvent writer, proctoring risk bands (spec:
 * "Seeded Data Spans Every Status, Both Assessment Types, and All Proctoring
 * Risk Bands" — the bands clause).
 *
 * `IntegritySummarizer::summarize` is the PRODUCTION weighted-score
 * function (server-side only, `IntegritySummarizer.php`) — these are its
 * exact weights applied to the exact seeded event sets, not a hand
 * computed approximation. Values chosen to land cleanly in each band:
 * 7.8 (low, < 15), 19.0 (medium, 15 ≤ x < 40), 46.0 (high, ≥ 40).
 */

use App\Models\IntegrityEvent;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Services\Proctoring\IntegritySummarizer;
use App\Support\Demo\DemoMarker;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);
});

function summarizeDemoSession(int $orgId, string $candidateRef, string $competencyCode): array
{
    return TenantContextScope::runFor($orgId, function () use ($candidateRef, $competencyCode): array {
        $participant = Participant::where('candidate_ref', $candidateRef)->firstOrFail();
        $session = InterviewSession::where('participant_id', $participant->id)
            ->where('competency_code', $competencyCode)
            ->firstOrFail();

        $events = IntegrityEvent::where('interview_session_id', $session->id)
            ->get()
            ->map(fn (IntegrityEvent $e): array => ['kind' => $e->kind, 'payload' => $e->payload])
            ->all();

        return IntegritySummarizer::summarize($events);
    });
}

test('c-001 scores 7.8 and bands low', function (): void {
    $summary = summarizeDemoSession($this->org->id, DemoMarker::PREFIX.'c-001', 'PRS');

    expect($summary['score'])->toBe(7.8);
    expect($summary['band'])->toBe('low');
});

test('c-004 scores 19.0 and bands medium', function (): void {
    $summary = summarizeDemoSession($this->org->id, DemoMarker::PREFIX.'c-004', 'PRS');

    expect($summary['score'])->toBe(19.0);
    expect($summary['band'])->toBe('medium');
});

test('c-003 scores 46.0 and bands high', function (): void {
    $summary = summarizeDemoSession($this->org->id, DemoMarker::PREFIX.'c-003', 'PRS');

    expect($summary['score'])->toBe(46.0);
    expect($summary['band'])->toBe('high');
});

test('all three risk bands are represented across the seeded demo sessions', function (): void {
    $low = summarizeDemoSession($this->org->id, DemoMarker::PREFIX.'c-001', 'PRS')['band'];
    $medium = summarizeDemoSession($this->org->id, DemoMarker::PREFIX.'c-004', 'PRS')['band'];
    $high = summarizeDemoSession($this->org->id, DemoMarker::PREFIX.'c-003', 'PRS')['band'];

    expect([$low, $medium, $high])->toBe(['low', 'medium', 'high']);
});
