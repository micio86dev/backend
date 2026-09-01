<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization branding: a logo and a primary colour.
 *
 * This reopens product decision 9, which CLAUDE.md records as PARKED —
 * "white-label and the FR-006 multi-test portal are underspecified ... removed
 * from C13 scope until a written requirement exists". The requirement now
 * exists: an operator sets a logo and a primary colour in Settings, and both
 * Nuxt apps render in them. CLAUDE.md is updated in the same change, because a
 * binding document that contradicts the code is worse than no document.
 *
 * BOTH COLUMNS ARE NULLABLE, and that is the design rather than caution. An
 * organization that has configured nothing must render in the Quint palette
 * DESIGN.md defines — the product has a brand of its own, and "no logo yet"
 * cannot mean "no logo at all". Null is therefore a real, permanent state, not
 * a migration artefact waiting to be filled.
 *
 * `primary_color` is a 7-character `#rrggbb` string, enforced by a CHECK rather
 * than by validation alone. This value is interpolated into a CSS custom
 * property in two apps: a string that is not a colour becomes a stylesheet that
 * silently does not apply, and one carrying a `;` or a `}` is a CSS injection.
 * The database is the last line that holds for the portability import path and
 * any future writer, not only for requests that go through a FormRequest.
 *
 * `logo_path` is a PATH on the configured disk, never a URL. The disk differs
 * per environment — `local` in development, `s3` in production — so storing a
 * resolved URL would bake one environment's host into the row and break the
 * moment it is restored into another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('logo_path')->nullable()->after('name');
            $table->string('primary_color', 7)->nullable()->after('logo_path');
        });

        // `~*` is Postgres' case-insensitive regex: `#AABBCC` and `#aabbcc` are
        // the same colour and refusing one would be a trap. Three-digit
        // shorthand (`#abc`) is deliberately NOT accepted — one canonical form
        // means the apps never have to expand it, and an operator pasting a
        // shorthand gets a validation error rather than a colour that silently
        // differs from the one they copied.
        DB::statement(
            "ALTER TABLE organizations ADD CONSTRAINT organizations_primary_color_hex
             CHECK (primary_color IS NULL OR primary_color ~* '^#[0-9a-f]{6}$')"
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_primary_color_hex'
        );

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['logo_path', 'primary_color']);
        });
    }
};
