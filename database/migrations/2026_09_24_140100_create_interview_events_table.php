<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `interview_events` — the timeline `GET /v1/interviews/{id}/events` needs
 * (public-api step 5, G-34, SPEC.md §3.3). A tenant-scoped, append-only log
 * of small, non-PII events on a participant enrolment.
 *
 * `organization_id` FIRST in composites (D22 org-lead rule, same as every
 * other tenant-scoped table in this codebase) even though `participant_id`
 * alone would already narrow every real query to one tenant — stated
 * explicitly rather than relied on transitively, matching
 * `App\Support\Project\ProjectInterviewability`'s own documented discipline
 * for the identical reason.
 *
 * `data` (jsonb, nullable) — G-34: "Never PII or transcript text in `data`."
 * This migration cannot enforce that at the schema level; it is a discipline
 * every writer (`App\Actions\PublicApi\EnrolCandidate`, the embed exchange
 * controller, and step 6's session/readiness event writers) must uphold.
 *
 * No `updated_at` — an event, once recorded, is never revised (append-only,
 * mirroring `App\Models\AuditLog`'s own precedent for the same reason).
 * `occurred_at` is the event's own logical timestamp (may predate
 * `created_at` by the time a queued writer actually persists it); `id` is
 * the tie-breaker for two events sharing one `occurred_at` — the composite
 * index below orders on both, exactly what `GET /v1/interviews/{id}/events`
 * needs for its oldest-first ordering (G-12, the one exception to SPEC.md
 * §3.2's default `created_at desc, id desc`).
 *
 * ONE composite index, not two (gga round 4 finding 8 — this migration is
 * unreleased, edited in place): a separate `[organization_id,
 * participant_id]` D22 org-lead index existed alongside the ordering index
 * below, but every query that index could serve is already a strict
 * LEADING-COLUMN PREFIX of `interview_events_participant_ordering_index`
 * (`[organization_id, participant_id, occurred_at, id]`) — PostgreSQL uses a
 * wider B-tree index for a prefix-only lookup just as well as a dedicated
 * narrower one, so the second index bought no query this one could not
 * already serve, only extra write/storage cost on every insert. Removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_events', function (Blueprint $table): void {
            $table->id();

            $table->char('public_id', 26);

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->foreignId('participant_id')
                ->constrained('participants')
                ->cascadeOnDelete();

            $table->string('type');
            $table->timestampTz('occurred_at');
            $table->jsonb('data')->nullable();

            // useCurrent() at the DB level: this model runs with
            // `$timestamps = false` (append-only, no `updated_at` — see
            // this class's own docblock), so nothing in PHP stamps this
            // column; the database default is the only writer.
            $table->timestampTz('created_at')->useCurrent();

            $table->unique('public_id', 'interview_events_public_id_unique');

            // The events-list read path: oldest-first, paginated per
            // participant (G-12). Leads with organization_id (gga round 3
            // finding 3 — D22 org-lead rule, same discipline as every other
            // tenant-scoped composite index in this codebase), then
            // participant_id — which ALSO serves the plain tenant-scoped
            // `[organization_id, participant_id]` lookup as a leading
            // prefix, so no separate index for that shape exists (gga
            // round 4 finding 8, see this class's own docblock) — then the
            // ordering columns themselves.
            $table->index(['organization_id', 'participant_id', 'occurred_at', 'id'], 'interview_events_participant_ordering_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_events');
    }
};
