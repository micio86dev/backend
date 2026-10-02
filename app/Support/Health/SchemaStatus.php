<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * Readiness verdict behind GET /api/health/ready (stack-schema-drift-guard).
 *
 * Incident 2026-10-02: a long-lived database volume was four migrations behind
 * the deployed code and nothing said so, because liveness (GET /api/health)
 * deliberately never touches the database. This is the check that does, kept
 * separate so liveness stays DB-free.
 *
 * The verdict is a machine-facing constant, never exception text, a migration
 * name or a host. It is deliberately not cached: a cached "ready" would defeat
 * the purpose of asking.
 */
final class SchemaStatus
{
    public const PENDING_MIGRATIONS = 'pending_migrations';

    public const DATABASE_UNAVAILABLE = 'database_unavailable';

    public function __construct(private readonly Migrator $migrator) {}

    public function isReady(): bool
    {
        return $this->reason() === null;
    }

    /**
     * @return self::PENDING_MIGRATIONS|self::DATABASE_UNAVAILABLE|null null when ready
     */
    public function reason(): ?string
    {
        try {
            if (! $this->migrator->repositoryExists()) {
                return self::PENDING_MIGRATIONS;
            }

            $onDisk = array_keys($this->migrator->getMigrationFiles(
                array_merge([database_path('migrations')], $this->migrator->paths()),
            ));
            $ran = $this->migrator->getRepository()->getRan();
        } catch (Throwable) {
            // Connection refused, DNS failure, auth failure, timeout: all the
            // same answer for the caller, and the cause stays out of the body.
            return self::DATABASE_UNAVAILABLE;
        }

        return array_diff($onDisk, $ran) === [] ? null : self::PENDING_MIGRATIONS;
    }
}
