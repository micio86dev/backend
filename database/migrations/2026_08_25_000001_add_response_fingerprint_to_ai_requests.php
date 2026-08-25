<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ai_requests diagnostic fingerprint (C13, scoring-failure-containment D6).
 *
 * Three ADDITIVE, NULLABLE columns — derived signals only, never raw
 * response text:
 *   - response_bytes:  strlen() of the response body.
 *   - response_fenced: whether ResponseEnvelopeStripper detected a fence.
 *   - response_sha256: a non-reversible digest of the response, for grouping
 *     identical failure shapes across calls/competencies.
 *
 * The CHECK constraint on response_sha256 IS the enforcement mechanism, not
 * documentation of one (D6): `unsignedInteger` and `boolean` cannot hold a
 * substring at all, and the one text-capable column is fixed at 64
 * characters and constrained to lowercase hex. No fragment of a scoring
 * response satisfies that regex — the illegal state (a raw excerpt landing
 * in this column) is unrepresentable.
 *
 * No data precondition on rollback: existing rows are NULL, and NULL passes
 * `response_sha256 IS NULL OR response_sha256 ~ '^[0-9a-f]{64}$'` by
 * construction. down() drops the constraint, then the columns.
 *
 * data-retention/spec.md needs NO amendment and receives none in this
 * change: none of these three columns is candidate-derived readable
 * content (D6) — a byte count and a boolean carry no transcript bits, and a
 * SHA-256 of a never-stored input is non-reversible and useless as an
 * identifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->unsignedInteger('response_bytes')->nullable()->after('finish_reason');
            $table->boolean('response_fenced')->nullable()->after('response_bytes');
            $table->char('response_sha256', 64)->nullable()->after('response_fenced');
        });

        DB::statement(
            "ALTER TABLE ai_requests ADD CONSTRAINT ai_requests_response_sha256_format_check
             CHECK (response_sha256 IS NULL OR response_sha256 ~ '^[0-9a-f]{64}$')"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ai_requests DROP CONSTRAINT IF EXISTS ai_requests_response_sha256_format_check');

        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->dropColumn(['response_bytes', 'response_fenced', 'response_sha256']);
        });
    }
};
