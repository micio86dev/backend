<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add nullable `provider_override` column to `projects` (C7a — Interview Session Mechanics).
 *
 * Per-project provider override: when set, overrides the env INTERVIEW_PROVIDER default.
 * Nullable — falls back to env default when null (FIX-6).
 * Named `provider_override` to distinguish from any future non-override `provider` semantics.
 * Reversible: down() drops the column cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            // Nullable — when null, env default applies (FIX-6: named provider_override, not provider).
            $table->string('provider_override')->nullable()->after('webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('provider_override');
        });
    }
};
