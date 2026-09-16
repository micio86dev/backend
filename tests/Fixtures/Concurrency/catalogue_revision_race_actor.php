<?php

declare(strict_types=1);

/**
 * Standalone (NOT autoloaded, NOT part of the app) actor process for the H3/H4
 * concurrency proofs (framework-catalogue-authoring PR3b).
 *
 * Launched via `proc_open` from `tests/Helpers/CatalogueRevisionRaceActor.php`
 * as a genuinely separate OS process with its OWN Postgres backend session —
 * the only way to hold a real lock open WHILE the main test process's blocking
 * call (`OpenDraftRevision::open()` / `PublishRevision::publish()`) is itself
 * synchronously waiting on it. A single PHP process is single-threaded and
 * cannot do both sides of a lock-contention race at once.
 *
 * Contention is confirmed by POLLING `pg_blocking_pids()` for our own
 * backend pid appearing in another backend's blocker list — a bounded
 * condition wait, never a fixed `sleep()` guess.
 * Each mode prints exactly two signal lines to STDOUT, flushed immediately,
 * so the parent can synchronize on real events. The password is read from
 * the `PGPASSWORD` environment variable, never an argv element (gga review
 * advisory): argv is readable in `ps` by any local user on the same host.
 *
 *   insert-competing-draft <host> <port> <dbname> <user> <parentId>
 *     READY:<newDraftId>   — the competing row is inserted, uncommitted
 *     DONE:<0|1>           — committed; 1 iff a waiting inserter was observed
 *
 *   lock-revision-row <host> <port> <dbname> <user> <revisionId>
 *     LOCKED               — `SELECT ... FOR UPDATE` acquired on the row
 *     DONE:<0|1>           — flipped to published and committed; 1 iff a
 *                            waiting locker was observed
 *
 *   create-committed-row <host> <port> <dbname> <user> <parentId|0>
 *     CREATED:<newId>      — a bare draft row, committed, no polling —
 *                            fixture setup OUTSIDE the test's own wrapped
 *                            transaction so a later race-mode invocation can
 *                            see it. `0` means no parent (nullable column).
 *
 *   delete-committed-row <host> <port> <dbname> <user> <id>
 *     DELETED              — cleanup, same reasoning: a row created by a
 *                            separate, already-committed connection is
 *                            NEVER undone by the test's own transaction
 *                            rollback, so it must be deleted for real here.
 *
 *   hang-silently <host> <port> <dbname> <user> <seconds>
 *     (nothing)            — connects, then sleeps for <seconds> without
 *                            EVER writing to STDOUT. Exists ONLY to prove
 *                            `readCatalogueRaceActorLine()`'s own bound
 *                            actually fires (`CatalogueRevisionRaceActorTest`)
 *                            — this is the "leftover lock from a crashed
 *                            prior run" scenario named throughout this file,
 *                            reproduced on purpose rather than assumed fixed.
 */

/**
 * @param  list<string>  $argv
 * @return list<string> exactly [mode, host, port, dbname, user, subjectId]
 */
function catalogueRaceActorArgs(array $argv): array
{
    return array_slice($argv, 1);
}

[$mode, $host, $port, $dbname, $user, $subjectId] = catalogueRaceActorArgs($argv);

$pass = (string) getenv('PGPASSWORD');
$dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

/**
 * Poll for a real backend genuinely blocked waiting on THIS session's held
 * lock, using Postgres's own `pg_blocking_pids()` — the canonical way to ask
 * "who is waiting on me", correct for both a row-lock wait (`SELECT ... FOR
 * UPDATE`) and a unique-index insert wait, without needing to know which
 * internal `pg_locks.locktype` either one happens to surface as. Bounded to
 * 5s, polled every 10ms: a condition wait, never a guessed sleep duration.
 *
 * Empirically confirmed (not assumed) that `pg_blocking_pids()` MUST be
 * evaluated from a THIRD, otherwise-uninvolved connection: run from the
 * lock-holding session's OWN connection, it consistently returns an empty
 * blocker list for a backend it is demonstrably blocking (verified directly
 * against `pg_stat_activity` from an independent session while reproducing
 * this manually) — some self-referential exclusion internal to how Postgres
 * resolves the wait graph for the CALLING backend's own point of view. A
 * dedicated polling connection, separate from `$heldLockPdo`, is therefore
 * not an optimization; it is required for correct detection at all.
 */
