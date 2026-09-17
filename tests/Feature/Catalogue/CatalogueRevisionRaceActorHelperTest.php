<?php

declare(strict_types=1);

/**
 * gga review finding (blocking, second pass): "a test that has never been
 * seen to fail is not evidence." Proves the bounded I/O this repo's H3/H4
 * concurrency proofs depend on ACTUALLY bounds — a stuck actor (a leftover
 * lock from a crashed prior run is the named scenario) must surface as a
 * red failure in bounded time, never an unbounded hang. `hang-silently`
 * reproduces exactly that: it connects, then never writes to STDOUT.
 *
 * Short timeouts (well under the real 15s default) so this test itself
 * stays fast — the MECHANISM under test is the same `stream_select()`
 * deadline loop either way.
 */
test('readCatalogueRaceActorLine() fails in bounded time against an actor that never writes', function (): void {
    $actor = startCatalogueRaceActor('hang-silently', '30');

    $start = microtime(true);

    try {
        expect(fn () => readCatalogueRaceActorLine($actor, timeoutSeconds: 0.5))
            ->toThrow(RuntimeException::class, 'produced no output within 0.5s');
    } finally {
        // The actor is genuinely still sleeping — proc_terminate(), not a
        // wait for its own 30s sleep to finish, must be what ends this.
        stopCatalogueRaceActor($actor, timeoutSeconds: 0.5);
    }

    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(5.0);
});

test('stopCatalogueRaceActor() terminates a genuinely stuck actor rather than waiting for it', function (): void {
    $actor = startCatalogueRaceActor('hang-silently', '30');

    $start = microtime(true);
    $exitCode = stopCatalogueRaceActor($actor, timeoutSeconds: 0.5);
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(5.0);
    // proc_terminate() sends SIGTERM; proc_close() then reports a non-zero,
    // signal-driven exit — never the clean `0` a normal `exit(0)` would.
    expect($exitCode)->not->toBe(0);
});
