<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `password_changed_at` to `users` (user-profile-self-service, design D3).
 *
 * Explicit nullable column, mirrors `..._add_deactivated_at_to_users_table.php`.
 * Compared against a token's `iat` claim by `RejectStaleCredentials` to revoke
 * every session other than the one that performed the change — there is no
 * per-user token registry to enumerate individual jtis against (design D3).
 *
 * `password_changed_at IS NULL` means "never changed" — every pre-existing
 * row and every live token stays valid across deploy, no backfill needed.
 *
 * REQ: Password Change Revokes Other Sessions, Not The Acting One
 * (openspec/changes/user-profile-self-service/specs/user-self-service/spec.md)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('password_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_changed_at');
        });
    }
};
