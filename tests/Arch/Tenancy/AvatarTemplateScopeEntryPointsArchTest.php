<?php

declare(strict_types=1);

/**
 * Pins the call sites that can reach a platform (NULL-organization) avatar
 * template (global-avatar-templates, design D2/D3).
 *
 * The two named scopes strip the tenant scope on purpose, and
 * AdminTenancySafetyArchTest cannot see them (they contain no
 * `withoutGlobalScope(` at the call site). This inventory is what keeps the
 * escape hatch reviewable: a new caller turns this red until someone decides,
 * in review, that it belongs.
 *
 * The allowlists name only files that exist and call the scope TODAY, and the
 * assertions are exact: a listed file that stops calling it fails just as an
 * unlisted one that starts does. Each later slice adds its own entries with
 * the file that needs them, never in advance.
 */

/**
 * PHP source with comments removed, so prose mentioning a scope never counts.
 *
 * @return array<string, string> repository-relative path => code
 */
function atsCode(string $directory): array
{
    $out = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $code = '';
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        $out[ltrim(str_replace(base_path(), '', $file->getPathname()), '/')] = $code;
    }

    return $out;
}

/**
 * @param  array<string, string>  $files
 * @return list<string>
 */
function atsFilesMatching(array $files, string $pattern): array
{
    $hits = array_keys(array_filter($files, fn (string $code): bool => preg_match($pattern, $code) === 1));
    sort($hits);

    return $hits;
}

test('availableToTenant() is called only from the pinned runtime and picker sites', function (): void {
    $hits = atsFilesMatching(atsCode('app'), '/(->|::)availableToTenant\(/');

    $allowed = [
        'app/Actions/Interview/BuildInterviewSessionResponse.php',
        'app/Http/Controllers/AvatarTemplateController.php',
        'app/Http/Controllers/Candidate/InterviewController.php',
        'app/Models/Project.php',
        'app/Support/AvatarTemplates/ActiveTemplateResolver.php',
    ];

    expect($hits)->toBe($allowed, 'availableToTenant() callers changed: '.implode(', ', $hits));
})->group('arch');

test('platformOnly() is called only by the platform controller', function (): void {
    $hits = atsFilesMatching(atsCode('app'), '/(->|::)platformOnly\(/');

    expect($hits)->toBe(['app/Http/Controllers/Api/PlatformAvatarTemplateController.php'], 'platformOnly() callers changed: '.implode(', ', $hits));
})->group('arch');

test('the platform write context is entered only by the platform controller', function (): void {
    $files = array_filter(
        atsCode('app'),
        fn (string $code): bool => str_contains($code, 'PlatformTemplateContext')
    );

    $hits = atsFilesMatching($files, '/(->|::)run\(/');

    // The class itself defines run() and does not call it.
    expect(array_values(array_diff($hits, ['app/Support/AvatarTemplates/PlatformTemplateContext.php'])))
        ->toBe(['app/Http/Controllers/Api/PlatformAvatarTemplateController.php'], 'PlatformTemplateContext::run() callers changed: '.implode(', ', $hits));
})->group('arch');

test('only AvatarTemplate implements AdmitsPlatformRows', function (): void {
    $hits = atsFilesMatching(atsCode('app'), '/implements[^{]*AdmitsPlatformRows/');

    expect($hits)->toBe(['app/Models/AvatarTemplate.php']);
})->group('arch');

test('the only scope strip under app/Support/AvatarTemplates is the cross-organization usage reader', function (): void {
    $hits = atsFilesMatching(atsCode('app/Support/AvatarTemplates'), '/(->|::)withoutGlobalScopes?\(/');

    expect($hits)->toBe(['app/Support/AvatarTemplates/GlobalAvatarTemplateUsage.php'], 'Scope strip callers changed: '.implode(', ', $hits));
})->group('arch');

test('quiet writes on an avatar template never carry organization_id', function (): void {
    // The write guards are model events, and `saveQuietly()` skips events by
    // design: provider bookkeeping (sync status, configuration ids) must be able
    // to stamp a platform row from any context. That leaves ONE thing a quiet
    // write must never do, change which organization a template belongs to.
    $quiet = array_filter(atsCode('app'), fn (string $code): bool => str_contains($code, 'AvatarTemplate')
        && preg_match('/(saveQuietly|updateQuietly|withoutEvents)\(/', $code) === 1);

    expect(array_keys($quiet))->toContain('app/Actions/ConversationLlm/ResyncTemplateBinding.php');

    $offenders = atsFilesMatching($quiet, '/(forceFill|fill|updateQuietly)\(\s*\[[^\]]*[\'"]organization_id[\'"]/');

    expect($offenders)->toBe([], 'organization_id written quietly in: '.implode(', ', $offenders));
})->group('arch');

test('TenantScoped::creating keeps its unconditional throw and admits only the platform contract', function (): void {
    $code = atsCode('app/Models/Concerns')['app/Models/Concerns/TenantScoped.php'];

    expect(substr_count($code, 'instanceof AdmitsPlatformRows'))->toBe(1)
        ->and($code)->toContain('throw new MissingTenantContextException(static::class)');
})->group('arch');
