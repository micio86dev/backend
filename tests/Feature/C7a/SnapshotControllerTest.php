<?php

declare(strict_types=1);

/**
 * SnapshotController feature tests (C7a — Interview Session Mechanics).
 *
 * Tests POST /api/candidate/interview/snapshot
 *
 * Asserts:
 * - Valid base64 JPEG under limit → HTTP 202; S3 key = {org_id}/{participant_id}/{session_id}/{uuid}.jpg;
 *   InterviewSnapshot row with correct s3_key and server-set taken_at.
 * - Encoded string length > max_encoded_bytes → HTTP 413; no S3 write; no DB insert; no decode attempted.
 * - Valid base64 but decodes to non-JPEG bytes → HTTP 422; no S3 write; no DB insert.
 * - session_id from different org → HTTP 404.
 *
 * Uses Storage::fake() — no argument, resolves the configured default disk
 * (object-storage-fix D3) — to intercept writes.
 *
 * Tasks: 12.1 (RED)
 * REQ: POST /snapshot — base64 JPEG to the configured disk (C7a; object-storage-fix)
 */

use App\Models\InterviewSession;
use App\Models\InterviewSnapshot;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Storage;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function snapshotOrg(): Organization
{
    return Organization::factory()->create();
}

function snapshotProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function snapshotParticipant(Organization $org, Project $project, string $status = 'in_corso', string $suffix = ''): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'snap-'.($suffix ?: uniqid()),
        'display_name' => 'Snapshot Test',
        'status' => $status,
    ]);
    $p->save();

    return $p->fresh();
}

function snapshotSession(Organization $org, Participant $participant, Project $project, string $status = 'in_corso'): InterviewSession
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'status' => $status,
    ]);
}

function snapshotBearer(Participant $participant): string
{
    return CandidateTokenFactory::mintCandidateToken($participant);
}

/**
 * Build a minimal valid JPEG base64 string.
 * Real JPEG magic bytes 0xFF 0xD8 0xFF + padding to simulate content.
 */
function validJpegBase64(int $extraBytes = 100): string
{
    $jpegHeader = "\xFF\xD8\xFF\xE0";
    $padding = str_repeat("\x00", $extraBytes);

    return base64_encode($jpegHeader.$padding);
}

/**
 * Build a base64 string that is NOT a JPEG (PNG magic bytes instead).
 */
function nonJpegBase64(): string
{
    $pngHeader = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A";

    return base64_encode($pngHeader.str_repeat("\x00", 50));
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('POST /snapshot with valid JPEG under limit → 202; S3 key correct; InterviewSnapshot row persisted', function (): void {
    Storage::fake();

    $org = snapshotOrg();
    $project = snapshotProject($org);
    $participant = snapshotParticipant($org, $project, 'in_corso');
    $session = snapshotSession($org, $participant, $project, 'in_corso');
    $token = snapshotBearer($participant);

    $imageBase64 = validJpegBase64();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/snapshot', [
            'session_id' => $session->id,
            'image_base64' => $imageBase64,
        ]);

    $response->assertStatus(202);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    // One InterviewSnapshot row must be persisted
    $snapshots = InterviewSnapshot::where('interview_session_id', $session->id)->get();
    expect($snapshots)->toHaveCount(1);

    $snapshot = $snapshots->first();

    // S3 key must follow the scheme: {org_id}/{participant_id}/{session_id}/{uuid}.jpg
    $s3Key = $snapshot->s3_key;
    expect($s3Key)->toStartWith("{$org->id}/{$participant->id}/{$session->id}/");
    expect($s3Key)->toEndWith('.jpg');

    // The key segment after the prefix must look like a UUID
    $parts = explode('/', $s3Key);
    expect($parts)->toHaveCount(4);
    $uuidPart = str_replace('.jpg', '', $parts[3]);
    expect($uuidPart)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');

    // S3 write must have occurred
    Storage::disk()->assertExists($s3Key);

    // taken_at must be server-set (not null)
    expect($snapshot->taken_at)->not->toBeNull();
});

test('POST /snapshot with encoded length exceeding max_encoded_bytes → 413; no S3 write; no DB insert', function (): void {
    Storage::fake();

    $org = snapshotOrg();
    $project = snapshotProject($org);
    $participant = snapshotParticipant($org, $project, 'in_corso');
    $session = snapshotSession($org, $participant, $project, 'in_corso');
    $token = snapshotBearer($participant);

    // Build a base64 string exceeding max_encoded_bytes (~2.7 MB)
    $maxBytes = config('interview.snapshot.max_encoded_bytes');
    $overLimit = str_repeat('A', $maxBytes + 1);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $countBefore = InterviewSnapshot::where('interview_session_id', $session->id)->count();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/snapshot', [
            'session_id' => $session->id,
            'image_base64' => $overLimit,
        ]);

    $response->assertStatus(413);

    // No S3 writes must have occurred
    Storage::disk()->assertDirectoryEmpty('');

    // No DB insert must have occurred
    $countAfter = InterviewSnapshot::where('interview_session_id', $session->id)->count();
    expect($countAfter)->toBe($countBefore, 'No InterviewSnapshot must be persisted on oversized input');
});

