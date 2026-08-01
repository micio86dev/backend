<?php

declare(strict_types=1);

/**
 * Architecture guard: `audit_logs` rows are never mutated or deleted (C13).
 *
 * An audit trail that can be edited by the system it audits is not evidence.
 *
 * A guard rather than a convention, for a reason this project has already
 * lived through: `ai_requests` carried the same append-only rule in prose and
 * a docblock, the test its docblock advertised was never written, and the rule
 * was then violated for months without anyone noticing.
 */

use App\Models\AuditLog;

test('no business logic updates or deletes an audit row', function (): void {
    $violations = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

        if ($relative === 'app/Models/AuditLog.php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        foreach (['AuditLog::query()->update(', 'AuditLog::query()->delete(', 'AuditLog::destroy(', '->forceDelete()'] as $needle) {
            if (str_contains($source, 'AuditLog') && str_contains($source, $needle)) {
                $violations[] = "{$relative} contains {$needle}";
            }
        }
    }

    expect($violations)->toBe([], sprintf(
        "audit_logs is append-only — evidence you can edit is not evidence:\n  - %s",
        implode("\n  - ", $violations)
    ));
});

test('the model maintains no updated_at', function (): void {
    expect((new AuditLog)->timestamps)->toBeFalse();
});

test('organization_id is not mass-assignable', function (): void {
    // Stamped by TenantScoped. Mass-assigning the tenant key is the hazard the
    // C2 arch test exists for, and an audit row attributed to the wrong
    // organization is worse than no row: it is evidence pointing at the wrong
    // tenant.
    expect((new AuditLog)->getFillable())->not->toContain('organization_id');
});
