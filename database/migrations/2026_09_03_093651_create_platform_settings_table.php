<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide settings — the knobs that belong to BEAI itself.
 *
 * DELIBERATELY NOT TENANT-SCOPED, and that is the whole reason it is a
 * separate table rather than more columns on `organizations`. Everything in
 * this database carries an `organization_id` and a global scope to match; a
 * row here belongs to the PLATFORM, and only a superadmin — who belongs to no
 * organization — may write one.
 *
 * The first setting is the per-competency question cap, which is a property of
 * the assessment method rather than of a client: a `standard` interview opens
 * with at most one predefined question because the adaptivity is the product,
 * and a tenant able to raise that would turn a BARS interview into a
 * questionnaire while still calling it a BARS interview.
 *
 * KEY-VALUE, not a column per setting. The alternative was a wide singleton
 * row, which needs a migration for every knob and a deploy to add one; the
 * cost is that reads go through a typed accessor
 * (`App\Support\Settings\PlatformSettings`) instead of a property, which is
 * where the defaults and the validation live anyway.
 *
 * `key` is the primary key. There is exactly one row per setting, forever —
 * writes upsert — so an id column would only invite a second row for the same
 * key and a "which one wins" question with no good answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->string('key', 191)->primary();

            // JSON rather than a string: the first setting is itself a map
            // (`{standard: 1, potential: 4}`), and a store that could only hold
            // scalars would have every structured setting encoded by hand at
            // each call site.
            $table->json('value');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
