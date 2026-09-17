<?php

declare(strict_types=1);

/**
 * Z15 (R4-turn-kind-check-lock, REQUIRED BEFORE ARCHIVE): the
 * `utterances_turn_kind_check` CHECK constraint (`2026_09_16_110000_add_turn_kind_to_utterances`)
 * is now added `NOT VALID` and validated in a SEPARATE statement — the
 * standard Postgres zero-downtime pattern, avoiding an ACCESS EXCLUSIVE
 * lock for the duration of scanning the whole table. This proves the
 * split-statement migration leaves the constraint in the SAME fully-
 * enforced end state as a plain `ADD CONSTRAINT` would (`convalidated =
 * true` — never left dangling as unenforced `NOT VALID` forever), and that
 * it still actually refuses an illegal value.
 */

use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;

function z15UtteranceFixture(): array
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('in_corso')->create();
    $session = InterviewSession::factory()->create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'framework_version_id' => $fv->id,
    ]);

    return [$org->id, $session->id];
}

test('Z15: the migration runs outside a transaction — otherwise the NOT VALID/VALIDATE split has no effect', function (): void {
    // gga review finding, blocking: Laravel wraps every migration in ONE
    // transaction by default, and Postgres DDL is transactional — the
    // ACCESS EXCLUSIVE lock `ADD COLUMN` takes stays held through
    // `VALIDATE CONSTRAINT` too unless the migration opts out. A structural
    // guard, not a behavioral one: this is the ONE property that turns the
    // split into a real lock-avoidance measure rather than a no-op.
    $migration = require database_path('migrations/2026_09_16_110000_add_turn_kind_to_utterances.php');

    expect($migration->withinTransaction)->toBeFalse();
});

test('Z15: utterances_turn_kind_check is fully VALIDATED after migration, never left dangling as NOT VALID', function (): void {
    $validated = DB::selectOne(
        "SELECT convalidated FROM pg_constraint WHERE conname = 'utterances_turn_kind_check'"
    );

    expect($validated)->not->toBeNull();
    expect($validated->convalidated)->toBeTrue();
});

test('Z15: turn_kind accepts primary and follow_up', function (): void {
    [$orgId, $sessionId] = z15UtteranceFixture();

    foreach (['primary', 'follow_up'] as $kind) {
        DB::table('utterances')->insert([
            'organization_id' => $orgId,
            'interview_session_id' => $sessionId,
            'speaker' => 'avatar',
            'text' => 'x',
            'ts' => now(),
            'turn_kind' => $kind,
        ]);
    }

    expect(DB::table('utterances')->where('interview_session_id', $sessionId)->count())->toBe(2);
});

test('Z15: turn_kind refuses any value outside {primary, follow_up}', function (): void {
    [$orgId, $sessionId] = z15UtteranceFixture();

    assertPostgresConstraintViolation(
        fn () => DB::table('utterances')->insert([
            'organization_id' => $orgId,
            'interview_session_id' => $sessionId,
            'speaker' => 'avatar',
            'text' => 'x',
            'ts' => now(),
            'turn_kind' => 'bogus',
        ]),
        '23514',
        'utterances_turn_kind_check',
    );
});