test('POST /snapshot accepts a data-URL-prefixed payload and stores the decoded JPEG', function (): void {
    // `canvas.toDataURL('image/jpeg')` yields "data:image/jpeg;base64,<payload>", and
    // a client that forwards it verbatim used to get a hard 422 on EVERY snapshot.
    // The prefix does not survive base64_decode(strict: false) as a rejection — every
    // character of "dataimagejpegbase64" is in the base64 alphabet, so the decode
    // silently MISALIGNED and the magic-byte check failed on shifted bytes.
    //
    // The client now strips it, but the endpoint tolerates the prefix rather than
    // relying on that: an alignment failure this quiet must not be one deploy away.
    Storage::fake();

    $org = snapshotOrg();
    $project = snapshotProject($org);
    $participant = snapshotParticipant($org, $project, 'in_corso');
    $session = snapshotSession($org, $participant, $project, 'in_corso');
    $token = snapshotBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/snapshot', [
            'session_id' => $session->id,
            'image_base64' => 'data:image/jpeg;base64,'.validJpegBase64(),
        ]);

    $response->assertStatus(202);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $snapshot = InterviewSnapshot::where('interview_session_id', $session->id)->first();
    expect($snapshot)->not->toBeNull();

    // The stored object must be the DECODED image, with no prefix bytes smuggled in.
    $stored = Storage::disk()->get($snapshot->s3_key);
    expect(substr($stored, 0, 3))->toBe("\xFF\xD8\xFF");
});

test('POST /snapshot rejects a data-URL-prefixed payload whose decoded bytes are not JPEG', function (): void {
    // Tolerating the prefix must not weaken the magic-byte gate behind it.
    Storage::fake();

    $org = snapshotOrg();
    $project = snapshotProject($org);
    $participant = snapshotParticipant($org, $project, 'in_corso');
    $session = snapshotSession($org, $participant, $project, 'in_corso');
    $token = snapshotBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/snapshot', [
            'session_id' => $session->id,
            'image_base64' => 'data:image/jpeg;base64,'.nonJpegBase64(),
        ]);

    $response->assertStatus(422);
    Storage::disk()->assertDirectoryEmpty('');
    expect(InterviewSnapshot::where('interview_session_id', $session->id)->count())->toBe(0);
});

test('POST /snapshot with valid base64 but non-JPEG bytes → 422; no S3 write; no DB insert', function (): void {
    Storage::fake();

    $org = snapshotOrg();
    $project = snapshotProject($org);
    $participant = snapshotParticipant($org, $project, 'in_corso');
    $session = snapshotSession($org, $participant, $project, 'in_corso');
    $token = snapshotBearer($participant);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $countBefore = InterviewSnapshot::where('interview_session_id', $session->id)->count();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/snapshot', [
            'session_id' => $session->id,
            'image_base64' => nonJpegBase64(),
        ]);

    $response->assertStatus(422);

    // No S3 write
    Storage::disk()->assertDirectoryEmpty('');

    // No DB insert
    $countAfter = InterviewSnapshot::where('interview_session_id', $session->id)->count();
    expect($countAfter)->toBe($countBefore, 'No InterviewSnapshot must be persisted on non-JPEG input');
});

test('POST /snapshot with session_id from different org → 404; no S3 write; no DB insert', function (): void {
    Storage::fake();

    $orgA = snapshotOrg();
    $orgB = snapshotOrg();
    $projectA = snapshotProject($orgA);
    $projectB = snapshotProject($orgB);
    $participantA = snapshotParticipant($orgA, $projectA, 'in_corso', 'a');
    $participantB = snapshotParticipant($orgB, $projectB, 'in_corso', 'b');

    // Session belongs to orgB/participantB
    $sessionB = snapshotSession($orgB, $participantB, $projectB, 'in_corso');

    // Authenticated as participantA (orgA)
    $token = snapshotBearer($participantA);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgB->id);
    $resolver->setBypass(false);
    $countBefore = InterviewSnapshot::where('interview_session_id', $sessionB->id)->count();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/snapshot', [
            'session_id' => $sessionB->id,
            'image_base64' => validJpegBase64(),
        ]);

    $response->assertNotFound();

    // No S3 write
    Storage::disk()->assertDirectoryEmpty('');

    // No DB insert
    $resolver->setOrgId($orgB->id);
    $countAfter = InterviewSnapshot::where('interview_session_id', $sessionB->id)->count();
    expect($countAfter)->toBe($countBefore, 'No InterviewSnapshot must be persisted for cross-tenant attempt');
});
