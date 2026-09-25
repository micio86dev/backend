<?php

declare(strict_types=1);

/**
 * Architecture guard: tenancy-safety backstop for the admin read API (C11, task 2.3).
 *
 * D1 explicitly names AdminParticipantReader (a mandatory-argument typed reader)
 * as the MECHANISM — these two Pest arch tests are the acknowledged-weaker
 * backstop, kept because they are cheap and would have caught the exact
 * hazard already present at EvaluationPayloadAssembler.php:46
 * (withoutGlobalScopes() in a job-only-safe pattern).
 *
 * (a) no `withoutGlobalScopes(` anywhere under app/Http/ — that pattern is
 *     reserved for EvaluationPayloadAssembler's queued-job context, never HTTP
 *     (openspec/specs/tenancy/spec.md — "No withoutGlobalScopes() in HTTP controllers").
 * (b) no bare `Participant::` static call under app/Http/Controllers/Api —
 *     the sanctioned path is AdminParticipantReader::read() (D1); a direct
 *     static call bypasses both the org filter and the lifecycle gate.
 * (c) (task 5.3, PR A2) no `withoutGlobalScopes(` anywhere under
 *     app/Services/Admin — AdminEvaluationSerializer/AdminTranscriptSerializer
 *     MUST query Evaluation/CompetencyResult/IndicatorScore/InterviewSession/
 *     Utterance under the ambient TenantContext scope, never bypass it (spec
 *     scenario "Serializer never bypasses the tenant scope").
 *
 * Mirrors the glob+file_get_contents+str_contains shape of
 * tests/Arch/Tenancy/QueuedJobTenantContextArchTest.php.
 *
 * REQ: Cross-Tenant Isolation on Every Admin Read Endpoint
 *      (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md,
 *       openspec/specs/tenancy/spec.md)
 */

/**
 * Recursively list every .php file under $directory.
 *
 * @return list<string>
 */
