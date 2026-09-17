<?php

declare(strict_types=1);

/**
 * Z16 (R4-utterance-lock-latency, REQUIRED BEFORE ARCHIVE): `UtteranceController::store()`
 * holds `SELECT ... FOR UPDATE` on the session row across classify-then-
 * insert. `SET LOCAL lock_timeout` bounds how long a request waits for
 * another request's (or a stuck connection's) lock on the SAME session row
 * — without it, Postgres has NO default timeout on a row lock wait, so a
 * wedged concurrent writer would stall this request (and the live turn
 * loop) indefinitely.
 *
 * Proven with a genuinely SEPARATE Postgres session holding the SAME row's
 * lock open while our own HTTP request is itself blocked waiting for it —
 * the only way to reproduce the exact contention a single PHP thread cannot
 * produce on its own. Unlike the catalogue suite's race-actor tests, this
 * does not need a separate OS PROCESS: Postgres lock waits block at the
 * SERVER, not the PHP, layer, so a second, genuinely independent PDO
 * connection within the SAME test process is sufficient — Postgres does not
 * care that both connections happen to be driven by the same PHP script.
 *
 * The fixture MUST be genuinely committed (not left inside
 * `RefreshDatabase`'s own wrapping transaction) for the second connection to
 * see it at all — `DB::commit()` ends that wrapping transaction for real,
 * `DB::beginTransaction()` immediately reopens an (empty) one so the test
 * framework's own teardown rollback has something to close. The now-real
 * fixture rows are deleted explicitly in `finally`, cascading from
 * `organizations`.
 *
 * TWO organizations get committed for real here, not one: `TenantScoped`
 * (`app/Models/Concerns/TenantScoped.php`) unconditionally overwrites
 * `organization_id` on `creating` from the resolver, but `Project::factory()`
 * defaults `framework_version_id` to `FrameworkVersion::factory()`, whose OWN
 * default is `'organization_id' => Organization::factory()` — that nested
 * factory relationship resolves (a real INSERT) to obtain an id BEFORE
 * `TenantScoped` discards it in favour of `$org->id`. The result is a second,
 * bare organization with no rows pointing at it at all, created as a pure
 * side effect. Under ordinary `RefreshDatabase` rollback this is invisible
 * (everything disappears together); once this test commits for real, it is a
 * genuine orphan that `where('id', $org->id)` alone would never find.
 * `$committedOrgIds` snapshots every organization that exists right before
 * the real commit, so cleanup deletes all of them regardless of how many a
 * factory chain happens to create.
 */

use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;

test('Z16: a request waiting on a genuinely locked session row times out with a clean 503, never hangs', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active']);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'z16-lock-'.uniqid(),
        'display_name' => 'Z16 Lock Test',
        'email' => uniqid('z16-').'@example.test',
        'status' => 'in_corso',
    ]);
    $participant->save();

    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'status' => 'in_corso',
    ]);

    $token = CandidateTokenFactory::mintCandidateToken($participant);

    // Snapshot every organization that exists right now — see the class
    // docblock: Project::factory()'s default FrameworkVersion::factory()
    // silently creates and discards a SECOND, bare organization alongside
    // $org, and cleanup below must find both.
    $committedOrgIds = Organization::pluck('id')->all();

    // Commit the fixture for REAL — a genuinely separate connection cannot
    // see it while it is still inside RefreshDatabase's own wrapping
    // transaction. Reopen immediately so the test framework's own teardown
    // rollback has a transaction to close.
    DB::commit();
    DB::beginTransaction();

    $config = config('database.connections.pgsql');
    $pdo = new PDO(
        "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        $config['username'],
        $config['password'],
    );
    $pdo->beginTransaction();
    // Genuinely holds the row lock open, uncommitted, for the ENTIRE
    // duration of the try block below.
    $pdo->query("SELECT id FROM interview_sessions WHERE id = {$session->id} FOR UPDATE");

    try {
        $start = microtime(true);

        $response = $this
            ->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/candidate/interview/utterance', [
                'session_id' => $session->id,
                'speaker' => 'candidate',
                'text' => 'This should time out waiting for the lock.',
                'ts' => now()->toIso8601String(),
            ]);

        $elapsedMs = (microtime(true) - $start) * 1000;

        $response->assertStatus(503);
        expect($response->json('error'))->toBe('utterance_lock_timeout');

        // Bounded on BOTH sides: genuinely waited close to the configured
        // timeout (not an instant, unrelated failure), and genuinely capped
        // (not hanging — the whole point of Z16).
        expect($elapsedMs)->toBeGreaterThanOrEqual(1800.0);
        expect($elapsedMs)->toBeLessThan(6000.0);
    } finally {
        $pdo->rollBack();

        // Manual cleanup — these rows are genuinely committed now, so
        // RefreshDatabase's own rollback at teardown never touches them.
        // Cascades from organizations to framework_versions/projects/
        // participants/interview_sessions. whereIn(), not where('id', $org->id):
        // see the class docblock — Project::factory() commits a second, bare
        // organization alongside $org, and only $committedOrgIds accounts for
        // both.
        //
        // This delete itself runs inside the transaction DB::beginTransaction()
        // reopened above, which RefreshDatabase's own teardown then rolls
        // back — so without an explicit DB::commit() here, the delete would
        // be silently undone and $org would leak right back. Reopen once
        // more immediately after, exactly as above, so teardown still has a
        // transaction to close.
        DB::table('organizations')->whereIn('id', $committedOrgIds)->delete();
        DB::commit();
        DB::beginTransaction();
    }
});