function catalogueRaceActorObserveContention(PDO $heldLockPdo, PDO $pollingPdo): bool
{
    $ownPid = (int) $heldLockPdo->query('SELECT pg_backend_pid()')->fetchColumn();
    $deadline = microtime(true) + 5.0;

    while (microtime(true) < $deadline) {
        $count = (int) $pollingPdo->query(
            "SELECT count(*) FROM pg_stat_activity WHERE {$ownPid} = ANY(pg_blocking_pids(pid))"
        )->fetchColumn();

        if ($count > 0) {
            return true;
        }

        usleep(10_000);
    }

    return false;
}

if ($mode === 'insert-competing-draft') {
    $parentId = (int) $subjectId;

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO framework_catalog_revisions (state, parent_revision_id, created_at, updated_at)
         VALUES ('draft', :parent, now(), now()) RETURNING id"
    );
    $stmt->execute([':parent' => $parentId]);
    $newId = (int) $stmt->fetchColumn();

    fwrite(STDOUT, "READY:{$newId}\n");
    fflush(STDOUT);

    // The main process's competing `FrameworkCatalogRevision::create()` call
    // (inside `OpenDraftRevision::open()`) blocks on this uncommitted row's
    // transaction id until it resolves.
    $contended = catalogueRaceActorObserveContention($pdo, new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));

    $pdo->commit();

    fwrite(STDOUT, 'DONE:'.($contended ? '1' : '0')."\n");
    fflush(STDOUT);

    exit(0);
}

if ($mode === 'lock-revision-row') {
    $revisionId = (int) $subjectId;

    $pdo->beginTransaction();

    $pdo->prepare('SELECT id FROM framework_catalog_revisions WHERE id = :id FOR UPDATE')
        ->execute([':id' => $revisionId]);

    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);

    // The main process's competing `lockForUpdate()` (inside
    // `PublishRevision::publish()`) blocks waiting on this row's lock.
    $contended = catalogueRaceActorObserveContention($pdo, new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));

    $pdo->prepare("UPDATE framework_catalog_revisions SET state = 'published', published_at = now() WHERE id = :id")
        ->execute([':id' => $revisionId]);
    $pdo->commit();

    fwrite(STDOUT, 'DONE:'.($contended ? '1' : '0')."\n");
    fflush(STDOUT);

    exit(0);
}

if ($mode === 'create-committed-row') {
    $parentId = (int) $subjectId;

    $stmt = $pdo->prepare(
        "INSERT INTO framework_catalog_revisions (state, parent_revision_id, created_at, updated_at)
         VALUES ('draft', :parent, now(), now()) RETURNING id"
    );
    $stmt->execute([':parent' => $parentId === 0 ? null : $parentId]);
    $newId = (int) $stmt->fetchColumn();

    fwrite(STDOUT, "CREATED:{$newId}\n");
    fflush(STDOUT);

    exit(0);
}

if ($mode === 'delete-committed-row') {
    $pdo->prepare('DELETE FROM framework_catalog_revisions WHERE id = :id')
        ->execute([':id' => (int) $subjectId]);

    fwrite(STDOUT, "DELETED\n");
    fflush(STDOUT);

    exit(0);
}

if ($mode === 'hang-silently') {
    sleep(max(1, (int) $subjectId));
    exit(0);
}

fwrite(STDERR, "unknown mode: {$mode}\n");
exit(1);
