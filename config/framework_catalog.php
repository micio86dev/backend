<?php

declare(strict_types=1);

/**
 * Framework catalogue specialist sign-off (C3/C4 Framework Catalog).
 *
 * ── READ THIS BEFORE SETTING THESE ──────────────────────────────────────
 *
 * openspec/specs/framework-catalog/spec.md ("Calibrated Draft Pending
 * Specialist Sign-Off") states plainly: the 132 newly authored indicators
 * (396 anchor texts) and SRX's `responsibilities` are a calibrated draft —
 * extrapolated content, not a reviewed one — and "an assessment specialist
 * MUST review and sign off this content before it scores a real candidate;
 * sign-off is a release gate, not a follow-up." Nothing in code recorded or
 * surfaced that state before this file: a process gate that existed only in
 * prose.
 *
 * `specialist_signed_off` is that record, made machine-readable. FALSE until
 * an assessment specialist has actually reviewed and approved the content —
 * never default it to true to make a status report look calmer than it is.
 *
 * Deliberately NOT `FrameworkVersion.is_locked`. That flag answers a
 * different question — is a per-tenant PINNED SNAPSHOT immutable because a
 * Project now depends on it (app/Models/FrameworkVersion.php) — and it
 * flips the moment ANY organization creates a Project
 * (ProjectController::store), an ordinary user action wholly unrelated to
 * specialist review. Production can show is_locked=false forever for a
 * catalogue that WAS reviewed (nobody has created a project yet), or
 * is_locked=true within minutes of launch for one that was NOT (the first
 * customer just signed up). Neither reading is a trustworthy sign-off
 * proxy. The catalogue itself is also a single GLOBAL artifact
 * (framework_roles / competencies / bars_indicators carry no
 * organization_id and no framework_version_id — see
 * FrameworkCatalogSeeder's own docblock), so sign-off is one environment-
 * wide fact, not one row per tenant the way is_locked is.
 *
 * Ratification is a config change, never a code change — the same doctrine
 * config/retention.php already states for GDPR retention's own sign-off
 * gate. Set via environment variables in the deployment that was actually
 * reviewed, not by editing this file's defaults.
 *
 * This is the READ side of the spec requirement only:
 * `beai:framework-catalog-status` reports this value, honestly, without a
 * database console. The spec additionally requires sign-off to GATE
 * production scoring of the newly authored pairs; wiring an actual block
 * into the scoring path is deliberately out of scope here — this is live,
 * and turning an observability gap into a production-breaking gate in the
 * same change would be a strictly worse outcome than the gap it closes.
 * That gate is a separate, carefully scoped change against the same spec
 * requirement, not a drive-by addition alongside a status command.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Sign-off recorded
    |--------------------------------------------------------------------------
    |
    | FALSE until an assessment specialist has reviewed and approved the
    | catalogue content. `beai:framework-catalog-status` reports "NOT
    | RATIFIED" while this is false — never silently, and never a reassuring
    | default.
    |
    */
    'specialist_signed_off' => (bool) env('FRAMEWORK_CATALOG_SPECIALIST_SIGNED_OFF', false),

    /*
    |--------------------------------------------------------------------------
    | Sign-off metadata
    |--------------------------------------------------------------------------
    |
    | Free text, for the audit trail an operator sees when they ask "who,
    | and when". Never validated or parsed — purely descriptive, surfaced
    | as-is by `beai:framework-catalog-status`. Meaningless (and ignored by
    | that command's RATIFIED branch, which is gated on the boolean above
    | alone) while `specialist_signed_off` is false.
    |
    */
    'specialist_signed_off_by' => env('FRAMEWORK_CATALOG_SPECIALIST_SIGNED_OFF_BY'),

    'specialist_signed_off_at' => env('FRAMEWORK_CATALOG_SPECIALIST_SIGNED_OFF_AT'),

];
