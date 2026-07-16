<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds organization_id (nullable FK → organizations, restrictOnDelete)
     * and is_superadmin (boolean NOT NULL default false) to the users table.
     *
     * restrictOnDelete(): an organization with existing users CANNOT be deleted.
     * Users must be reassigned or deleted first to prevent orphaned null-org accounts
     * from inadvertently matching the superadmin null-org bypass check.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete()
                ->after('id');

            $table->index('organization_id');

            $table->boolean('is_superadmin')
                ->default(false)
                ->after('organization_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops FK + columns in reverse order. FK must be dropped before column.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id']);
            $table->dropColumn(['is_superadmin', 'organization_id']);
        });
    }
};
