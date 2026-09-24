<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `interview_recordings` — backing storage for `GET /v1/interviews/{id}/recording`
 * (public-api step 6, G-01, SPEC.md §3.3 "Audio only"). One row per
 * participant enrolment (`unique(participant_id)`), holding a pointer to the
 * audio object on the configured disk — never the audio bytes themselves.
 *
 * G-01 (binding, open on provider ingestion): step 6 builds ONLY the storage
 * and the signed read endpoint. No provider integration writes rows here yet
 * — a live interview has none, and `GET /recording` answers
 * `404 recording_not_ready`. Step 9's mock provider is the first writer.
 *
 * `organization_id` FIRST in the composite lookup, mirroring
 * `interview_events`'s own D22 org-lead discipline (this table's docblock)
 * for the identical reason — every real query already narrows by
 * `participant_id` alone, but the tenant column is named explicitly rather
 * than relied on transitively.
 *
 * `object_key` — a PATH on the configured disk (`recordings/{org_id}/{participant_id}/…`),
 * never a URL: the disk differs per environment (local in dev/test, S3-
 * compatible in production, CLAUDE.md "Object storage" row), and a signed
 * URL is minted fresh, per read, from this key — never stored (the SAME
 * "never persist a URL, always mint one" discipline `App\Support\
 * ProfilePhotoUrlSigner` and `App\Http\Controllers\Api\SessionReviewController`
 * already apply to their own object keys).
 *
 * No `public_id`/`HasPublicId` — this row is never addressed directly by an
 * external id; it is always resolved FROM the already-public-id'd
 * `participant_id`, exactly like `interview_sessions`/`utterances` are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_recordings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->foreignId('participant_id')
                ->constrained('participants')
                ->cascadeOnDelete();

            $table->string('object_key', 2048);
            $table->string('format', 64);
            $table->unsignedInteger('duration_seconds');
            $table->unsignedBigInteger('size_bytes');

            $table->timestampsTz();

            $table->unique('participant_id', 'interview_recordings_participant_id_unique');
            $table->index(['organization_id', 'participant_id'], 'interview_recordings_org_participant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_recordings');
    }
};
