<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization candidate-page background colour.
 *
 * Same contract as `primary_color` (see add_branding_to_organizations): a
 * nullable `#rrggbb` string guarded by a CHECK, because the value is
 * interpolated into a CSS custom property in the candidate app. Null is a
 * permanent, legal state meaning "use the product default canvas (dark)".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('background_color', 7)->nullable()->after('primary_color');
        });

        DB::statement(
            "ALTER TABLE organizations ADD CONSTRAINT organizations_background_color_hex
             CHECK (background_color IS NULL OR background_color ~* '^#[0-9a-f]{6}$')"
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_background_color_hex'
        );

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('background_color');
        });
    }
};
