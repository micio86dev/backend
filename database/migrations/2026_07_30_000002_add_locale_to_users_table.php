<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users.locale (C12 — Notifications & Reminders, D7).
 *
 * There is no recipient language preference anywhere today — verified across
 * both migrations that define this table. Notifications are the first thing
 * BEAI sends TO a human, and the i18n mandate (it/en) applies to them.
 *
 * Nullable on purpose: NULL means "no preference", which resolves to
 * config('app.fallback_locale'). An unsupported value resolves the same way, so
 * this column can never wedge a send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale', 5)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
