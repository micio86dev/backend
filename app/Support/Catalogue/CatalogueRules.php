<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

/**
 * Named structural constants for the catalogue (framework-catalogue-
 * authoring PR3b, H10) — previously bare literals repeated across the
 * publish sweep and the catalogue-write FormRequests, each carrying an
 * inline comment re-explaining the SAME rule instead of naming it once.
 *
 * These are structural, cross-revision rules (CLAUDE.md's binding domain
 * constraints), never a value a superadmin or a config file changes — a
 * literal `3`/`5` reappearing here would be exactly as wrong as reintroducing
 * the bare literals this class replaces.
 */
final class CatalogueRules
{
    /**
     * Every BARS indicator set — role-scoped or role-less (`potential`) —
     * carries EXACTLY this many indicators. Enforced at three layers: the
     * FormRequest (`StoreBarsIndicatorRequest`, refuses a 4th), the DB
     * (`framework_bars_indicators_enforce_pair_cap` trigger, refuses a 4th
     * regardless of writer), and the publish sweep (`PublishRevision`,
     * refuses fewer than 3 — the DB cap alone cannot express "too few").
     */
    public const INDICATORS_PER_PAIR = 3;

    /**
     * The closed set of roles (ICO/FLL/MLL/BUL/SRX) — a 6th role is refused
     * at the FormRequest layer (`StoreRoleRequest`). There is no DB-level
     * "at most 5" constraint: unlike the indicator cap, roles have no
     * natural grouping column to count against, so this rule lives only at
     * the FormRequest and publish-sweep layers.
     */
    public const MAX_ROLES = 5;
}
