<?php

declare(strict_types=1);

/**
 * App\Support\Health\SchemaStatus (stack-schema-drift-guard): the readiness
 * verdict behind GET /api/health/ready, tested against a faked Migrator so
 * every branch is deterministic and needs no database.
 */

use App\Support\Health\SchemaStatus;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;

/**
 * @param  list<string>  $files  migration files present on disk
 * @param  list<string>|Throwable  $ran  rows in the migrations table, or what reading them throws
 */
function fakeMigrator(array $files, array|Throwable $ran, bool $repositoryExists = true): Migrator
{
    $repository = Mockery::mock(MigrationRepositoryInterface::class);
    if ($ran instanceof Throwable) {
        $repository->shouldReceive('getRan')->andThrow($ran);
    } else {
        $repository->shouldReceive('getRan')->andReturn($ran);
    }

    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('paths')->andReturn([]);
    $migrator->shouldReceive('getMigrationFiles')->andReturn(array_combine($files, $files));
    $migrator->shouldReceive('getRepository')->andReturn($repository);
    $migrator->shouldReceive('repositoryExists')->andReturn($repositoryExists);

    return $migrator;
}

test('is ready when every migration file has been run', function (): void {
    $status = new SchemaStatus(fakeMigrator(['a', 'b'], ['a', 'b']));

    expect($status->reason())->toBeNull()
        ->and($status->isReady())->toBeTrue();
});

test('reports pending_migrations when one migration file has not been run', function (): void {
    $status = new SchemaStatus(fakeMigrator(['a', 'b', 'c'], ['a', 'b']));

    expect($status->reason())->toBe('pending_migrations')
        ->and($status->isReady())->toBeFalse();
});

test('reports pending_migrations when the migrations table has no rows', function (): void {
    $status = new SchemaStatus(fakeMigrator(['a', 'b'], []));

    expect($status->reason())->toBe('pending_migrations');
});

test('reports pending_migrations when the migrations table does not exist yet', function (): void {
    $status = new SchemaStatus(fakeMigrator(['a'], [], repositoryExists: false));

    expect($status->reason())->toBe('pending_migrations');
});

test('a database row without a file on disk does not make the schema pending', function (): void {
    // A rolled-back deploy leaves rows for migrations the older code lacks.
    $status = new SchemaStatus(fakeMigrator(['a'], ['a', 'z']));

    expect($status->reason())->toBeNull();
});

test('reports database_unavailable when reading the migrations table throws, without leaking the message', function (): void {
    $secret = 'SQLSTATE[08006] could not connect to server at db.internal.example:5432 password=hunter2';
    $status = new SchemaStatus(fakeMigrator(['a'], new RuntimeException($secret)));

    $reason = $status->reason();

    expect($reason)->toBe('database_unavailable')
        ->and($reason)->not->toContain('SQLSTATE')
        ->and($reason)->not->toContain('hunter2')
        ->and($status->isReady())->toBeFalse();
});

test('reports database_unavailable when the repository existence check throws', function (): void {
    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('paths')->andReturn([]);
    $migrator->shouldReceive('getMigrationFiles')->andReturn(['a' => 'a']);
    $migrator->shouldReceive('repositoryExists')->andThrow(new RuntimeException('connection refused'));

    expect((new SchemaStatus($migrator))->reason())->toBe('database_unavailable');
});
