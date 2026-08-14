<?php

declare(strict_types=1);

namespace App\Support\Demo;

/**
 * The reserved-prefix invariant `beai:demo-seed` / `beai:demo-teardown` are
 * built on (design D1).
 *
 * Demo data shares an organization with real client data, so every row
 * `beai:demo-seed` creates directly carries this prefix on the one natural
 * free-text key its table already has:
 *
 *   - `projects.slug`               → isDemoSlug()
 *   - `participants.candidate_ref`  → isDemoRef()
 *   - `framework_versions.version`  → matches()
 *   - `avatar_templates.name`       → matches()
 *
 * Rows with no natural key of their own (sessions, evaluations, snapshots,
 * proctoring events, competency results, indicator scores) are never marked —
 * they are reached transitively from a marked root through a declared
 * `cascadeOnDelete` FK, which is what makes the reachable set exactly the
 * database's own closure rather than a heuristic.
 *
 * Single source of truth: both DemoWriter (what to create) and
 * DemoTeardownCommand (what to delete) import this class, so "what counts as
 * demo data" cannot drift between the two commands.
 */
final class DemoMarker
{
    public const PREFIX = 'beai-demo-';

    /**
     * Whether a `projects.slug` value was written by `beai:demo-seed`.
     */
    public static function isDemoSlug(?string $slug): bool
    {
        return self::matches($slug);
    }

    /**
     * Whether a `participants.candidate_ref` value was written by
     * `beai:demo-seed`.
     */
    public static function isDemoRef(?string $ref): bool
    {
        return self::matches($ref);
    }

    /**
     * The shared predicate every field-specific check delegates to. A PREFIX
     * check, not a substring check — a client's own value that happens to
     * contain "beai-demo-" mid-string was never written by this command and
     * must not be swept by teardown.
     */
    public static function matches(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::PREFIX);
    }
}
