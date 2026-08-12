<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `deactivated_at` to `users` (backoffice-missing-pages D5).
 *
 * Explicit nullable column, deliberately NOT Laravel `SoftDeletes` (`deleted_at`):
 * a `SoftDeletes` global scope would hide the row from every query — including
 * audit-log author joins and the user list itself, where a deactivated user is
 * shown badged, not hidden. Hard delete is also rejected: `users.organization_id`
 * is `restrictOnDelete` and audit trails would dangle.
 *
 * `deactivated_at IS NULL` means "active" — every pre-existing row is already
 * correct with no backfill needed.
 *
 * REQ: Soft Deactivation, Not Hard Delete (backoffice-missing-pages D5)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('deactivated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('deactivated_at');
        });
    }
};
