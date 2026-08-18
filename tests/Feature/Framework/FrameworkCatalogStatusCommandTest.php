<?php

declare(strict_types=1);

/**
 * beai:framework-catalog-status — specialist sign-off observability.
 *
 * openspec/specs/framework-catalog/spec.md ("Calibrated Draft Pending
 * Specialist Sign-Off") requires that an assessment specialist's recorded
 * sign-off exist before newly authored content scores a real candidate.
 * Nothing in code recorded or surfaced that state before this command — a
 * process gate that existed only in prose. This is the read side of closing
 * that gap: a machine-readable, honestly-defaulting answer to "is the
 * catalogue in this environment ratified or still a draft", observable
 * without a database console (`railway ssh` + this command).
 *
 * Deliberately NOT `FrameworkVersion.is_locked`. That flag answers a
 * different question — is this per-tenant PINNED SNAPSHOT immutable because
 * a Project now depends on it — and flips the moment ANY organization
 * creates a Project (ProjectController::store), an ordinary user action with
 * no connection to specialist review. Production can carry is_locked=false
 * forever for a catalogue that WAS reviewed (nobody has created a project
 * yet), or is_locked=true within minutes of launch for one that was NOT
 * (the first customer just signed up). Neither reading is trustworthy as a
 * sign-off proxy, so this command does not consult it.
 *
 * Config-driven instead — "Ratification is a config change, never a code
 * change", the same doctrine config/retention.php already states for GDPR
 * retention's own ratification gate. The catalogue itself is a single global
 * artifact (framework_roles / competencies / bars_indicators carry no
 * organization_id and no framework_version_id — see FrameworkCatalogSeeder's
 * own docblock), so sign-off is one environment-wide fact, not one row per
 * tenant.
 *
 * This command REPORTS state; it does not enforce it. Gating production
 * scoring on sign-off is the spec's own requirement too, but wiring an
 * actual block into the scoring path is a separate, deliberately scoped
 * change — not a drive-by addition alongside a read-only status command.
 */
afterEach(function (): void {
    // Every test sets these explicitly, but leaving a non-default value in
    // the config repository would leak into whichever test runs next in the
    // same process — Pest/PHPUnit does not reset config() between tests.
    config()->set('framework_catalog.specialist_signed_off', false);
    config()->set('framework_catalog.specialist_signed_off_by', null);
    config()->set('framework_catalog.specialist_signed_off_at', null);
});

test('reports NOT RATIFIED by default — the honest floor state', function (): void {
    config()->set('framework_catalog.specialist_signed_off', false);
    config()->set('framework_catalog.specialist_signed_off_by', null);
    config()->set('framework_catalog.specialist_signed_off_at', null);

    $this->artisan('beai:framework-catalog-status')
        ->expectsOutputToContain('NOT RATIFIED')
        ->assertExitCode(0);
});

test('never defaults to a reassuring RATIFIED answer when unset', function (): void {
    config()->set('framework_catalog.specialist_signed_off', false);

    // The exact RATIFIED-branch line — not a loose "RATIFIED" substring,
    // which the honest "NOT RATIFIED — ..." line would also match.
    $this->artisan('beai:framework-catalog-status')
        ->doesntExpectOutputToContain('RATIFIED — the framework catalogue has a recorded specialist sign-off.')
        ->assertExitCode(0);
});

test('reports RATIFIED, with who and when, once sign-off is recorded in config', function (): void {
    config()->set('framework_catalog.specialist_signed_off', true);
    config()->set('framework_catalog.specialist_signed_off_by', 'Dr. Jane Doe, I-O Psychologist');
    config()->set('framework_catalog.specialist_signed_off_at', '2026-08-18');

    $this->artisan('beai:framework-catalog-status')
        ->expectsOutputToContain('RATIFIED')
        ->expectsOutputToContain('Dr. Jane Doe, I-O Psychologist')
        ->expectsOutputToContain('2026-08-18')
        ->assertExitCode(0);
});

test('exit code is always SUCCESS — this command reports, it does not gate', function (): void {
    config()->set('framework_catalog.specialist_signed_off', false);

    $this->artisan('beai:framework-catalog-status')->assertExitCode(0);

    config()->set('framework_catalog.specialist_signed_off', true);
    config()->set('framework_catalog.specialist_signed_off_by', 'Dr. Jane Doe');
    config()->set('framework_catalog.specialist_signed_off_at', '2026-08-18');

    $this->artisan('beai:framework-catalog-status')->assertExitCode(0);
});

test('sign-off state is read from config only, never from FrameworkVersion.is_locked', function (): void {
    // Structural guard against exactly the conflation this command exists to
    // avoid: is_locked is a per-tenant pin-immutability flag, not a
    // specialist-review record, and using it here would report "ratified"
    // the moment any tenant creates a Project — a false positive with
    // nothing to do with review. Checks the IMPORT line specifically (not a
    // bare "FrameworkVersion"/"is_locked" substring), because this file's own
    // docblock legitimately NAMES both terms in prose to explain why it does
    // not use them — a substring check would fail on that explanation too.
    $source = file_get_contents(base_path('app/Console/Commands/FrameworkCatalogStatusCommand.php'));

    expect($source)->not->toBeFalse();
    expect($source)->not->toContain('use App\Models\FrameworkVersion;');
    expect($source)->not->toContain('->is_locked');
});
