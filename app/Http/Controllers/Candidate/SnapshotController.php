<?php

declare(strict_types=1);

namespace App\Http\Controllers\Candidate;

use App\Http\Controllers\Candidate\Concerns\ResolvesOwnedSession;
use App\Http\Controllers\Controller;
use App\Models\InterviewSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * SnapshotController (C7a — Interview Session Mechanics).
 *
 * Handles POST /api/candidate/interview/snapshot
 *
 * Accepts a base64-encoded JPEG proctoring snapshot, validates it, uploads it to S3,
 * and persists an InterviewSnapshot row with the server-generated s3_key and taken_at.
 *
 * Validation pipeline (order matters — design and spec mandated):
 * 1. Encoded length check BEFORE decode: strlen($image_base64) > max_encoded_bytes → 413.
 *    Checked first to avoid OOM on maliciously large inputs.
 * 2. Decode the base64 string.
 * 3. JPEG magic bytes check: first 3 bytes must be 0xFF 0xD8 0xFF → else 422.
 * 4. S3 upload: server-generated key {org_id}/{participant_id}/{session_id}/{uuid}.jpg.
 * 5. Persist InterviewSnapshot row; taken_at is server-set (now()).
 *
 * S3 key scheme (server-generated, design mandated):
 *   {organization_id}/{participant_id}/{session_id}/{snapshot_uuid}.jpg
 * No client-supplied path segments. No timestamp-collision risk.
 *
 * Response contract:
 * - 202 Accepted → snapshot uploaded to S3; InterviewSnapshot row persisted.
 * - 404 Not Found → session not owned by authenticated candidate.
 * - 413 Payload Too Large → encoded string length exceeds max_encoded_bytes.
 * - 422 Unprocessable → valid base64 but decoded bytes are not JPEG magic bytes.
 *
 * REQ: POST /snapshot — base64 JPEG to S3 (C7a)
 */
class SnapshotController extends Controller
{
    use ResolvesOwnedSession;

    /**
     * Upload a JPEG snapshot to S3 and persist the reference.
     *
     * resolveOwnedSession MUST be called FIRST — enforces participant_id + org isolation.
     * Encoded length check MUST happen BEFORE decoding to avoid OOM on oversized inputs.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'integer'],
            'image_base64' => ['required', 'string'],
        ]);

        // ── Step 1: Encoded length check BEFORE decode (design mandated, prevents OOM) ──
        // strlen() on a PHP string returns the byte count of the raw string.
        $encodedLength = strlen($validated['image_base64']);
        $maxEncoded = (int) config('interview.snapshot.max_encoded_bytes');

        if ($encodedLength > $maxEncoded) {
            return response()->json(
                ['message' => 'Snapshot payload exceeds maximum allowed size.'],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE
            );
        }

        // ── Step 2: Resolve session ownership (404 if not owned) ─────────────────────
        // Placed AFTER the encoded length check (pure in-memory, no security implications)
        // and BEFORE the decode / JPEG check / S3 write / DB write — so this is still the
        // first DB call and first mutation-relevant check. The design contract ("MUST be
        // invoked FIRST, before any DB mutation") is satisfied: no DB mutation has occurred.
        $session = $this->resolveOwnedSession((int) $validated['session_id']);

        // ── Step 3: Base64 decode ─────────────────────────────────────────────────────
        $decoded = base64_decode($validated['image_base64'], strict: false);

        if (strlen($decoded) < 3) {
            return response()->json(
                ['message' => 'Invalid base64 encoding.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // ── Step 4: JPEG magic bytes check (first 3 bytes must be 0xFF 0xD8 0xFF) ────
        // Design mandated: first 3 bytes === 0xFF 0xD8 0xFF → else 422.
        if (ord($decoded[0]) !== 0xFF || ord($decoded[1]) !== 0xD8 || ord($decoded[2]) !== 0xFF) {
            return response()->json(
                ['message' => 'Image must be a JPEG (invalid magic bytes).'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // ── Step 5: S3 upload ─────────────────────────────────────────────────────────
        // S3 key scheme: {org_id}/{participant_id}/{session_id}/{uuid}.jpg
        // All segments are server-generated integer IDs + UUID — no client input in the path.
        $snapshotUuid = (string) Str::uuid();
        $s3Key = implode('/', [
            $session->organization_id,
            $session->participant_id,
            $session->id,
            $snapshotUuid.'.jpg',
        ]);

        Storage::disk('s3')->put($s3Key, $decoded);

        // ── Step 6: Persist InterviewSnapshot ────────────────────────────────────────
        // taken_at is server-set; NOT in $fillable to prevent client forgery.
        // We use forceFill() to bypass the fillable guard for server-controlled fields,
        // then save() to persist the row. organization_id is stamped by TenantScoped.
        $snapshot = new InterviewSnapshot;
        $snapshot->forceFill([
            'interview_session_id' => $session->id,
            's3_key' => $s3Key,
            'taken_at' => now(),
        ]);
        $snapshot->save();

        return response()->json(null, Response::HTTP_ACCEPTED);
    }
}
