<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `profile_photo_path` to `users` (user-avatar-image, design D7).
 *
 * Explicit nullable string, mirrors `..._add_password_changed_at_to_users_table.php`'s
 * shape. Holds the full server-generated storage key
 * (`profile-photos/{user_id}/{uuid}.{jpg|png}`) — never a client-supplied
 * value (design D1/D2).
 *
 * `profile_photo_path IS NULL` means "no photo", which is every existing
 * row's true state and the state the initials fallback already handles
 * correctly. No backfill — a backfill would have to invent data.
 *
 * REQ: The Photo Object Key Is Server-Generated, Never Client-Supplied
 * (openspec/changes/user-avatar-image/specs/user-self-service/spec.md)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('profile_photo_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('profile_photo_path');
        });
    }
};
