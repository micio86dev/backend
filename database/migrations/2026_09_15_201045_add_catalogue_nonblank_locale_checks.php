<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The runtime twin's DB-constraint layer for "non-blank locale maps, no edge
 * whitespace" (framework-catalogue-authoring PR3, D3 — mirrors
 * `scripts/ci-guards.sh:2330`'s file-side check). Every catalogue-content
 * translatable field must carry a non-empty, non-whitespace-only `en` value —
 * the FormRequest layer (Phase 11) enforces the SAME rule before a write is
 * even attempted, but a constraint is what refuses a raw `DB::table()` write
 * or a future call site that forgets to run through a FormRequest.
 *
 * These columns are `json`, not `jsonb` (2026_07_17_111652_create_bars_
 * indicators_table.php, 2026_07_17_*_create_framework_roles/competencies_
 * table.php). The `?` (contains-key) operator design.md D3 uses in its
 * pseudocode is `jsonb`-only; `->>'en'` (get-text) works identically on
 * either type and is what this migration uses instead. A key entirely absent
 * makes `->>'en'` return SQL NULL, which `IS NOT NULL` catches the same way
 * an empty string does.
 *
 * `framework_roles.responsibilities` is DELIBERATELY EXCLUDED from this
 * migration, unlike `name` — `FrameworkCatalogSeeder::readLocaleMap()`'s own
 * `$allowBlankEn` parameter exists ONLY for this field: an empty `en`
 * responsibilities value is a legitimate, already-tested "not yet authored"
 * sentinel the seeder writes deliberately (`missing_role_meta` gap tracking,
 * see `tests/Feature/Seeders/GapResolutionTest.php`), not malformed content.
 * A CHECK forbidding it would break that already-shipped, still-correct
 * behaviour for no invariant this change is asked to add — `framework_roles`
 * gains a CHECK on `name` only. `framework_competencies` has no such
 * exception for either of its two fields, and neither does any BARS field.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE framework_roles
             ADD CONSTRAINT framework_roles_name_nonblank_check
             CHECK ((name->>'en') IS NOT NULL AND length(btrim(name->>'en')) > 0)"
        );

        DB::statement(
            "ALTER TABLE framework_competencies
             ADD CONSTRAINT framework_competencies_name_nonblank_check
             CHECK ((name->>'en') IS NOT NULL AND length(btrim(name->>'en')) > 0)"
        );
        DB::statement(
            "ALTER TABLE framework_competencies
             ADD CONSTRAINT framework_competencies_definition_nonblank_check
             CHECK ((definition->>'en') IS NOT NULL AND length(btrim(definition->>'en')) > 0)"
        );

        foreach (['text', 'anchor_5', 'anchor_3', 'anchor_1'] as $column) {
            DB::statement(
                "ALTER TABLE framework_bars_indicators
                 ADD CONSTRAINT framework_bars_indicators_{$column}_nonblank_check
                 CHECK (({$column}->>'en') IS NOT NULL AND length(btrim({$column}->>'en')) > 0)"
            );
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE framework_roles DROP CONSTRAINT framework_roles_name_nonblank_check');
        DB::statement('ALTER TABLE framework_competencies DROP CONSTRAINT framework_competencies_name_nonblank_check');
        DB::statement('ALTER TABLE framework_competencies DROP CONSTRAINT framework_competencies_definition_nonblank_check');

        foreach (['text', 'anchor_5', 'anchor_3', 'anchor_1'] as $column) {
            DB::statement("ALTER TABLE framework_bars_indicators DROP CONSTRAINT framework_bars_indicators_{$column}_nonblank_check");
        }
    }
};
