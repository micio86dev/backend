<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every candidate has an email address, and it is required.
 *
 * CLAUDE.md ruling 8, reversed 2026-09-01. The original ruling — no candidate
 * contact data at all — was ratified on the assumption that every candidate
 * arrives through an SSO ingress the calling system owns. Operators also create
 * candidates directly in the backoffice, and for those there is no calling
 * system to send anything: the candidate was created and never told.
 *
 * IDENTITY, NOT JUST AN ADDRESS. The email is what makes two enrolments the
 * same person. `participants` stays the per-project enrolment; inviting one
 * human to two projects, or to two organizations, produces two rows with one
 * address, and that is the point rather than a duplicate.
 *
 * NOT NULL IN ONE MIGRATION, WITH A BACKFILL
 * -------------------------------------------
 * Rows already exist. Adding a required column to a populated table fails, and
 * the usual workaround — leave it nullable and "tighten it later" — never gets
 * tightened, so the application spends forever handling a null the domain says
 * cannot happen. The backfill below synthesises a deterministic,
 * non-deliverable placeholder from the row's own `candidate_ref`, so the
 * constraint is real from the first minute.
 *
 * `@invalid.beai.local` is deliberately undeliverable. `.local` is reserved by
 * RFC 6762 and resolves nowhere, so a placeholder can never accidentally reach
 * a real inbox — and it is greppable, so the operators who need to fix these
 * rows can find every one of them with a single query.
 *
 * UNIQUE PER PROJECT, NOT GLOBAL. Inviting the same person twice to one project
 * is a mistake worth refusing at the database. Inviting them to a second
 * project, or to another organization entirely, is the requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            $table->string('email')->nullable()->after('display_name');
        });

        // Deterministic, so re-running this on a copy of the same data
        // produces the same placeholders rather than a second set of them.
        DB::statement(<<<'SQL'
            UPDATE participants
               SET email = candidate_ref || '@invalid.beai.local'
             WHERE email IS NULL
        SQL);

        DB::statement('ALTER TABLE participants ALTER COLUMN email SET NOT NULL');

        // Leads with project_id to match the existing
        // `participants_project_id_candidate_ref_unique` shape, and because
        // every lookup that uses it starts from a project.
        DB::statement('CREATE UNIQUE INDEX participants_project_id_email_unique ON participants (project_id, email)');

        // Reading "every enrolment for this address" is what the invite flow
        // does to pre-fill a name, and it is always org-scoped — a global
        // index on email alone would serve a query no endpoint is allowed to
        // make.
        DB::statement('CREATE INDEX participants_organization_id_email_index ON participants (organization_id, email)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS participants_organization_id_email_index');
        DB::statement('DROP INDEX IF EXISTS participants_project_id_email_unique');

        Schema::table('participants', function (Blueprint $table): void {
            $table->dropColumn('email');
        });
    }
};
