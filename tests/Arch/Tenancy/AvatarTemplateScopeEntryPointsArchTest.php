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
 * The allowlists deliberately name files that arrive in later slices; a file
 * that does not exist yet simply has no call site.
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

    expect(array_values(array_diff($hits, $allowed)))->toBe([], 'Unpinned availableToTenant() caller(s): '.implode(', ', $hits));
})->group('arch');

test('platformOnly() is called only from the platform controller and the usage reader', function (): void {
    $hits = atsFilesMatching(atsCode('app'), '/(->|::)platformOnly\(/');

    $allowed = [
        'app/Http/Controllers/Api/PlatformAvatarTemplateController.php',
        'app/Support/AvatarTemplates/GlobalAvatarTemplateUsage.php',
    ];

    expect(array_values(array_diff($hits, $allowed)))->toBe([], 'Unpinned platformOnly() caller(s): '.implode(', ', $hits));
})->group('arch');

test('the platform write context is entered only by the platform controller', function (): void {
    $files = array_filter(
        atsCode('app'),
        fn (string $code): bool => str_contains($code, 'PlatformTemplateContext')
    );

    $hits = atsFilesMatching($files, '/(->|::)run\(/');

    expect(array_values(array_diff($hits, ['app/Http/Controllers/Api/PlatformAvatarTemplateController.php'])))
        ->toBe([], 'PlatformTemplateContext::run() caller(s) outside the platform controller: '.implode(', ', $hits));
})->group('arch');

test('only AvatarTemplate implements AdmitsPlatformRows', function (): void {
    $hits = atsFilesMatching(atsCode('app'), '/implements[^{]*AdmitsPlatformRows/');

    expect($hits)->toBe(['app/Models/AvatarTemplate.php']);
})->group('arch');

test('scope strips under app/Support/AvatarTemplates are limited to the usage reader', function (): void {
    $hits = atsFilesMatching(atsCode('app/Support/AvatarTemplates'), '/(->|::)withoutGlobalScopes?\(/');

    expect(array_values(array_diff($hits, ['app/Support/AvatarTemplates/GlobalAvatarTemplateUsage.php'])))
        ->toBe([], 'Unexpected scope strip(s): '.implode(', ', $hits));
})->group('arch');

test('TenantScoped::creating keeps its unconditional throw and admits only the platform contract', function (): void {
    $code = atsCode('app/Models/Concerns')['app/Models/Concerns/TenantScoped.php'];

    expect(substr_count($code, 'instanceof AdmitsPlatformRows'))->toBe(1)
        ->and($code)->toContain('throw new MissingTenantContextException(static::class)');
})->group('arch');
