<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Real concurrency for the reusable link tests: separate OS processes, each with
 * its own Postgres backend, racing for the same row.
 *
 * Why it needs a database of its own. Every test in this suite runs inside one
 * wrapped transaction that is rolled back at the end, which is exactly what makes
 * two processes unable to see each other's rows. Concurrency needs COMMITTED
 * data, and committed data has to go somewhere that cannot poison the next test.
 * So `open()` creates a throwaway database next to the test one, migrates it,
 * and points the default connection at it for the rest of the test: every row the
 * test then creates through the ordinary fixtures is committed at once and is
 * visible to the actor processes. `close()` points the connection back and drops
 * the database, with FORCE, so nothing survives, whatever the test did.
 *
 * Connection parameters come from the CURRENT connection config at call time,
 * never hard-coded, so this works under `php artisan test --parallel`, where each
 * worker has a database of its own to derive the throwaway name from.
 *
 * Synchronisation is by OBSERVATION, never by a fixed sleep: a test holds a row
 * lock on a connection of its own, starts the actors, and waits until Postgres
 * itself reports that they are blocked on a lock (`pg_stat_activity`). Only then
 * does it release the row, so the interleaving is the one the test names and not
 * one the scheduler happened to produce. Every wait is bounded and fails loudly.
 */
final class ReusableLinkConcurrency
{
    /**
     * @var array{connection: string, previous: string, name: string, admin: PDO, params: array<string, mixed>}|null
     */
    private static ?array $state = null;

    /**
     * @var list<PDO>
     */
    private static array $holders = [];

    /**
     * @var list<array{process: resource, out: string, err: string}>
     */
    private static array $actors = [];

