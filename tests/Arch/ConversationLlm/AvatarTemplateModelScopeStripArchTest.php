<?php

declare(strict_types=1);

/**
 * The scope strips inside `app/Models/AvatarTemplate.php` are pinned to an explicit list (hr-avatar-adapter A12).
 *
 * `AdminTenancySafetyArchTest` guards the HTTP-reachable roots and does not scan `Models/`, so a new
 * `withoutGlobalScope(s)` in this model would pass unreviewed. Each call site below carries its reason; a new one
 * fails here until it is argued for. The behaviour of the `deleted` hook is pinned by
 * `AvatarTemplateDeletedHookScopeTest`; the two scopes by `AvatarTemplateScopeEntryPointsArchTest`.
 */
$avatarTemplateScopeStrips = [
    '$query->withoutGlobalScopes([\'tenant\', self::SCOPE_EXCLUDE_PLATFORM_ROWS]);' => 'scopeAvailableToTenant(): drops the scopes and re-applies the organization filter itself, failing closed without a context.',
    '->withoutGlobalScopes([\'tenant\', self::SCOPE_EXCLUDE_PLATFORM_ROWS])' => 'scopePlatformOnly(): reads platform rows, then filters on organization_id IS NULL.',
];

test('every scope strip in the AvatarTemplate model is a named, justified call site', function () use ($avatarTemplateScopeStrips): void {
    $found = [];

    foreach (file(app_path('Models/AvatarTemplate.php')) ?: [] as $line) {
        if (preg_match('/withoutGlobalScopes?\(/', $line) === 1 && ! str_starts_with(trim($line), '*') && ! str_starts_with(trim($line), '//')) {
            $found[] = trim($line);
        }
    }

    expect($found)->toEqualCanonicalizing(array_keys($avatarTemplateScopeStrips));
});

test('the deleted hook unbinds through the base query builder and strips no scope', function (): void {
    $source = (string) file_get_contents(app_path('Models/AvatarTemplate.php'));

    preg_match('/private static function unbindAfterSoftDelete\(.*?\n    \}\n/s', $source, $body);

    expect($body)->not->toBeEmpty()
        ->and($body[0])->not->toContain('withoutGlobalScope')
        ->and($body[0])->not->toContain('withTrashed')
        ->and($body[0])->toContain('->getConnection()->table(');
});
