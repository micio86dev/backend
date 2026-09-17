<?php

declare(strict_types=1);

/**
 * Launches the standalone race-actor process
 * (`tests/Fixtures/Concurrency/catalogue_revision_race_actor.php`) as a
 * genuinely separate OS process with its OWN Postgres backend session, for
 * the H3/H4 concurrency proofs (framework-catalogue-authoring PR3b).
 *
 * Connection parameters are read from the CURRENT `pgsql` connection config
 * at call time (not hard-coded) so this works correctly under
 * `php artisan test --parallel`, where each worker is pointed at its own
 * database.
 *
 * Bounded I/O throughout (gga review finding, blocking, verified
 * empirically rather than assumed): `stream_set_timeout()` does NOT work on
 * a `proc_open` pipe — it is a plain-fd stream, not a socket or TTY, and
 * `stream_set_timeout()` silently returns `false` and never times out. Both
 * the read (`readCatalogueRaceActorLine()`) and the process teardown
 * (`stopCatalogueRaceActor()`) instead use `stream_select()` against an
 * explicit deadline — the correct, documented way to bound I/O on a
 * `proc_open` pipe. A leftover lock from a crashed prior run (the exact
 * scenario the actor script's own docblock names) now surfaces as a red
 * test in bounded time, never an unbounded hang.
 */

use Illuminate\Support\Facades\Config;

/**
 * A per-line read buffer scoped to ONE actor's STDOUT stream — several
 * `readCatalogueRaceActorLine()` calls share it across an actor's lifetime,
 * since a single `fread()` can return more than one line's worth of bytes
 * at once and the remainder must survive to the NEXT call.
 */
final class CatalogueRaceActorHandle
{
    /**
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     */
    public function __construct(
        public $process,
        public array $pipes,
        public string $readBuffer = '',
    ) {}
}

function startCatalogueRaceActor(string $mode, string $subjectId): CatalogueRaceActorHandle
{
    $connection = Config::get('database.connections.pgsql');

    $command = [
        PHP_BINARY,
        base_path('tests/Fixtures/Concurrency/catalogue_revision_race_actor.php'),
        $mode,
        (string) $connection['host'],
        (string) $connection['port'],
        (string) $connection['database'],
        (string) $connection['username'],
        $subjectId,
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    // Password via the child's ENVIRONMENT, never an argv element (gga
    // review advisory): argv is readable in `ps` by any local user, `$env`
    // is not. Merged with the current process's own environment (`getenv()`
    // with no arguments) rather than replacing it outright — the child
    // still needs PATH and friends to locate its own PHP/Postgres runtime.
    $env = [...getenv(), 'PGPASSWORD' => (string) $connection['password']];

    $process = proc_open($command, $descriptors, $pipes, base_path(), $env);

    if ($process === false) {
        throw new RuntimeException('failed to launch the catalogue revision race actor process');
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    return new CatalogueRaceActorHandle($process, $pipes);
}

/**
 * Read one complete line from the actor's STDOUT — waiting for a real
 * signal the actor process emits, never a fixed sleep, but BOUNDED (gga
 * review finding, blocking): a genuinely stuck actor (a leftover lock from
 * a crashed prior run, or any other reason it never reaches its own next
 * `fwrite`) must surface as a clean, red test failure here — never an
 * unbounded wait that hangs the whole test process, and under CI, the job.
 *
 * `stream_select()` against an explicit deadline, not `stream_set_timeout()`
 * (confirmed, not assumed, not to work on a `proc_open` pipe — see the file
 * docblock). Buffers any extra bytes past the first newline on the handle,
 * since one `fread()` can return more than one line at once.
 */
function readCatalogueRaceActorLine(CatalogueRaceActorHandle $actor, float $timeoutSeconds = 15.0): string
{
    $stream = $actor->pipes[1];
    $deadline = microtime(true) + $timeoutSeconds;

    while (! str_contains($actor->readBuffer, "\n")) {
        $remaining = $deadline - microtime(true);

        if ($remaining <= 0) {
            throw new RuntimeException("the catalogue revision race actor produced no output within {$timeoutSeconds}s — it is stuck (a leftover lock from a crashed prior run?), not merely slow.");
        }

        $read = [$stream];
        $write = null;
        $except = null;
        $seconds = (int) floor($remaining);
        $microseconds = (int) (($remaining - $seconds) * 1_000_000);

        $changed = stream_select($read, $write, $except, $seconds, $microseconds);

        if ($changed === false) {
            throw new RuntimeException('stream_select() failed while waiting for the catalogue revision race actor.');
        }

        if ($changed === 0) {
            continue;
        }

        $chunk = fread($stream, 8192);

        if ($chunk === false || ($chunk === '' && feof($stream))) {
            $stderr = stream_get_contents($actor->pipes[2]);

            throw new RuntimeException("the catalogue revision race actor closed its output unexpectedly. stderr: {$stderr}");
        }

        $actor->readBuffer .= $chunk;
    }

    [$line, $rest] = explode("\n", $actor->readBuffer, 2);
    $actor->readBuffer = $rest;

    return rtrim($line, "\r");
}

function stopCatalogueRaceActor(CatalogueRaceActorHandle $actor, float $timeoutSeconds = 15.0): int
{
    foreach ($actor->pipes as $pipe) {
        fclose($pipe);
    }

    // `proc_close()` blocks until the child exits, with NO timeout of its
    // own (gga review finding, blocking) — fixing the read above without
    // this one just moves the same hang one function down: a stuck actor
    // still never exits, so `proc_close()` still waits forever. Poll
    // `proc_get_status()` against a deadline first; a still-running process
    // past it is forcibly terminated before the final, now-fast
    // `proc_close()`.
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        $status = proc_get_status($actor->process);

        if (! $status['running']) {
            return proc_close($actor->process);
        }

        usleep(10_000);
    }

    proc_terminate($actor->process);

    return proc_close($actor->process);
}