    /**
     * Create and migrate the throwaway database and switch the default
     * connection to it.
     */
    public static function open(): void
    {
        $connection = (string) config('database.default');
        /** @var array<string, mixed> $params */
        $params = config("database.connections.{$connection}");
        $previous = (string) $params['database'];
        $name = $previous.'_conc_'.bin2hex(random_bytes(4));

        $admin = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=postgres', $params['host'], $params['port']),
            (string) $params['username'],
            (string) $params['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $admin->exec('CREATE DATABASE "'.$name.'"');

        self::$state = ['connection' => $connection, 'previous' => $previous, 'name' => $name, 'admin' => $admin, 'params' => $params];

        $out = tempnam(sys_get_temp_dir(), 'conc-migrate-');
        $process = proc_open(
            [PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'],
            [0 => ['pipe', 'r'], 1 => ['file', (string) $out, 'w'], 2 => ['file', (string) $out, 'a']],
            $pipes,
            base_path(),
            self::environment(),
        );

        if ($process === false) {
            throw new RuntimeException('could not start the migration of the concurrency database');
        }

        fclose($pipes[0]);
        $exit = proc_close($process);
        $log = (string) file_get_contents((string) $out);
        @unlink((string) $out);

        if ($exit !== 0) {
            self::close();

            throw new RuntimeException("migrating the concurrency database failed ({$exit}):\n".substr($log, -2000));
        }

        config(["database.connections.{$connection}.database" => $name]);
        DB::purge($connection);
    }

    /**
     * Point the default connection back, stop every actor, release every lock
     * and drop the throwaway database. Safe to call twice, and when `open()`
     * never ran.
     */
    public static function close(): void
    {
        foreach (self::$actors as $actor) {
            $status = proc_get_status($actor['process']);
            if ($status['running']) {
                proc_terminate($actor['process'], 9);
            }
            proc_close($actor['process']);
            @unlink($actor['out']);
            @unlink($actor['err']);
        }
        self::$actors = [];

        foreach (self::$holders as $holder) {
            try {
                if ($holder->inTransaction()) {
                    $holder->rollBack();
                }
            } catch (\Throwable) {
                // The connection is going away with its database either way.
            }
        }
        self::$holders = [];

        if (self::$state === null) {
            return;
        }

        $state = self::$state;
        self::$state = null;

        config(["database.connections.{$state['connection']}.database" => $state['previous']]);
        DB::purge($state['connection']);

        $state['admin']->exec('DROP DATABASE IF EXISTS "'.$state['name'].'" WITH (FORCE)');
    }

    /**
     * Open a connection of its own to the throwaway database, begin a
     * transaction and take the row lock of one link: what an in-flight
     * redemption or disable holds. Release it with `commit()`.
     */
    public static function holdLinkLock(int $linkId): PDO
    {
        $holder = self::connect();
        $holder->beginTransaction();
        $holder->prepare('SELECT id FROM reusable_interview_links WHERE id = ? FOR UPDATE')->execute([$linkId]);

        return self::$holders[] = $holder;
    }

    /**
     * One connection that takes the row lock of SEVERAL links in one
     * transaction: what a burst of in-flight redemptions of different links
     * holds. Every request aimed at any of those links queues behind it, and
     * `commit()` releases them all together, so the requests then race each
     * other for whatever the locks were not protecting (the `(project_id, email)`
     * unique index).
     *
     * @param  list<int>  $linkIds
     */
    public static function holdLinkLocks(array $linkIds): PDO
    {
        $holder = self::connect();
        $holder->beginTransaction();

        foreach ($linkIds as $linkId) {
            $holder->prepare('SELECT id FROM reusable_interview_links WHERE id = ? FOR UPDATE')->execute([$linkId]);
        }

        return self::$holders[] = $holder;
    }

    /**
     * The same, but the holder is a Disable that has written its change and not
     * yet committed it.
     */
    public static function holdUncommittedDisable(int $linkId): PDO
    {
        $holder = self::connect();
        $holder->beginTransaction();
        $holder->prepare('UPDATE reusable_interview_links SET disabled_at = clock_timestamp(), updated_at = clock_timestamp() WHERE id = ?')->execute([$linkId]);

        return self::$holders[] = $holder;
    }

    /**
     * Wait until Postgres reports at least `$count` backends of the throwaway
     * database blocked on a lock. Bounded: a request that never blocks (a lock
     * that is no longer taken) or a database that is stuck is a failure with a
     * reason, not a hang.
     */
    public static function waitForBlocked(int $count, float $timeoutSeconds = 30.0): void
    {
        $state = self::state();
        $deadline = microtime(true) + $timeoutSeconds;
        $blocked = 0;

        while (microtime(true) < $deadline) {
            $statement = $state['admin']->prepare("SELECT count(*) FROM pg_stat_activity WHERE datname = ? AND wait_event_type = 'Lock'");
            $statement->execute([$state['name']]);
            $blocked = (int) $statement->fetchColumn();

            if ($blocked >= $count) {
                return;
            }

            usleep(20_000);
        }

        throw new RuntimeException("expected {$count} backend(s) blocked on a row lock, saw {$blocked} after {$timeoutSeconds}s: the requests did not queue behind the lock.");
    }

    /**
     * Start one real HTTP request in a process of its own and return at once.
     *
     * @param  array{method: string, uri: string, ip?: string, headers?: array<string, string>, body?: array<string, mixed>}  $spec
     */
    public static function startRequest(array $spec): int
    {
        $out = (string) tempnam(sys_get_temp_dir(), 'conc-out-');
        $err = (string) tempnam(sys_get_temp_dir(), 'conc-err-');

        $process = proc_open(
            [PHP_BINARY, base_path('tests/Fixtures/Concurrency/reusable_link_http_actor.php'), json_encode($spec, JSON_THROW_ON_ERROR)],
            [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
            $pipes,
            base_path(),
            self::environment(),
        );

        if ($process === false) {
            throw new RuntimeException('could not start an actor process');
        }

        fclose($pipes[0]);
        self::$actors[] = ['process' => $process, 'out' => $out, 'err' => $err];

        return array_key_last(self::$actors);
    }

    /**
     * Whether an actor is still running.
     */
    public static function isRunning(int $actor): bool
    {
        return proc_get_status(self::$actors[$actor]['process'])['running'];
    }

    /**
     * Wait for an actor to finish and return its response. Bounded.
     *
     * @return array{status: int, body: string}
     */
    public static function result(int $actor, float $timeoutSeconds = 60.0): array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (self::isRunning($actor)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("actor {$actor} did not finish within {$timeoutSeconds}s: it is stuck, not merely slow.");
            }

            usleep(10_000);
        }

        $out = trim((string) file_get_contents(self::$actors[$actor]['out']));
        $err = trim((string) file_get_contents(self::$actors[$actor]['err']));
        $decoded = json_decode($out, true);

        if (! is_array($decoded) || ! isset($decoded['status'])) {
            throw new RuntimeException("actor {$actor} produced no response.\nstdout: {$out}\nstderr: ".substr($err, -1500));
        }

        return ['status' => (int) $decoded['status'], 'body' => (string) $decoded['body']];
    }

    /**
     * A new connection to the throwaway database.
     */
    private static function connect(): PDO
    {
        $state = self::state();

        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $state['params']['host'], $state['params']['port'], $state['name']),
            (string) $state['params']['username'],
            (string) $state['params']['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    /**
     * The environment an actor (or the migration) runs with: the current one,
     * pointed at the throwaway database, with an in-process cache and a sync
     * queue.
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $state = self::state();

        return [
            ...getenv(),
            'DB_CONNECTION' => $state['connection'],
            'DB_HOST' => (string) $state['params']['host'],
            'DB_PORT' => (string) $state['params']['port'],
            'DB_DATABASE' => $state['name'],
            'DB_USERNAME' => (string) $state['params']['username'],
            'DB_PASSWORD' => (string) $state['params']['password'],
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ];
    }

    /**
     * @return array{connection: string, previous: string, name: string, admin: PDO, params: array<string, mixed>}
     */
    private static function state(): array
    {
        return self::$state ?? throw new RuntimeException('ReusableLinkConcurrency::open() has not run.');
    }
}
