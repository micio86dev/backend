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
 * Accepts a base64-encoded JPEG proctoring snapshot, validates it, uploads it to the
 * disk resolved through the single storage configuration point (`FILESYSTEM_DISK`),
 * and persists an InterviewSnapshot row with the server-generated s3_key and taken_at.
 * `s3_key` is a legacy column name — storage is disk-agnostic (object-storage-fix);
 * the rename is a separate, out-of-scope change.
 *
 * Validation pipeline (order matters — design and spec mandated):
 * 1. Encoded length check BEFORE decode: strlen($image_base64) > max_encoded_bytes → 413.
 *    Checked first to avoid OOM on maliciously large inputs.
 * 2. Decode the base64 string.
 * 3. JPEG magic bytes check: first 3 bytes must be 0xFF 0xD8 0xFF → else 422.
 * 4. Upload to the configured disk: server-generated key {org_id}/{participant_id}/{session_id}/{uuid}.jpg.
 * 5. Persist InterviewSnapshot row; taken_at is server-set (now()).
 *
 * Object key scheme (server-generated, design mandated):
 *   {organization_id}/{participant_id}/{session_id}/{snapshot_uuid}.jpg
 * No client-supplied path segments. No timestamp-collision risk.
 *
 * Response contract:
 * - 202 Accepted → snapshot uploaded; InterviewSnapshot row persisted.
 * - 404 Not Found → session not owned by authenticated candidate.
 * - 413 Payload Too Large → encoded string length exceeds max_encoded_bytes.
 * - 422 Unprocessable → valid base64 but decoded bytes are not JPEG magic bytes.
 *
 * REQ: POST /snapshot — base64 JPEG to the configured disk (C7a; object-storage-fix)
 */
class SnapshotController extends Controller
{
    use ResolvesOwnedSession;

    /**
     * Upload a JPEG snapshot to the configured disk and persist the reference.
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
        // Tolerate a `data:image/jpeg;base64,` prefix before decoding.
        //
        // This is not cosmetic leniency. `canvas.toDataURL()` returns the prefixed
        // form, and decoding it with strict:false does NOT reject: every character of
        // "dataimagejpegbase64" is itself in the base64 alphabet, so the prefix is
        // CONSUMED, the payload shifts out of alignment, and the magic-byte check
        // below fails on bytes that were a perfectly good JPEG a moment earlier.
        // The resulting 422 blames the image for a framing mistake.
        $decoded = base64_decode($this->stripDataUrlPrefix($validated['image_base64']), strict: false);

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

        // ── Step 5: Upload to the configured disk ────────────────────────────────────
        // Object key scheme: {org_id}/{participant_id}/{session_id}/{uuid}.jpg
        // All segments are server-generated integer IDs + UUID — no client input in the path.
        $snapshotUuid = (string) Str::uuid();
        $s3Key = implode('/', [
            $session->organization_id,
            $session->participant_id,
            $session->id,
            $snapshotUuid.'.jpg',
        ]);

        // No argument: the disk is resolved through the single storage
        // configuration point (env FILESYSTEM_DISK), never named here. This
        // is what makes "writer and purge disagree about the disk"
        // syntactically impossible rather than merely avoided by convention
        // (D1; enforced by the arch guard at tests/Arch/Storage — D2).
        Storage::put($s3Key, $decoded);

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

    /**
     * Strip a leading `data:<mediatype>;base64,` prefix, if present.
     *
     * Only the well-formed base64 data-URL header is removed; anything else is
     * returned untouched so it still reaches the magic-byte gate. The length check
     * in step 1 runs against the ORIGINAL string, so this cannot be used to slip a
     * larger payload past the size limit.
     */
    private function stripDataUrlPrefix(string $value): string
    {
        if (preg_match('#^data:[\w.+-]+/[\w.+-]+;base64,#i', $value, $matches) !== 1) {
            return $value;
        }

        return substr($value, strlen($matches[0]));
    }
}