function phpFilesUnder(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    $files = [];

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

/**
 * Files under app/Http/ permitted to strip a tenant scope, and why.
 *
 * NAMED, because the alternative was invisible. The pattern below used to be
 * plural-only (`withoutGlobalScopes\(`, no `?`), which let the SINGULAR
 * `withoutGlobalScope('tenant')` through unseen — and `SsoExchangeController`
 * has been calling exactly that, in a controller, on a PUBLIC UNAUTHENTICATED
 * seam, while passing a test whose whole purpose is to forbid tenant-scope
 * stripping in HTTP code. The call is defensible on the merits: SSO exchange
 * resolves a project BEFORE any tenant context exists, so there is no scope to
 * respect yet. What was not defensible is that nothing said so.
 *
 * An allowance somebody had to argue for is a different thing from a gap in a
 * regex. Adding a file here is a reviewable act; widening the pattern to catch
 * both forms is what makes it one.
 *
 * gga round 4 finding 1, closed further by step 5 review follow-up item 15:
 * the SINGULAR-form detection below now flags `withoutGlobalScope(...)` for
 * EVERY argument except the literal, allowlisted `SoftDeletingScope::class`
 * — see `$containsUnsafeGlobalScopeStrip`'s own docblock. `App\Http\
 * Controllers\PublicApi\InterviewController::projectIncludingTrashed()`
 * strips exactly that allowlisted scope, on `Project` (still a
 * `TenantModel` throughout that call), never the tenant scope — so it needs
 * no entry here: it was never a tenancy bypass, only ever a soft-delete one
 * (public-api step 5, gga round 3 finding 1). Allowlisting that ONE safe
 * call, rather than narrowing the pattern to the literal tenant-scope name,
 * keeps this list meaning "argued to bypass tenancy", not "happened to call
 * a method whose name contains withoutGlobalScope" — AND catches a
 * variable-passed tenant scope name a name-narrowed pattern would still
 * miss.
 *
 * @var array<string, string>
 */
$tenantScopeStripGuardedRoots = ['Http', 'Services/ConversationLlm', 'Actions/ConversationLlm', 'Listeners'];

/**
 * Matches an actual invocation (`::` or `->`) rather than a bare string, so
 * prose describing the anti-pattern is not flagged as committing it:
 *   - `withoutGlobalScopes(` — the PLURAL, no-args-or-not form. Always a
 *     tenancy bypass in this codebase (nothing narrows it to "every scope
 *     except tenant"), so any occurrence is flagged.
 *   - `withoutGlobalScope(` — the SINGULAR form, flagged for EVERY argument
 *     EXCEPT the literal `SoftDeletingScope::class` (step 5 review
 *     follow-up, item 15). The previous shape here matched only a
 *     LITERAL `'tenant'`/`"tenant"` string argument — which is exactly the
 *     gap gga round 4 finding 1 closed for the STRING form and left open
 *     for every other one: `withoutGlobalScope($someVariable)`, where the
 *     variable happens to hold `'tenant'` at runtime, matched neither the
 *     old pattern nor any allowlist entry, and would have passed this test
 *     silently. Allowlisting the ONE call this codebase has ever argued is
 *     safe (`App\Http\Controllers\PublicApi\InterviewController::
 *     projectIncludingTrashed()`, `withoutGlobalScope(SoftDeletingScope::class)`
 *     — dropping soft-delete scoping, never tenant scoping) and flagging
 *     everything else closes that gap for good: a future caller cannot
 *     introduce an unreviewed tenant-scope strip merely by NOT spelling the
 *     scope name as a literal string.
 */
$tenantScopeStripPluralPattern = '/(->|::)withoutGlobalScopes\(/';

$tenantScopeStripSingularPattern = '/(->|::)withoutGlobalScope\(\s*([^)]*)\)/';

$tenantScopeStripAllowedSingularArgument = 'SoftDeletingScope::class';

/**
 * True when `$source` invokes `withoutGlobalScopes()` (always unsafe here)
 * or `withoutGlobalScope(...)` with any argument OTHER than the literal,
 * allowlisted `SoftDeletingScope::class` (see the pattern docblock above).
 * The singular argument is matched with any amount of leading namespace —
 * `SoftDeletingScope::class`, `\Illuminate\Database\Eloquent\
 * SoftDeletingScope::class`, or an aliased import — all end in the same
 * literal suffix.
 */
$containsUnsafeGlobalScopeStrip = function (string $source) use (
    $tenantScopeStripPluralPattern,
    $tenantScopeStripSingularPattern,
    $tenantScopeStripAllowedSingularArgument,
): bool {
    if (preg_match($tenantScopeStripPluralPattern, $source) === 1) {
        return true;
    }

    if (preg_match_all($tenantScopeStripSingularPattern, $source, $matches) < 1) {
        return false;
    }

    foreach ($matches[2] as $argument) {
        $argument = trim($argument);

        if ($argument === $tenantScopeStripAllowedSingularArgument) {
            continue;
        }

        if (str_ends_with($argument, '\\'.$tenantScopeStripAllowedSingularArgument)) {
            continue;
        }

        return true;
    }

    return false;
};

test('containsUnsafeGlobalScopeStrip flags every singular withoutGlobalScope() argument except the allowlisted SoftDeletingScope::class', function () use ($containsUnsafeGlobalScopeStrip): void {
    // The gap this broadened detection closes (gga round 4 finding 1,
    // extended by step 5 review follow-up item 15): a VARIABLE-passed
    // tenant scope name, which never matched the old literal-`'tenant'`-only
    // pattern.
    expect($containsUnsafeGlobalScopeStrip('$query->withoutGlobalScope($someVariable);'))->toBeTrue();

    // Still flagged: the literal string form the old pattern already caught.
    expect($containsUnsafeGlobalScopeStrip("Project::withoutGlobalScope('tenant')->find(1);"))->toBeTrue();

    // Still flagged unconditionally: the plural, no-narrowing form.
    expect($containsUnsafeGlobalScopeStrip('Project::withoutGlobalScopes()->find(1);'))->toBeTrue();

    // The one allowlisted safe case: NOT flagged, short-name or fully
    // qualified.
    expect($containsUnsafeGlobalScopeStrip('$relation->getQuery()->withoutGlobalScope(SoftDeletingScope::class);'))->toBeFalse();
    expect($containsUnsafeGlobalScopeStrip('$relation->getQuery()->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class);'))->toBeFalse();

    // Prose with no `->`/`::` invocation is never a call.
    expect($containsUnsafeGlobalScopeStrip('See withoutGlobalScope(\'tenant\') for details.'))->toBeFalse();
})->group('arch');

$tenantScopeStripAllowlist = [
    'Http/Controllers/Sso/SsoExchangeController.php' => 'SSO exchange resolves the project before any '
        .'tenant context exists — the scope cannot be respected because it has not been '
        .'established yet. Strips ONLY the named tenant scope, never the plural no-args form, so '
        .'SoftDeletingScope survives and a deleted project stays unreachable.',
    'Http/Controllers/Api/LlmCredentialController.php' => 'destroy() counts the AvatarTemplates bound to '
        .'a credential before deleting it, and credentials are PLATFORM rows (RATIFIED '
        .'2026-09-14) while templates are still tenant-scoped. The foreign key '
        .'avatar_templates.llm_credential_id is ON DELETE RESTRICT across EVERY tenant, so a '
        .'scoped count would report "nothing bound" for a credential another organization still '
        .'uses, allow the delete, and turn an integrity error into an unhandled 500 — the strip '
        .'makes the guard agree with the constraint it is guarding. Endpoint is superadmin-only '
        .'and the query reads ONLY names, for a refusal message naming what blocks the delete.',
    'Actions/ConversationLlm/ResyncCredentialBindings.php' => 'Re-pushes EVERY template bound to '
        .'a rotated credential, across every provider and every tenant. Credentials are platform '
        .'rows (RATIFIED 2026-09-14) — one key serves every tenant — so narrowing this sweep by '
        .'organization would push the new key for exactly one organization and leave it silently '
        .'stale for the rest, with the rows still reading `synced`. Reached from '
        .'ResyncCredentialBindingsJob, a QUEUED job dispatched by LlmCredentialController::'
        .'update() — it is guarded here anyway rather than treated as job-only, because the '
        .'strip is what makes it cross-tenant and that deserves a named argument wherever it '
        .'runs. Superadmin-only by the time it is dispatched, and it '
        .'writes only llm_sync_status bookkeeping on rows it resolved by credential id. '
        .'(Moved here from HeygenLlmRegistrar, which held the same sweep behind a '
        .'provider = heygen filter that stranded every Tavus template.)',
    'Listeners/SendProgressWebhook.php' => 'resolveOrganizationId() resolves the Project a '
        .'ParticipantCreated/CompetencySessionEnded event names, and neither event carries an '
        .'organization id directly. The listener runs synchronously in the SSO exchange and '
        .'/end request paths, which — like SsoExchangeController above — establish no reliable '
        .'ambient tenant context of their own. Uses withoutGlobalScope(\'tenant\') ONLY, the '
        .'same singular form SsoExchangeController documents and this file was fixed to match '
        .'(pre-commit gate round 6, finding 1) — never the plural no-args form — so '
        .'SoftDeletingScope survives and a soft-deleted project still 404s here.',
    'Listeners/SendEvaluationWebhook.php' => 'Both strips resolve an Evaluation by an id an '
        .'event carries, with no ambient tenant context: this listener runs synchronously off '
        .'ScoreEvaluationJob, not inside a request. handleCompleted() then re-derives '
        .'Participant scoped by the evaluation\'s own organization_id before recording anything '
        .'(pre-commit gate round 5, finding 1); handleFailed() scopes Participant by the '
        .'org id ScoreEvaluationJob itself already derived. Both Evaluation reads use '
        .'withoutGlobalScope(\'tenant\') ONLY, never the plural form, extended to this file '
        .'when the guarded roots grew to include Listeners (pre-commit gate round 6).',
];

/**
 * Extended beyond `app/Http/` on 2026-09-14.
 *
 * `HeygenLlmRegistrar::rotateSecret()` strips the tenant scope and is reached
 * synchronously from `LlmCredentialController::update()` — an HTTP request. It
 * sat outside this walk, so it was HTTP-context code stripping a tenant scope
 * that nobody had to argue for: exactly the shape the allowlist docblock above
 * calls "a gap in the regex" rather than "an allowance somebody argued for".
 */
test('no tenant-scope strip exists in a guarded root outside the named allowlist', function () use ($tenantScopeStripAllowlist, $tenantScopeStripGuardedRoots, $containsUnsafeGlobalScopeStrip): void {
    $violations = [];

    foreach ($tenantScopeStripGuardedRoots as $root) {
        foreach (phpFilesUnder(base_path('app/'.$root)) as $file) {
            $source = file_get_contents($file);

            if ($source === false || ! $containsUnsafeGlobalScopeStrip($source)) {
                continue;
            }

            $relative = str_replace(base_path('app').'/', '', $file);

            if (! array_key_exists($relative, $tenantScopeStripAllowlist)) {
                $violations[] = $relative;
            }
        }
    }

    expect($violations)->toBe([], 'Stripping a tenant scope in a guarded root requires a named entry '
        .'in $tenantScopeStripAllowlist with the reason it is safe. Unlisted: '
        .implode(', ', $violations));
})->group('arch');

test('every allowlisted tenant-scope strip still exists, so the list cannot rot', function () use ($tenantScopeStripAllowlist, $containsUnsafeGlobalScopeStrip): void {
    // An allowlist nobody prunes becomes a licence for the next file that
    // happens to take the same path. If the call is gone, the entry goes too.
    foreach (array_keys($tenantScopeStripAllowlist) as $relative) {
        $file = base_path('app').'/'.$relative;

        expect(file_exists($file))->toBeTrue("Allowlisted file no longer exists: {$relative}");

        $source = file_get_contents($file);

        expect($source !== false && $containsUnsafeGlobalScopeStrip($source))
            ->toBeTrue("Allowlisted file no longer strips a tenant scope — remove it: {$relative}");
    }
})->group('arch');

test('no tenant-scope strip exists anywhere under app/Services/Admin/ (task 5.3)', function () use ($containsUnsafeGlobalScopeStrip): void {
    $violations = [];

    // Both forms here too — the singular slipped past this guard for the same
    // reason it slipped past the one above. No allowlist: nothing under
    // app/Services/Admin has ever needed one.
    foreach (phpFilesUnder(base_path('app/Services/Admin')) as $file) {
        $source = file_get_contents($file);

        if ($source !== false && $containsUnsafeGlobalScopeStrip($source)) {
            $violations[] = $file;
        }
    }

    expect($violations)->toBe([], 'withoutGlobalScopes() is reserved for the queued-job context '
        .'(EvaluationPayloadAssembler) and MUST NOT appear in the admin serializers under '
        .'app/Services/Admin. Violations: '.implode(', ', $violations));
})->group('arch');

test('no bare Participant:: static call exists under app/Http/Controllers/Api', function (): void {
    $violations = [];

    foreach (phpFilesUnder(base_path('app/Http/Controllers/Api')) as $file) {
        $source = file_get_contents($file);

        if ($source !== false && str_contains($source, 'Participant::')) {
            $violations[] = $file;
        }
    }

    expect($violations)->toBe([], 'A direct Participant:: static call bypasses AdminParticipantReader '
        .'(D1) — both the org filter and the lifecycle gate. Use AdminParticipantReader::read() instead. '
        .'Violations: '.implode(', ', $violations));
})->group('arch');
