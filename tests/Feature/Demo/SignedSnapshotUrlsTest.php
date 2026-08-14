<?php

declare(strict_types=1);

/**
 * `beai:demo-seed` — signed snapshot URLs actually resolve through the real
 * admin endpoint (spec: "Snapshot Rows Reference Objects That Actually Exist
 * on Disk" §"A signed snapshot URL resolves").
 *
 * `SnapshotObjectsTest` asserts object existence directly against
 * `Storage::fake()` — necessary, but it never proves an operator can
 * actually SEE a snapshot: that requires going through
 * `SessionReviewController::show()` → `signedSnapshots()`, RBAC-authorized,
 * exactly as the backoffice does. This is that proof, for a real demo
 * session (c-001's PRS session, design volume table).
 */

use App\Models\InterviewSession;
use App\Models\InterviewSnapshot;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\User;
use App\Support\Demo\DemoMarker;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    Storage::fake();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

/**
 * beai:demo-seed never creates a user (design D2/D7) — the operator reading
 * a demo session review already has their own account, exactly like this.
 */
function demoReviewerToken(Organization $org, string $role = 'admin'): string
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return (string) auth('api')->login($user);
}

test('an RBAC-authorized admin reads real, non-empty signed snapshot URLs for a demo session', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $token = demoReviewerToken($this->org);

    [$sessionId, $rawKeys] = TenantContextScope::runFor($this->org->id, function (): array {
        $c001 = Participant::where('candidate_ref', DemoMarker::PREFIX.'c-001')->firstOrFail();

        $session = InterviewSession::where('participant_id', $c001->id)
            ->where('competency_code', 'PRS')
            ->firstOrFail();

        $keys = InterviewSnapshot::where('interview_session_id', $session->id)->pluck('s3_key')->all();

        return [$session->id, $keys];
    });

    expect($rawKeys)->toHaveCount(2);

    $response = $this->withToken($token)->getJson("/api/interview-sessions/{$sessionId}/review");

    $response->assertOk();

    $snapshots = $response->json('data.snapshots');

    // 2 snapshots per completed session for c-001 (design volume table).
    expect($snapshots)->toHaveCount(2);

    foreach ($snapshots as $snapshot) {
        expect($snapshot['url'])->toBeString()->not->toBe('');
        expect($snapshot)->not->toHaveKey('s3_key');
    }

    // No raw storage key anywhere in the payload — same discipline
    // SessionReviewTest.php already asserts for non-demo sessions.
    $body = json_encode($response->json());

    foreach ($rawKeys as $rawKey) {
        expect($body)->not->toContain((string) $rawKey);
    }
});
