<?php

declare(strict_types=1);

/**
 * `beai:demo-seed` — Participant + InterviewSession + Utterance writer,
 * and the tenancy discipline the whole command runs under (design D7).
 *
 * `TenantScoped::creating` unconditionally stamps `organization_id` from the
 * resolver and THROWS with no ambient context — so the command establishes
 * one explicit `TenantContextScope::runFor` boundary around the whole seed
 * rather than relying on request-lifecycle middleware that does not run in
 * a console command. `Participant` is a plain `Model` (not `TenantModel`):
 * its `organization_id` is excluded from `$fillable` as a C2 invariant, so
 * it must be written via `forceFill()`, never `create()`.
 */

use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Utterance;
use App\Support\Demo\DemoMarker;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

test('the command establishes and restores its own tenant context boundary, leaking nothing into the ambient resolver', function (): void {
    // `->throwsNoExceptions()` only restated what an uncaught exception
    // already does (fail the test) — its real effect was silencing PHPUnit's
    // no-assertion detector without asserting the thing this test's own name
    // claims (generated-client-truth-and-session-safety D7). The actual
    // claim: the command establishes its OWN `TenantContextScope::runFor`
    // boundary and restores it (`TenantContextScope.php:62-68`) rather than
    // leaking a global tenant context into the process — proven by reading
    // the ambient resolver both BEFORE and AFTER the call, with no
    // `TenantContextScope::runFor()` established around this test itself
    // (exactly the console-command condition design D7 exists for).
    $resolver = app(TenantResolver::class);
    expect($resolver->getOrgId())->toBeNull();

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    expect($resolver->getOrgId())->toBeNull();
});

test('9 demo participants are written across P1, P2 and P4, every row carrying the target organization_id', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $participants = Participant::where('organization_id', $this->org->id)
        ->where('candidate_ref', 'like', DemoMarker::PREFIX.'%')
        ->get();

    expect($participants)->toHaveCount(9);
    expect($participants->pluck('organization_id')->unique()->all())->toBe([$this->org->id]);

    $refs = $participants->pluck('candidate_ref')->sort()->values()->all();
    expect($refs)->toBe([
        DemoMarker::PREFIX.'c-001',
        DemoMarker::PREFIX.'c-002',
        DemoMarker::PREFIX.'c-003',
        DemoMarker::PREFIX.'c-004',
        DemoMarker::PREFIX.'c-005',
        DemoMarker::PREFIX.'c-006',
        DemoMarker::PREFIX.'c-007',
        DemoMarker::PREFIX.'c-008',
        DemoMarker::PREFIX.'c-009',
    ]);
});

test('each participant lands on the intended lifecycle status', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $byRef = Participant::where('organization_id', $this->org->id)
        ->where('candidate_ref', 'like', DemoMarker::PREFIX.'%')
        ->get()
        ->keyBy('candidate_ref');

    expect($byRef[DemoMarker::PREFIX.'c-001']->status)->toBe('completato');
    expect($byRef[DemoMarker::PREFIX.'c-002']->status)->toBe('completato');
    expect($byRef[DemoMarker::PREFIX.'c-003']->status)->toBe('in_valutazione');
    expect($byRef[DemoMarker::PREFIX.'c-004']->status)->toBe('in_corso');
    expect($byRef[DemoMarker::PREFIX.'c-005']->status)->toBe('errore');
    expect($byRef[DemoMarker::PREFIX.'c-006']->status)->toBe('in_attesa');
    expect($byRef[DemoMarker::PREFIX.'c-007']->status)->toBe('completato');
    expect($byRef[DemoMarker::PREFIX.'c-008']->status)->toBe('in_attesa');
    expect($byRef[DemoMarker::PREFIX.'c-009']->status)->toBe('completato');
});

test('interview sessions carry question_index = pivot position, provider heygen, and a marked provider_session_ref', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $c001 = Participant::where('organization_id', $this->org->id)
        ->where('candidate_ref', DemoMarker::PREFIX.'c-001')
        ->firstOrFail();

    $sessions = TenantContextScope::runFor(
        $this->org->id,
        fn () => InterviewSession::where('participant_id', $c001->id)->orderBy('question_index')->get(),
    );

    expect($sessions)->toHaveCount(5);
    expect($sessions->pluck('question_index')->all())->toBe([0, 1, 2, 3, 4]);
    expect($sessions->pluck('competency_code')->all())->toBe(['PRS', 'STG', 'DRV', 'COM', 'COL']);

    foreach ($sessions as $session) {
        expect($session->organization_id)->toBe($this->org->id);
        expect($session->provider)->toBe('heygen');
        expect($session->provider_session_ref)->toStartWith(DemoMarker::PREFIX.'s-');
        expect($session->status)->toBe('completed');
    }
});

test('completed sessions carry 3 avatar and 3 candidate utterances, all scoped to the organization', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $c001 = Participant::where('organization_id', $this->org->id)
        ->where('candidate_ref', DemoMarker::PREFIX.'c-001')
        ->firstOrFail();

    $session = TenantContextScope::runFor(
        $this->org->id,
        fn () => InterviewSession::where('participant_id', $c001->id)->where('competency_code', 'PRS')->firstOrFail(),
    );

    $utterances = TenantContextScope::runFor(
        $this->org->id,
        fn () => Utterance::where('interview_session_id', $session->id)->orderBy('ts')->orderBy('id')->get(),
    );

    expect($utterances)->toHaveCount(6);
    expect($utterances->where('speaker', 'avatar'))->toHaveCount(3);
    expect($utterances->where('speaker', 'candidate'))->toHaveCount(3);
    expect($utterances->pluck('organization_id')->unique()->all())->toBe([$this->org->id]);
});

test('an in_corso participant has an in_corso session with no ended_at', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $c004 = Participant::where('organization_id', $this->org->id)
        ->where('candidate_ref', DemoMarker::PREFIX.'c-004')
        ->firstOrFail();

    $sessions = TenantContextScope::runFor(
        $this->org->id,
        fn () => InterviewSession::where('participant_id', $c004->id)->get(),
    );

    expect($sessions)->toHaveCount(3);
    $live = $sessions->firstWhere('status', 'in_corso');
    expect($live)->not->toBeNull();
    expect($live->ended_at)->toBeNull();
    expect($live->competency_code)->toBe('DRV');
});

test('an errore participant has one session with status and ended_reason = error', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $c005 = Participant::where('organization_id', $this->org->id)
        ->where('candidate_ref', DemoMarker::PREFIX.'c-005')
        ->firstOrFail();

    $sessions = TenantContextScope::runFor(
        $this->org->id,
        fn () => InterviewSession::where('participant_id', $c005->id)->get(),
    );

    expect($sessions)->toHaveCount(1);
    expect($sessions->first()->status)->toBe('error');
    expect($sessions->first()->ended_reason)->toBe('error');
});

test('an in_attesa participant has zero sessions', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $c006 = Participant::where('organization_id', $this->org->id)
        ->where('candidate_ref', DemoMarker::PREFIX.'c-006')
        ->firstOrFail();

    $count = TenantContextScope::runFor(
        $this->org->id,
        fn () => InterviewSession::where('participant_id', $c006->id)->count(),
    );

    expect($count)->toBe(0);
});
