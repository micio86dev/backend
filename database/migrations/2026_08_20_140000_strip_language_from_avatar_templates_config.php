<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove the `language` key from every `avatar_templates.config`
 * (avatar-language-follows-project, D6).
 *
 * The avatar's spoken language follows the PROJECT now, and `ProviderFieldSpecs`
 * no longer offers a language control. `ConfigValidator` is entirely spec-driven,
 * so any key absent from the specs comes back `unknown` — which means a stored
 * `language` would make an existing template fail validation on the operator's
 * NEXT save, and on portability import, for a field the form no longer renders.
 * The row would look fine and be unsavable.
 *
 * THIS IS ONE-WAY. `down()` is a documented no-op: the stripped values are not
 * recoverable, and pretending otherwise by writing a plausible-looking reverse
 * migration would be worse than saying so. If a rollback needs them, restore
 * from a database backup.
 *
 * UNSCOPED BY DESIGN: every organization, every template. The invariant is
 * per-row — no template may carry a language — so a tenant filter here would
 * leave exactly the rows it excluded in the broken state.
 *
 * `??` is the escaped form of Postgres's `?` key-exists operator: a single `?`
 * is consumed by PDO as a parameter placeholder before Postgres ever sees it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE avatar_templates SET config = config - 'language' WHERE config ?? 'language'");
    }

    public function down(): void
    {
        // Deliberately empty. See the class docblock: the values are gone.
    }
};
